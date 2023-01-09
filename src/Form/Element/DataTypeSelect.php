<?php declare(strict_types=1);

namespace BulkEdit\Form\Element;

use Laminas\Form\Element\Select;
use Omeka\DataType\Manager as DataTypeManager;

/**
 * @see AdvancedResourceTemplate\Form\Element\DataTypeSelect
 * @see BulkEdit\Form\Element\DataTypeSelect
 * @see SearchSolr\Form\Element\DataTypeSelect
 */
class DataTypeSelect extends Select
{
    use TraitOptionalElement;

    protected $attributes = [
        'type' => 'select',
        'multiple' => false,
        'class' => 'chosen-select',
    ];

    /**
     * @var DataTypeManager
     */
    protected $dataTypeManager;

    /**
     * @var array
     */
    protected $dataTypes = [];

    public function getValueOptions(): array
    {
        $options = [];
        $optgroupOptions = [];
        foreach ($this->dataTypes as $dataTypeName) {
            $dataType = $this->dataTypeManager->get($dataTypeName);
            $label = $dataType->getLabel();
            if ($optgroupLabel = $dataType->getOptgroupLabel()) {
                // Hash the optgroup key to avoid collisions when merging with
                // data types without an optgroup.
                $optgroupKey = md5($optgroupLabel);
                // Put resource data types before ones added by modules.
                $optionsVal = in_array($dataTypeName, ['resource', 'resource:item', 'resource:itemset', 'resource:media'])
                    ? 'options'
                    : 'optgroupOptions';
                if (!isset(${$optionsVal}[$optgroupKey])) {
                    ${$optionsVal}[$optgroupKey] = [
                        'label' => $optgroupLabel,
                        'options' => [],
                    ];
                }
                ${$optionsVal}[$optgroupKey]['options'][$dataTypeName] = $label;
            } else {
                $options[$dataTypeName] = $label;
            }
        }
        // Always put data types not organized in option groups before data
        // types organized within option groups.
        return array_merge($options, $optgroupOptions);
    }

    public function setDataTypeManager(DataTypeManager $dataTypeManager): self
    {
        $this->dataTypeManager = $dataTypeManager;
        $this->dataTypes = $dataTypeManager->getRegisteredNames();
        return $this;
    }
}
