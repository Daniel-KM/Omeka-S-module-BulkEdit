<?php declare(strict_types=1);

namespace BulkEdit\Form;

use BulkEdit\Form\Element as BulkEditElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element as OmekaElement;

class BulkEditFieldset extends Fieldset
{
    /**
     * @var array
     */
    protected $dataTypesMain = [];

    /**
     * @var array
     */
    protected $dataTypesLabels = [];

    /**
     * @var array
     */
    protected $mediaTypes = [];

    /**
     * @var array
     */
    protected $ingesters = [];

    /**
     * @var array
     */
    protected $renderers = [];

    /**
     * Warning: Update bulk-edit.js for empty values to fix batch update selected resource.
     * Fixed in Omeka S v4.0.2 (#609dbbb30).
     *
     * {@inheritDoc}
     * @see \Laminas\Form\Element::init()
     */
    public function init(): void
    {
        $resourceType = $this->getOption('resource_type');

        $this
            ->setName('bulkedit')
            ->setAttribute('id', 'bulk-edit')
            // TODO Remove all the attributes for each field. May still be used in previous versions (< 2.0).
            ->setAttribute('data-collection-action', 'replace')
            ->appendFieldsetCleaning()
            ->appendFieldsetReplace()
            ->appendFieldsetDisplace()
            ->appendFieldsetExplode()
            ->appendFieldsetMerge()
            ->appendFieldsetConvert()
            ->appendFieldsetOrderValues()
            ->appendFieldsetPropertiesVisibility()
            ->appendFieldsetFillData()
            ->appendFieldsetFillValues()
            ->appendFieldsetRemove()
        ;

        switch ($resourceType) {
            case 'items':
            case 'item':
                $this
                    ->appendFieldsetExplodeItem()
                    ->appendFieldsetExplodePdf()
                    ->appendFieldsetMediaOrder()
                ;
                // no break.
            case 'media':
                $this
                    ->appendFieldsetMediaHtml()
                    ->appendFieldsetMediaType()
                    ->appendFieldsetMediaVisibility()
                ;
                break;
            case 'item_sets':
            case 'item-set':
            case 'itemSet':
            default:
                break;
        }

        // Omeka doesn't display fieldsets, so add them via a hidden input
        // managed by js.
        $fieldsets = [];
        foreach ($this->getFieldsets() as $fieldset) {
            $name = $fieldset->getName();
            $fieldsets[$fieldset->getName()] = $fieldset->getLabel();
            foreach ($fieldset->getElements() as $element) {
                $element->setAttribute('data-bulkedit-fieldset', $name);
            }
        }
        $this
            ->add([
                'name' => 'bulkedit-fieldsets',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'id' => 'bulkedit-fieldsets',
                    'data-bulkedit-fieldsets' => json_encode($fieldsets, 320),
                ],
            ])
        ;
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
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Trim property values', // @translate
                    'info' => 'Remove initial and trailing whitespace of all values of all properties', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'cleaning_trim_values',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'specify_datatypes',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Specify data type "resource" for linked resources', // @translate
                    'info' => 'In some cases, linked resources are saved in the database with the generic data type "resource", not with the specific "resource:item", "resource:media, etc.', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'cleaning_specify_datatypes',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_languages',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Clean languages (set null when language is empty)', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'cleaning_clean_languages',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_language_codes',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Normalize or modify language codes', // @translate
                    'info' => 'Normalize language codes from a code to another one, for example "fr" to "fra" or vice-versa. It allows to add or remove a code too.', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'cleaning_clean_language_codes',
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
                    'id' => 'cleaning_clean_language_codes_from',
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
                    'id' => 'cleaning_clean_language_codes_to',
                    'placeholder' => 'fra',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_language_codes_properties',
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                    'id' => 'cleaning_clean_language_codes_properties',
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
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Deduplicate property values case insensitively', // @translate
                    'info' => 'Deduplicate values of all properties, case INsensitively. Trimming values before is recommended, because values are checked strictly.', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'cleaning_clean_deduplicate_values',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
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
                'type' => BulkEditElement\OptionalRadio::class,
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
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Remove string', // @translate
                    'use_hidden_element' => false,
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
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Remove language', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'replace_language_clear',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => BulkEditElement\OptionalPropertySelect::class,
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

