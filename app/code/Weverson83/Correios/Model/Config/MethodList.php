<?php
namespace Weverson83\Correios\Model\Config;
use Magento\Framework\Option\ArrayInterface;

/**
 *
 * Do not edit this file if you want to update this module for future new versions.
 *
 * @author    Weverson Cachinsky <weversoncachinsky@gmail.com>
 */
class MethodList implements ArrayInterface
{
    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        return [
            ['value' => 10065, 'label' => __('Carta Comercial')],
            ['value' => 10138, 'label' => __('Carta Comercial Registrada')],
            ['value' => 40010, 'label' => __('Sedex Sem Contrato')],
            ['value' => 40045, 'label' => __('Sedex a Cobrar')],
            ['value' => 40096, 'label' => __('Sedex Com Contrato')],
            ['value' => 40215, 'label' => __('Sedex 10')],
            ['value' => 40290, 'label' => __('Sedex HOJE')],
            ['value' => 40436, 'label' => __('Sedex Com Contrato')],
            ['value' => 41068, 'label' => __('PAC Com Contrato')],
            ['value' => 41106, 'label' => __('PAC Sem Contrato')],
            ['value' => 41300, 'label' => __('PAC GF')],
            ['value' => 81019, 'label' => __('E-Sedex Com Contrato')],
        ];
    }

    public function toArray()
    {
        return array_reduce($this->toOptionArray(), function (array $result, $option) {
            $result[] = $option['value'];

            return $result;
        }, []);
    }
}
