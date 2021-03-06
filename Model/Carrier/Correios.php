<?php
/**
 * Do not edit this file if you want to update this module for future new versions.
 *
 * @author    Weverson Cachinsky <weversoncachinsky@gmail.com>
 */
namespace Weverson\Correios\Model\Carrier;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Carrier\AbstractCarrierOnline;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Tracking\Result\Error;
use Weverson\Correios\Model\Api\PriceEstimateDate;
use Weverson\Correios\Model\Source\MethodList;

class Correios extends AbstractCarrierOnline implements CarrierInterface
{
    protected $_code = 'correios';
    /**
     * @var \Magento\Framework\HTTP\ZendClientFactory
     */
    private $httpClientFactory;

    /**
     * Rate result data
     *
     * @var Result|null
     */
    protected $_result;

    private $_errors = [];
    /**
     * @var PriceEstimateDate
     */
    private $priceEstimateDate;
    /**
     * @var MethodList
     */
    private $methodList;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Xml\Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\HTTP\ZendClientFactory $httpClientFactory,
        PriceEstimateDate $priceEstimateDate,
        MethodList $methodList,
        array $data = []
    ) {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $xmlSecurity,
            $xmlElFactory,
            $rateFactory,
            $rateMethodFactory,
            $trackFactory,
            $trackErrorFactory,
            $trackStatusFactory,
            $regionFactory,
            $countryFactory,
            $currencyFactory,
            $directoryData,
            $stockRegistry,
            $data
        );

        $this->httpClientFactory = $httpClientFactory;
        $this->priceEstimateDate = $priceEstimateDate;
        $this->methodList = $methodList;
    }

    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     * @return \Magento\Framework\DataObject|bool|null
     * @api
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->canCollectRates()) {
            return $this->getErrorMessage();
        }

        $responseBody = $this->_doShipmentRequest($request);

        if ($this->isResponseValid($responseBody) === false) {
            return false;
        }

        $this->_result = $this->getQuotes($responseBody);

        $this->_updateFreeMethodQuote($request);

        if (!empty($this->_errors)) {
            $this->debugErrors($this->_errors);
        }

        return $this->_result;
    }

    /**
     * @param $response
     * @return bool
     */
    private function isResponseValid($response)
    {
        if (trim($response) != '') {
            if (strpos(trim($response), '<?xml') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param $response
     * @return Result|\Magento\Framework\DataObject
     * @throws LocalizedException
     */
    protected function getQuotes($response)
    {
        $responseError = __('The response is in wrong format.');

        $xml = $this->parseXml($response, 'Magento\Shipping\Model\Simplexml\Element');

        if (!is_object($xml) || !isset($xml->Servicos->cServico)) {
            throw new LocalizedException($responseError);
        }

        foreach ($xml->Servicos->cServico as $shipmentDetails) {
            $this->addRate($shipmentDetails);
        }

        $result = $this->_rateFactory->create();

        if (!$this->_rates) {
            $result->append($this->getErrorMessage());

            return $result;
        }

        foreach ($this->_rates as $rateData) {
            $result->append($this->createRateObject($rateData));
        }

        return $result;
    }

    /**
     * @param \SimpleXMLElement $shipmentDetails
     * @return $this
     */
    protected function addRate(\SimpleXMLElement $shipmentDetails)
    {
        if ($shipmentDetails->Erro != 0) {
            /** @var \SimpleXMLElement $errorMsg */
            $this->_errors[(string) $shipmentDetails->Erro] = (string) $shipmentDetails->MsgErro;
            return $this;
        }

        $this->_rates[] = [
            'service_code' => (string) $shipmentDetails->Codigo,
            'service_title' => $this->getServiceTitleByCode((string) $shipmentDetails->Codigo),
            'price' => (float) $shipmentDetails->Valor,
            'estimate' => $this->calculateEstimate((int) $shipmentDetails->PrazoEntrega),
        ];

        return $this;
    }

    /**
     * @param int $days
     * @return int
     */
    private function calculateEstimate($days)
    {
        return $days + (int) $this->getConfigData('add_days_to_estimate');
    }

    /**
     * @param string $code
     * @return string
     */
    protected function getServiceTitleByCode($code)
    {
        return array_reduce($this->methodList->toOptionArray(), function ($result, $option) use ($code) {
            if ($option['value'] == $code) {
                $result = $option['label'];
            }
            return $result;
        });
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     * @api
     */
    public function getAllowedMethods()
    {
        return $this->getConfigData('allowed_methods');
    }

    /**
     * Do shipment request to carrier web service, obtain Print Shipping Labels and process errors in response
     *
     * @param \Magento\Framework\DataObject $request
     * @return \Magento\Framework\DataObject
     */
    protected function _doShipmentRequest(\Magento\Framework\DataObject $request)
    {
        $client = $this->httpClientFactory->create();
        $this->priceEstimateDate->setRateRequest($request);

        $uri = $this->priceEstimateDate->getMethodUri();
        $client->setUri($uri);
        $client->setConfig(['maxredirects' => 0, 'timeout' => 30]);
        $client->setParameterGet($this->priceEstimateDate->getParams());

        return $client->request(\Zend_Http_Client::GET)->getBody();
    }

    /**
     * @return bool|Error
     */
    protected function getErrorMessage()
    {
        if ($this->getConfigData('showmethod') == false) {
            return false;
        }

        /* @var $error Error */
        $error = $this->_rateErrorFactory->create();
        $error->setCarrier($this->getCarrierCode());
        $error->setCarrierTitle($this->getConfigData('title'));
        $error->setErrorMessage($this->getConfigData('specificerrmsg'));

        return $error;
    }

    /**
     * @param $rateData
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    protected function createRateObject($rateData)
    {
        /* @var $rate \Magento\Quote\Model\Quote\Address\RateResult\Method */
        $rate = $this->_rateMethodFactory->create();
        $rate->setData('carrier', $this->_code)
            ->setData('carrier_title', $this->getConfigData('title'))
            ->setData('method', $rateData['service_code'])
            ->setData('method_title', $this->getMethodTitle($rateData))
            ->setData('cost', $rateData['price'])
            ->setPrice($rateData['price']);

        return $rate;
    }

    /**
     * @param array $rateData
     * @return string
     */
    private function getMethodTitle(array $rateData)
    {
        if ($this->getConfigData('show_estimate')) {
            return sprintf('%s (%s dias)', $rateData['service_title'], $rateData['estimate']);
        }

        return $rateData['service_title'];
    }
}
