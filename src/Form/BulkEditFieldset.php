<?php
namespace BulkEdit\Form;

use Zend\Form\Element;
use Zend\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;

class BulkEditFieldset extends Fieldset
{
    public function init()
    {
        $this
            ->setName('bulkedit')
            ->setAttribute('id', 'bulk-edit')
            // TODO Remove all the attributes for each field. May still be used in previous versions (< 2.0).
            ->setAttribute('data-collection-action', 'replace');
        if (!$this->hasOldModuleNext()) {
            $this
                ->appendFieldsetCleaning();
        }
        $this
            ->appendFieldsetReplace()
            ->appendFieldsetOrderValues()
            ->appendFieldsetPropertiesVisibility()
            ->appendFieldsetDisplace()
            ->appendFieldsetExplode()
            ->appendFieldsetMerge()
            ->appendFieldsetConvert()
            ->appendFieldsetMediaHtml()
        ;
    }

    protected function appendFieldsetReplace()
    {
        $this
            ->add([
                'name' => 'replace',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Replace literal values', // @translate
                ],
                'attributes' => [
                    'id' => 'replace',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('replace');
        $fieldset
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'String to replace…', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_from',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => '… by…', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_to',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => '… using replacement mode', // @translate
                    'value_options' => [
                        'raw' => 'Simple', // @translate
                        'raw_i' => 'Simple (case insensitive)', // @translate
                        'html' => 'Simple and html entities', // @translate
                        'regex' => 'Regex (full pattern)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'replace_mode',
                    'value' => 'raw',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'remove',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Remove string', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_remove',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'prepend',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'String to prepend', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_prepend',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'append',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'String to append', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_append',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Set a language…', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_language',
                    'class' => 'value-language active',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'language_clear',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => '… or remove it…', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_language_clear',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => '… for properties', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'replace_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        return $this;
    }

    protected function appendFieldsetOrderValues()
    {
        $this
            ->add([
                'name' => 'order_values',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Values order', // @translate
                ],
                'attributes' => [
                    'id' => 'order_values',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('order_values');
        $fieldset
            ->add([
                'name' => 'languages',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Order by language', // @translate
                    'info' => 'List the language you want to order before other values.', // @translate
                ],
                'attributes' => [
                    'id' => 'order_languages',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties to order', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'order_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetPropertiesVisibility()
    {
        $datatypes = $this->listDataTypesForSelect();
        $datatypes = [
            'all' => '[All datatypes]', // @translate
        ] + $datatypes;

        $this
            ->add([
                'name' => 'properties_visibility',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Visibility', // @translate
                ],
                'attributes' => [
                    'id' => 'properties_visibility',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('properties_visibility');
        $fieldset
            ->add([
                'name' => 'visibility',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Set visibility…', // @translate
                    'value_options' => [
                        '1' => 'Public', // @translate
                        '0' => 'Not public', // @translate
                        '' => '[No change]', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'propvis_visibility',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => '… for properties', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'propvis_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Only datatypes', // @translate
                    'value_options' => $datatypes,
                ],
                'attributes' => [
                    'id' => 'propvis_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select datatypes', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'languages',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only languages', // @translate
                ],
                'attributes' => [
                    'id' => 'propvis_languages',
                    // 'class' => 'value-language active',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'contains',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only containing', // @translate
                ],
                'attributes' => [
                    'id' => 'propvis_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetDisplace()
    {
        $datatypes = $this->listDataTypesForSelect();
        $datatypes = [
            'all' => '[All datatypes]', // @translate
        ] + $datatypes;

        $this
            ->add([
                'name' => 'displace',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Displace values', // @translate
                ],
                'attributes' => [
                    'id' => 'displace',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('displace');
        $fieldset
            ->add([
                'name' => 'from',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'From properties', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'displace_from',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'To property', // @translate
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'displace_to',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select property', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Only datatypes', // @translate
                    'value_options' => $datatypes,
                ],
                'attributes' => [
                    'id' => 'displace_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select datatypes', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'languages',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only languages', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_languages',
                    // 'class' => 'value-language active',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'visibility',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Only visibility', // @translate
                    'value_options' => [
                        '1' => 'Public', // @translate
                        '0' => 'Not public', // @translate
                        '' => 'Any', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'displace_visibility',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'contains',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only containing', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetExplode()
    {
        $this
            ->add([
                'name' => 'explode',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Explode values', // @translate
                ],
                'attributes' => [
                    'id' => 'explode',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('explode');
        $fieldset
            ->add([
                'name' => 'properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'explode_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'separator',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Separator', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_separator',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'contains',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only containing', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMerge()
    {
        $this
            ->add([
                'name' => 'merge',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Merge values as uri and label', // @translate
                    'info' => 'The values are merged two by two, whatever order and initial datatype. The number of values must be even and clean.', // @translate
                ],
                'attributes' => [
                    'id' => 'merge',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('merge');
        $fieldset
            ->add([
                'name' => 'properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'merge_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetConvert()
    {
        $this
            ->add([
                'name' => 'convert',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Convert datatype', // @translate
                ],
                'attributes' => [
                    'id' => 'convert',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('convert');
        $fieldset
            ->add([
                'name' => 'from',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'From datatype', // @translate
                    'value_options' => [
                        'literal' => 'Literal', // @translate
                        'uri' => 'Uri', // @translate
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_from',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select datatype', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'To datatype', // @translate
                    'info' => 'Warning: When converted to uri, the format is not checked. When converted to text, the label is lost.', // @translate
                    'value_options' => [
                        'literal' => 'Literal', // @translate
                        'uri' => 'Uri', // @translate
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_to',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select datatype', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'For properties', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'convert_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'uri_label',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Set label of uri', // @translate
                ],
                'attributes' => [
                    'id' => 'convert_uri_label',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMediaHtml()
    {
        $this
            ->add([
                'name' => 'media_html',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Media HTML', // @translate
                ],
                'attributes' => [
                    'id' => 'media_html',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('media_html');
        $fieldset
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'String to replace…', // @translate
                ],
                'attributes' => [
                    'id' => 'mediahtml_from',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => '… by…', // @translate
                ],
                'attributes' => [
                    'id' => 'mediahtml_to',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => '… using replacement mode', // @translate
                    'value_options' => [
                        'raw' => 'Simple', // @translate
                        'raw_i' => 'Simple (case insensitive)', // @translate
                        'html' => 'Simple and html entities', // @translate
                        'regex' => 'Regex (full pattern)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mediahtml_mode',
                    'value' => 'raw',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'remove',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Remove string', // @translate
                ],
                'attributes' => [
                    'id' => 'mediahtml_remove',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'prepend',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'String to prepend', // @translate
                ],
                'attributes' => [
                    'id' => 'mediahtml_prepend',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'append',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'String to append', // @translate
                ],
                'attributes' => [
                    'id' => 'mediahtml_append',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetCleaning()
    {
        $this
            ->add([
                'name' => 'cleaning',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Cleaning', // @translate
                ],
                'attributes' => [
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('cleaning');
        $fieldset
            ->add([
                'name' => 'trim_values',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Trim property values', // @translate
                    'info' => 'Remove initial and trailing whitespace of all values of all properties', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_trim',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'deduplicate_values',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Deduplicate property values case insensitively', // @translate
                    'info' => 'Deduplicate values of all properties, case INsensitively. Trimming values before is recommended, because values are checked strictly.', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_deduplicate',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    /**
     * @return array
     */
    protected function listDataTypesForSelect()
    {
        return $this->getOption('listDataTypesForSelect') ?: [];
    }

    /**
     * @return bool
     */
    protected function hasOldModuleNext()
    {
        return !empty($this->getOption('hasOldModuleNext'));
    }
}