    protected function appendFieldsetDisplace()
    {
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                'type' => BulkEditElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Only datatypes', // @translate
                    'empty_option' => '[All datatypes]', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select datatypes…', // @translate
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
                'type' => BulkEditElement\OptionalRadio::class,
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                'type' => BulkEditElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'From datatype', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_from',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-datatypes' => json_encode($this->dataTypesMain, 320),
                    'data-placeholder' => 'Select datatype', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => BulkEditElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'To datatype', // @translate
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'For properties', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                    'used_terms' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
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
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Convert to literal: Content', // @translate
                    'value_options' => [
                        'label_uri' => 'Label and uri', // @translate
                        'uri_label' => 'Uri and label', // @translate
                        'label_or_uri' => 'Label if present, else uri', // @translate
                        'label' => 'Label only', // @translate
                        'uri' => 'Uri only', // @translate
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_literal_value',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-info-datatype' => 'literal',
                    'data-placeholder' => 'Select option', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'literal_extract_html_text',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Convert to literal: keep only text from html/xml', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'convert_literal_extract_html_text',
                    'data-info-datatype' => 'literal',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'literal_html_only_tagged_string',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Convert to html/xml: only html/xml-looking strings', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'convert_literal_html_only_tagged_string',
                    'data-info-datatype' => 'literal',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'resource_properties',
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                    'data-info-datatype' => 'resource',
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'uri_extract_label',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Convert to uri: extract label after uri', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'convert_uri_extract_label',
                    'data-info-datatype' => 'uri',
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
                    'data-info-datatype' => 'uri',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'uri_base_site',
                'type' => BulkEditElement\OptionalSiteSelect::class,
                'options' => [
                    'label' => 'Convert to uri: Site to use as base url', // @translate
                    // 'disable_group_by_owner' => true,
                    'prepend_value_options' => [
                        'api' => 'Api url', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'convert_uri_base_site',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-info-datatype' => 'uri',
                    'data-placeholder' => 'Select a site', // @translate
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
                    'id' => 'convert_contains',
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
        $this
            ->add([
                'name' => 'properties_visibility',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Visibility of values', // @translate
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
                'type' => BulkEditElement\OptionalRadio::class,
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
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                'type' => BulkEditElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Only datatypes', // @translate
                    'empty_option' => '[All datatypes]', // @translate
                ],
                'attributes' => [
                    'id' => 'propvis_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select datatypes…', // @translate
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

    protected function appendFieldsetFillData()
    {
        $this
            ->add([
                'name' => 'fill_data',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Fill and update metadata', // @translate
                ],
                'attributes' => [
                    'id' => 'fill_data',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('fill_data');
        $fieldset
            ->add([
                'name' => 'owner',
                'type' => BulkEditElement\OptionalUserSelect::class,
                'options' => [
                    'label' => 'Append or remove owner', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        '0' => 'Remove owner', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'fill_data_owner',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select a user…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        return $this;
    }

    protected function appendFieldsetFillValues()
    {
        $managedDatatypes = [
            'valuesuggest:geonames:geonames' => 'Geonames',
            'valuesuggest:idref:person' => 'IdRef Personnes',
            'valuesuggest:idref:corporation' => 'IdRef Organisations',
            'valuesuggest:idref:conference' => 'IdRef Congrès',
            'valuesuggest:idref:subject' => 'IdRef Sujets',
            'valuesuggest:idref:rameau' => 'IdRef Sujets Rameau',
        ];

        $datatypesVSAttrs = [];
        foreach ($this->dataTypesLabels as $datatype => $label) {
            if (substr($datatype, 0, 12) === 'valuesuggest'
                // || substr($datatype, 0, 15) === 'valuesuggestall'
            ) {
                $isManaged = isset($managedDatatypes[$datatype]);
                // All datatypes are included for uri, not label.
                // Uri is not selected on load, but all datatypes are included
                // anyway until mode selection.
                $attributes = $isManaged
                    ? []
                    : ['data-fill-mode-option' => 'uri'];
                $datatypesVSAttrs[] = [
                    'value' => $datatype,
                    'label' => $label,
                    'attributes' => $attributes,
                ];
            }
        }

        $this
            ->add([
                'name' => 'fill_values',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Fill labels or uris for Value Suggest', // @translate
                ],
                'attributes' => [
                    'id' => 'fill_values',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('fill_values');
        $fieldset
            ->add([
                'name' => 'mode',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Fill mode', // @translate
                    'value_options' => [
                        [
                            'value' => 'label_missing',
                            'label' => 'Fill missing labels of uris', // @translate
                            'attributes' => [
                                'data-fill-main' => 'label',
                            ],
                        ],
                        [
                            'value' => 'label_all',
                            'label' => 'Reset and fill all labels of uris', // @translate
                            'attributes' => [
                                'data-fill-main' => 'label',
                            ],
                        ],
                        [
                            'value' => 'label_remove',
                            'label' => 'Remove labels of uris', // @translate
                            'attributes' => [
                                'data-fill-main' => 'label',
                            ],
                        ],
                        [
                            'value' => 'uri_missing',
                            'label' => 'Fill missing uri from labels', // @translate
                            'attributes' => [
                                'data-fill-main' => 'uri',
                            ],
                        ],
                        [
                            'value' => 'uri_all',
                            'label' => 'Reset and fill missing uri from labels', // @translate
                            'attributes' => [
                                'data-fill-main' => 'uri',
                            ],
                        ],
                    ],
                ],
                'attributes' => [
                    'id' => 'fill_mode',
                    // 'value' => 'empty',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => BulkEditElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'For properties', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'fill_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'note_uri',
                'type' => BulkEditElement\Note::class,
                'options' => [
                    'content' => 'The uri can be filled only when the remote endpoint returns a single result.', // @translate
                    // TODO For compatibility with other modules, the content is passed as text too. Will be removed in Omeka S v4.
                    'text' => 'The uri can be filled only when the remote endpoint returns a single result.', // @translate
                ],
                'attributes' => [
                    'id' => 'fill_note_uri',
                    'class' => 'field',
                    'data-fill-mode' => 'uri',
                    'style' => 'display: none',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Data types to process', // @translate
                    'empty_option' => '',
                    'value_options' => array_merge([
                        [
                            'value' => 'all',
                            'label' => '[All datatypes]', // @translate
                        ],
                        [
                            'value' => 'literal',
                            'label' => 'Literal', // @translate
                        ],
                        [
                            'value' => 'uri',
                            'label' => 'Uri', // @translate
                        ],
                    ], $datatypesVSAttrs),
                ],
                'attributes' => [
                    'id' => 'fill_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select datatypes…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatype',
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Data type to use when the value is literal or uri', // @translate
                    'empty_option' => '',
                    'value_options' => $datatypesVSAttrs,
                ],
                'attributes' => [
                    'id' => 'fill_datatype',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select a datatype…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Language code for querying and filling', // @translate
                ],
                'attributes' => [
                    'id' => 'fill_language',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'update_language',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Update language in value', // @translate
                    'value_options' => [
                        'keep' => 'Keep', // @translate
                        'update' => 'Update', // @translate
                        'remove' => 'Remove', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'fill_update_language',
                    'value' => 'keep',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'featured_subject',
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Use featured subject (Rameau)', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'fill_featured_subject',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;
        return $this;
    }

    protected function appendFieldsetRemove()
    {
        $this
            ->add([
                'name' => 'remove',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Remove values, linked resources, or uris from a property', // @translate
                ],
                'attributes' => [
                    'id' => 'remove',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('remove');
        $fieldset
            ->add([
                'name' => 'properties',
                'type' => BulkEditElement\OptionalPropertySelect::class,
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
                    'id' => 'remove_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => BulkEditElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Only datatypes', // @translate
                    'empty_option' => '[All datatypes]', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select datatypes…', // @translate
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
                    'id' => 'remove_languages',
                    // 'class' => 'value-language active',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'visibility',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Only visibility', // @translate
                    'value_options' => [
                        '1' => 'Public', // @translate
                        '0' => 'Not public', // @translate
                        '' => 'Any', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'remove_visibility',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'equal',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only equal to', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_equal',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'contains',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Only containing string (text or uri)', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetExplodeItem()
    {
        $this
            ->add([
                'name' => 'explode_item',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Explode item by media', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_item',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('explode_item');
        $fieldset
            ->add([
                'name' => 'note_process',
                'type' => BulkEditElement\Note::class,
                'options' => [
                    'content' => 'Check first in jobs and logs that there is no background process working on medias, for example data extraction or indexation.', // @translate
                    // TODO For compatibility with other modules, the content is passed as text too. Will be removed in Omeka S v4.
                    'text' => 'Check first in jobs and logs that there is no background process working on medias, for example data extraction or indexation.', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_item_note_process',
                    'class' => 'field',
                    'style' => 'display_block',
                ],
            ])
            ->add([
                'name' => 'mode',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'What to do with media metadata', // @translate
                    'value_options' => [
                        '' => 'No process', // @translate
                        'append' => 'Append media metadata to item metadata', // @translate
                        'update' => 'Replace item metadata by media metadata when set', // @translate
                        'replace' => 'Remove all item metadata and replace them by media ones', // @translate
                        'none' => 'Do not copy media metadata and keep them in media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'explode_item_mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetExplodePdf()
    {
        $this
            ->add([
                'name' => 'explode_pdf',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Explode pdf into jpeg for iiif viewers', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_pdf',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
            $fieldset = $this->get('explode_pdf');
        $fieldset
            ->add([
                'name' => 'note_process',
                'type' => BulkEditElement\Note::class,
                'options' => [
                    'content' => 'Check first in jobs and logs that there is no background process working on medias, for example data extraction or indexation.', // @translate
                    // TODO For compatibility with other modules, the content is passed as text too. Will be removed in Omeka S v4.
                    'text' => 'Check first in jobs and logs that there is no background process working on medias, for example data extraction or indexation.', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_pdf_note_process',
                    'class' => 'field',
                    'style' => 'display_block',
                ],
            ])
            ->add([
                'name' => 'mode',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Process mode', // @translate
                    'value_options' => [
                        '' => 'No process', // @translate
                        'all' => 'All pdf of each item', // @translate
                        'first' => 'First pdf only', // @translate
                        'last' => 'Last pdf only', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'explode_pdf_mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'process',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Creation process', // @translate
                    'value_options' => [
                        'all' => 'All pages', // @translate
                        'skip' => 'Skip existing pages (same created file name)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'explode_pdf_process',
                    'value' => 'all',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'resolution',
                'type' => BulkEditElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Resolution, generally 72, 96, 150, 300, 400 (default), 600 or more', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_pdf_resolution',
                    'min' => '0',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMediaOrder()
    {
        $this
            ->add([
                'name' => 'media_order',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Media order', // @translate
                ],
                'attributes' => [
                    'id' => 'media_order',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('media_order');
        $fieldset
            ->add([
                'name' => 'order',
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Media order', // @translate
                    'value_options' => [
                        'simple' => [
                            'label' => 'Simple',
                            'options' => [
                                'title' => 'By title', // @translate
                                'source' => 'By original source full name', // @translate
                                'basename' => 'By original source basename', // @translate
                                'mediatype' => 'By media type', // @translate
                                'extension' => 'By extension', // @translate
                            ],
                        ],
                        'mediatype_first' => [
                            'label' => 'Sort by media type first',
                            'options' => [
                                'mediatype/title' => 'By media type then title', // @translate
                                'mediatype/source' => 'By media type then source', // @translate
                                'mediatype/basename' => 'By media type then source basename', // @translate
                            ],
                        ],
                        'mediatype_after' => [
                            'label' => 'Sort by media type last',
                            'options' => [
                                'title/mediatype' => 'By title then media type', // @translate
                                'source/mediatype' => 'By source then media type', // @translate
                                'basename/mediatype' => 'By source basename then media type', // @translate
                            ],
                        ],
                        'extension_first' => [
                            'label' => 'Sort by extension first',
                            'options' => [
                                'extension/title' => 'By extension then title', // @translate
                                'extension/source' => 'By extension then source', // @translate
                                'extension/basename' => 'By extension then source basename', // @translate
                            ],
                        ],
                        'extension_after' => [
                            'label' => 'Sort by extension last',
                            'options' => [
                                'title/extension' => 'By title then extension', // @translate
                                'source/extension' => 'By source then extension', // @translate
                                'basename/extension' => 'By source basename then extension', // @translate
                            ],
                        ],
                    ],
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'mediaorder_order',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-info-datatype' => 'literal',
                    'data-placeholder' => 'Select option', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'mediatypes',
                'type' => BulkEditElement\ArrayText::class,
                'options' => [
                    'label' => 'List of media types to order first', // @translate
                    'value_separator' => ' ', // @translate
                ],
                'attributes' => [
                    'id' => 'mediaorder_mediatypes',
                    'value' => [
                        'video',
                        'audio',
                        'image',
                        'application/pdf',
                    ],
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'extensions',
                'type' => BulkEditElement\ArrayText::class,
                'options' => [
                    'label' => 'List of extensions to order first', // @translate
                ],
                'attributes' => [
                    'id' => 'mediaorder_extensions',
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
                'type' => BulkEditElement\OptionalRadio::class,
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
                'type' => BulkEditElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Remove string', // @translate
                    'use_hidden_element' => false,
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

    protected function appendFieldsetMediaType()
    {
        $this
            ->add([
                'name' => 'media_type',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Media type (mime-type)', // @translate
                ],
                'attributes' => [
                    'id' => 'media_type',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('media_type');
        $fieldset
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Media type to replace', // @translate
                ],
                'attributes' => [
                    'id' => 'mediatype_from',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'By a valid media-type', // @translate
                ],
                'attributes' => [
                    'id' => 'mediatype_to',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMediaVisibility()
    {
        $this
            ->add([
                'name' => 'media_visibility',
                'type' => Fieldset::class,
                'options' => [
                    'label' => 'Visibility of medias', // @translate
                ],
                'attributes' => [
                    'id' => 'media_visibility',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('media_visibility');
        $fieldset
            ->add([
                'name' => 'visibility',
                'type' => BulkEditElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Set visibility', // @translate
                    'value_options' => [
                        '1' => 'Public', // @translate
                        '0' => 'Not public', // @translate
                        '' => '[No change]', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mediavis_visibility',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'media_types',
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Limit to media types', // @translate
                    'empty_option' => 'All media types', // @translate
                    'value_options' => $this->mediaTypes,
                ],
                'attributes' => [
                    'id' => 'mediavis_media_types',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select media types to process', // @ translate
                    'data-placeholder' => 'Select media types to process', // @ translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'ingesters',
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Limit to ingesters', // @translate
                    'empty_option' => 'All ingesters', // @translate
                    'value_options' => $this->ingesters,
                ],
                'attributes' => [
                    'id' => 'mediavis_ingesters',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select ingesters to process', // @ translate
                    'data-placeholder' => 'Select ingesters to process', // @ translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'renderers',
                'type' => BulkEditElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Limit to renderers', // @translate
                    'empty_option' => 'All renderers', // @translate
                    'value_options' => $this->renderers,
                ],
                'attributes' => [
                    'id' => 'mediavis_renderers',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'placeholder' => 'Select renderers to process', // @ translate
                    'data-placeholder' => 'Select renderers to process', // @ translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    public function setDataTypesMain(array $dataTypesMain): self
    {
        $this->dataTypesMain = $dataTypesMain;
        return $this;
    }

    public function setDataTypesLabels(array $dataTypesLabels): self
    {
        $this->dataTypesLabels = $dataTypesLabels;
        return $this;
    }

    public function setMediaTypes(array $mediaTypes): self
    {
        $this->mediaTypes = $mediaTypes;
        return $this;
    }

    public function setIngesters(array $ingesters): self
    {
        $this->ingesters = $ingesters;
        return $this;
    }

    public function setRenderers(array $renderers): self
    {
        $this->renderers = $renderers;
        return $this;
    }
}
