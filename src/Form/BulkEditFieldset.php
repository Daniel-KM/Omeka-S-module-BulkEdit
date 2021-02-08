<?php declare(strict_types=1);

namespace BulkEdit\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\PropertySelect;

class BulkEditFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->setName('bulkedit')
            ->setAttribute('id', 'bulk-edit')
            // TODO Remove all the attributes for each field. May still be used in previous versions (< 2.0).
            ->setAttribute('data-collection-action', 'replace')
            ->appendFieldsetCleaning()
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
                    'label' => 'String to replace', // @translate
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
                    'label' => 'By', // @translate
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
                    'label' => 'Replacement mode', // @translate
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
                    'label' => 'Set a language', // @translate
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
                    'label' => 'Remove language', // @translate
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
                    'label' => 'For properties', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                    'empty_option' => '',
                    'used_terms' => true,
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
                    'empty_option' => '',
                    'used_terms' => true,
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
                    'label' => 'Set visibility', // @translate
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
                    'label' => 'For properties', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                    'empty_option' => '',
                    'used_terms' => true,
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
                    'empty_option' => '',
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
                    'empty_option' => '',
                    'used_terms' => true,
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
                    'term_as_value' => true,
                    'empty_option' => '',
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
                    'empty_option' => '',
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
                    'empty_option' => '',
                    'used_terms' => true,
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
                    'empty_option' => '',
                    'used_terms' => true,
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
                    'info' => 'Some combinations are not managed.', // @translate
                    'value_options' => [
                        'literal' => 'Literal', // @translate
                        'resource' => 'Resource', // @translate
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
                    'info' => 'Warning: When converted to uri, the format is not checked.', // @translate
                    'value_options' => [
                        'literal' => 'Literal', // @translate
                        'resource' => 'Resource', // @translate
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
                    'empty_option' => '',
                    'used_terms' => true,
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
                'name' => 'literal_value',
                'type' => Element\Select::class,
                'options' => [
                    'label' => 'Convert to literal: Content', // @translate
                    'value_options' => [
                        'label_uri' => 'Label and uri', // @translate
                        'uri_label' => 'Uri and label', // @translate
                        'uri' => 'Uri only', // @translate
                        'label' => 'Label only', // @translate
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_literal_value',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select option', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'resource_properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'Convert to resource: Properties where to search the identifier', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'o:id' => 'Omeka internal id', // @translate
                    ],
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'convert_resource_properties',
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
                    'label' => 'Convert to uri: Label of uri', // @translate
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
                    'label' => 'String to replace', // @translate
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
                    'label' => 'By', // @translate
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
                    'label' => 'Replacement mode', // @translate
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
                'name' => 'specify_datatypes',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Specify data type "resource" for linked resources', // @translate
                    'info' => 'In some cases, linked resources are saved in the database with the generic data type "resource", not with the specific "resource:item", "resource:media, etc.', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_datatypes',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_languages',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Clean languages (set null when language is empty)', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_languages',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_language_codes',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Normalize or modify language codes', // @translate
                    'info' => 'Normalize language codes from a code to another one, for example "fr" to "fra" or vice-versa. It allows to add or remove a code too.', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_language_codes',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_language_codes_from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'From code', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_language_codes_from',
                    'placeholder' => 'fr',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_language_codes_to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'To code', // @translate
                ],
                'attributes' => [
                    'id' => 'cleaning_language_codes_to',
                    'placeholder' => 'fra',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_language_codes_properties',
                'type' => PropertySelect::class,
                'options' => [
                    'label' => 'For properties', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'cleaning_language_codes_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'required' => false,
                    'data-placeholder' => 'Select properties', // @translate
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
}
