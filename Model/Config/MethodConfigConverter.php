<?php
namespace Weverson83\Correios\Model\Config;

use Magento\Framework\Config\ConverterInterface;
use Weverson83\Correios\Model\Method\Method;

class MethodConfigConverter implements ConverterInterface
{
    /**
     * Convert config
     *
     * @param \DOMDocument $source
     * @return Method[]
     */
    public function convert($source)
    {
        $rootNode = $this->getRootNode($source);
        $result = [];
        foreach ($this->getAllChildElements($rootNode) as $childNode) {
            if ($childNode->nodeName === 'service') {
                $method = new Method();
                $method->setCode($this->getNodeAttributeValue($childNode, 'value'))
                    ->setLabel($this->getNodeAttributeValue($childNode, 'label'))
                    ->setMaxWeight($this->getNodeAttributeValue($childNode, 'maxWeight'));

                $result[] = $method;
            }
        }
        return [$rootNode->nodeName => $result];
    }

    public function getRootNode(\DOMDocument $document)
    {
        return $this->getAllChildElements($document)[0];
    }

    /**
     * @param \DOMNode $source
     * @return \DOMElement[]
     */
    public function getAllChildElements(\DOMNode $source)
    {
        return array_filter(iterator_to_array($source->childNodes), function (\DOMNode $childNode) {
            return $childNode->nodeType === \XML_ELEMENT_NODE;
        });
    }

    private function getNodeAttributeValue($childNode, $attributeName)
    {
        return $childNode->attributes->getNamedItem($attributeName)->nodeValue;
    }
}
