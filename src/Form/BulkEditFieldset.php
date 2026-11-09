<?php declare(strict_types=1);

namespace BulkEdit\Form;

use BulkEdit\Form\Element as BulkEditElement;
use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\I18n\Translator\TranslatorAwareInterface;
use Laminas\I18n\Translator\TranslatorAwareTrait;
use Omeka\Form\Element as OmekaElement;

/**
 * Adapted:
 * @see \BulkEdit\Form\BulkEditFieldset
 * @see \BulkExport\Form\ShaperConfigFieldset
 */
class BulkEditFieldset extends Fieldset implements TranslatorAwareInterface
{
    // TODO Fix translation of fieldset legend in core.
    use TranslatorAwareTrait;

    protected $elementGroups = [
        'advanced' => 'Advanced', // @translate
    ];

    /**
     * Groups of fieldsets, the default one being "Values".
     */
    protected $fieldsetGroups = [
        'fill_data' => 'Resource', // @translate
        'thumbnails' => 'Resource',
        'explode_item' => 'Media', // @translate
        'explode_pdf' => 'Media',
        'media_remove' => 'Media',
        'media_order' => 'Media',
        'media_html' => 'Media',
        'media_source' => 'Media',
        'media_type' => 'Media',
        'media_visibility' => 'Media',
    ];

    /**
     * Elements required to process an action, according to prepareProcesses().
     *
     * Each group requires one filled element, or a value ("name=value"), or all
     * filled elements ("name+name").
     */
    protected $requiredElements = [
        'create_values' => [['values']],
        'modify' => ['any' => [
            'replace' => [['properties'], ['replace/enabled'], ['replace/action=search+replace/from', 'replace/action=whole', 'replace/action=filename', 'replace/action=unicode', 'replace/action=remove', 'replace/prepend', 'replace/append']],
            'language' => [['properties'], ['language/enabled'], ['language/action=set+language/language', 'language/action=remove', 'language/action=change+language/code_from', 'language/action=change+language/code_to']],
            'explode' => [['properties'], ['explode/enabled'], ['explode/separator']],
            'convert' => [['properties'], ['convert/enabled'], ['convert/from'], ['convert/to']],
            'order_values' => [['properties'], ['order_values/enabled'], ['order_values/languages']],
            'properties_visibility' => [['properties'], ['properties_visibility/enabled'], ['properties_visibility/visibility']],
        ]],
        'copy' => [['from'], ['to']],
        'displace' => [['from'], ['to']],
        'merge' => [['properties']],
        'fill_values' => [['mode'], ['properties']],
        'remove' => [['properties']],
        'cleaning' => [['trim_values', 'specify_datatypes', 'clean_empty_values', 'clean_languages', 'deduplicate_values', 'clean_language_codes+clean_language_codes_properties']],
        'fill_data' => [['owner']],
        'thumbnails' => [['mode']],
        'explode_item' => [['mode']],
        'explode_pdf' => [['mode']],
        'media_remove' => [['mode']],
        'media_order' => [['order']],
        'media_html' => [['mode'], ['from', 'to', 'prepend', 'append', 'mode=unicode', 'mode=remove']],
        'media_source' => [['mode'], ['from', 'to', 'prepend', 'append', 'mode=basename', 'mode=filename', 'mode=unicode', 'mode=remove']],
        'media_type' => [['from'], ['to']],
        'media_visibility' => [['visibility']],
    ];

    /**
     * Elements displayed in a collapsed block "Options".
     */
    protected $secondaryElements = [
        'fill_values' => ['datatypes', 'datatype', 'language', 'update_language', 'featured_subject'],
        'remove' => ['datatypes', 'languages', 'visibility', 'equal', 'contains', 'match', 'keep_max_values', 'max_values'],
        'explode_pdf' => ['process', 'resolution', 'processor', 'process_events'],
        'media_order' => ['mediatypes', 'extensions'],
        'media_html' => ['prepend', 'append'],
        'media_source' => ['prepend', 'append'],
        'media_visibility' => ['media_types', 'ingesters', 'renderers'],
    ];

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
            // TODO Currently, the elements groups of fieldset are not take in account in bulk edit.
            ->setOption('element_groups', $this->elementGroups)
            // TODO Remove all the attributes for each field. May still be used in previous versions (< 2.0).
            ->setAttribute('data-collection-action', 'replace')
            // The fieldsets are ordered like the processes.
            ->appendFieldsetCreateValues()
            ->appendFieldsetModify()
            ->appendFieldsetCopy()
            ->appendFieldsetDisplace()
            ->appendFieldsetMerge()
            ->appendFieldsetFillValues()
            ->appendFieldsetRemove()
            ->appendFieldsetCleaning()
            ->appendFieldsetFillData()
            ->appendFieldsetThumbnail()
        ;

        switch ($resourceType) {
            case 'items':
            case 'item':
                $this
                    ->appendFieldsetExplodeItem()
                    ->appendFieldsetExplodePdf()
                    ->appendFieldsetRemoveMedia()
                    ->appendFieldsetMediaOrder()
                    ->appendFieldsetMediaHtml()
                    ->appendFieldsetMediaSource()
                    ->appendFieldsetMediaType()
                    ->appendFieldsetMediaVisibility()
                ;
                break;
            case 'media':
                $this
                    ->appendFieldsetMediaHtml()
                    ->appendFieldsetMediaSource()
                    ->appendFieldsetMediaType()
                    ->appendFieldsetMediaVisibility()
                ;
                break;
            // The processes on media do not manage digital objects.
            case 'digital_objects':
            case 'digital-object':
            case 'digitalObject':
            case 'item_sets':
            case 'item-set':
            case 'itemSet':
            default:
                break;
        }

        // Omeka doesn't display fieldsets, so add them via a hidden input
        // managed by js.
        // TODO The element groups are not take in account currently.
        $fieldsets = [];
        $blocks = [];
        $groups = [];
        $currentGroup = null;
        foreach ($this->getFieldsets() as $fieldset) {
            $name = $fieldset->getName();
            $fieldsets[$name] = $fieldset->getLabel();
            $group = $this->fieldsetGroups[$name] ?? 'Values'; // @translate
            if ($group !== $currentGroup) {
                $groups[$name] = $this->translator->translate($group);
                $currentGroup = $group;
            }
            $secondaryElements = $this->secondaryElements[$name] ?? [];
            $fieldset->setOption('element_group', 'advanced');
            foreach ($fieldset->getElements() as $element) {
                $element->setAttribute('data-bulkedit-fieldset', $name);
                if (in_array($element->getName(), $secondaryElements)) {
                    $element->setAttribute('data-bulkedit-option', '1');
                }
            }
            foreach ($fieldset->getFieldsets() as $block) {
                $blocks[$name][$block->getName()] = $block->getLabel();
                foreach ($block->getElements() as $element) {
                    $element->setAttribute('data-bulkedit-fieldset', $name);
                    $element->setAttribute('data-bulkedit-block', $block->getName());
                }
            }
        }
        $this
            ->add([
                'name' => 'bulkedit-fieldsets',
                'type' => Element\Hidden::class,
                'options' => [
                    'element_group' => 'advanced',
                ],
                'attributes' => [
                    'id' => 'bulkedit-fieldsets',
                    'data-bulkedit-fieldsets' => json_encode($fieldsets, 320),
                    'data-bulkedit-groups' => json_encode($groups, 320),
                    'data-bulkedit-blocks' => json_encode($blocks, 320),
                    'data-bulkedit-required' => json_encode(array_intersect_key($this->requiredElements, $fieldsets), 320),
                ],
            ])
        ;
    }

    protected function appendFieldsetCleaning(): self
    {
        $this
            ->add([
                'name' => 'cleaning',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Cleaning'), // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Trim values', // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Specify linked resource types', // @translate
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
                'name' => 'clean_empty_values',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Empty values to null', // @translate
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'cleaning_clean_empty_values',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'clean_languages',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Empty languages to null', // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Change language codes', // @translate
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
                    'label' => 'From', // @translate
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
                    'label' => 'To', // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'deduplicate_values',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Deduplicate values', // @translate
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

    protected function appendFieldsetCreateValues(): self
    {
        $this
            ->add([
                'name' => 'create_values',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Add'), // @translate
                ],
                'attributes' => [
                    'id' => 'create_values',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('create_values');
        // The values are managed by js like in the resource form.
        $fieldset
            ->add([
                'name' => 'properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Add property', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'create_values_properties',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select property…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'values',
                'type' => Element\Hidden::class,
                'attributes' => [
                    'id' => 'create_values_values',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    /**
     * Group the processes that update the existing values of properties.
     *
     * The values to modify are selected first, then each operation is enabled
     * with a checkbox. The operations are mapped to the processes on save.
     *
     * @see \BulkEdit\Module::expandModify()
     */
    protected function appendFieldsetModify(): self
    {
        $this
            ->add([
                'name' => 'modify',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Modify'), // @translate
                ],
                'attributes' => [
                    'id' => 'modify',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->appendFieldsetExplode()
            ->appendFieldsetConvert()
            ->appendFieldsetOrderValues()
            ->appendFieldsetPropertiesVisibility();

        $modify = $this->get('modify');

        // The values to modify are selected with the elements of the visibility.
        $visibility = $this->get('properties_visibility');
        foreach (['properties', 'datatypes', 'languages', 'contains'] as $name) {
            $element = $visibility->get($name);
            $visibility->remove($name);
            $modify->add($element->setAttribute('id', 'modify_' . $name));
        }
        $modify
            ->add([
                'name' => 'match',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Regex', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_match',
                ],
            ])
            ->add([
                'name' => 'is_public',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
                    'value_options' => [
                        '' => 'Any', // @translate
                        '1' => 'Public', // @translate
                        '0' => 'Not public', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'modify_is_public',
                    'value' => '',
                ],
            ]);
        $this->prepareFilterElements($modify);

        $text = (new Fieldset('replace'))
            ->add([
                'name' => 'value_part',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Part', // @translate
                    'value_options' => [
                        'value' => 'Value', // @translate
                        'uri' => 'Uri', // @translate
                        'uri_label' => 'Uri label', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'modify_replace_value_part',
                    'value' => 'value',
                ],
            ])
            ->add([
                'name' => 'action',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Operation', // @translate
                    'value_options' => [
                        '' => 'No operation', // @translate
                        'whole' => 'Replace the whole value', // @translate
                        'search' => 'Search and replace', // @translate
                        'unicode' => 'Fix encoding (Ã© to é)', // @translate
                        'filename' => 'Keep the file name only', // @translate
                        'remove' => 'Remove the value', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'modify_replace_action',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Search', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_replace_from',
                    'data-bulkedit-show' => 'action=search',
                ],
            ])
            ->add([
                'name' => 'match',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Comparison', // @translate
                    'value_options' => [
                        'raw' => 'Exact', // @translate
                        'raw_i' => 'Case insensitive', // @translate
                        'regex' => 'Regex', // @translate
                        'html' => 'With html entities', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'modify_replace_match',
                    'value' => 'raw',
                    'data-bulkedit-show' => 'action=search',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Replace by', // @translate
                    'info' => 'Placeholders, also for prepend and append: {resource_id}, {resource_url}, {resource_url_admin}, {resource_title}, or any term, like {dcterms:identifier}.', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_replace_to',
                    'data-bulkedit-show' => 'action=search whole',
                ],
            ])
            ->add([
                'name' => 'extension',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Extension', // @translate
                    'value_options' => [
                        'keep' => 'Keep', // @translate
                        'remove' => 'Remove', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'modify_replace_extension',
                    'value' => 'keep',
                    'data-bulkedit-show' => 'action=filename',
                ],
            ])
            ->add([
                'name' => 'prepend',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Prepend', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_replace_prepend',
                ],
            ])
            ->add([
                'name' => 'append',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Append', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_replace_append',
                ],
            ]);

        $language = (new Fieldset('language'))
            ->add([
                'name' => 'action',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Operation', // @translate
                    'value_options' => [
                        '' => 'No operation', // @translate
                        'set' => 'Set', // @translate
                        'remove' => 'Remove', // @translate
                        'change' => 'Change the code', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'modify_language_action',
                    'value' => '',
                ],
            ])
            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Language', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_language_language',
                    'class' => 'value-language active',
                    'data-bulkedit-show' => 'action=set',
                ],
            ])
            ->add([
                'name' => 'code_from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Current code', // @translate
                    'info' => 'Empty for the values without language.', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_language_code_from',
                    'data-bulkedit-show' => 'action=change',
                ],
            ])
            ->add([
                'name' => 'code_to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'New code', // @translate
                    'info' => 'Empty to remove the language.', // @translate
                ],
                'attributes' => [
                    'id' => 'modify_language_code_to',
                    'data-bulkedit-show' => 'action=change',
                ],
            ]);

        // Reuse the fieldsets of the processes, without the common elements.
        $explode = $this->get('explode');
        foreach (['properties', 'contains', 'match'] as $name) {
            $explode->remove($name);
        }
        $convert = $this->get('convert');
        foreach (['properties', 'contains'] as $name) {
            $convert->remove($name);
        }
        $orderValues = $this->get('order_values');
        $orderValues->remove('properties');
        $orderValues->get('languages')
            ->setOption('info', 'Only the filtered values are sorted, the other ones keep their position.'); // @translate
        $visibility->get('visibility')->setValueOptions([
            '' => '[No change]', // @translate
            '1' => 'Public', // @translate
            '0' => 'Not public', // @translate
        ]);
        foreach (['explode', 'convert', 'order_values', 'properties_visibility'] as $name) {
            $this->remove($name);
        }

        // The checkbox to enable each operation is its label.
        $operations = [
            'replace' => [$text, 'Modify the text'], // @translate
            'language' => [$language, 'Modify the language'], // @translate
            'explode' => [$explode, 'Explode the values'], // @translate
            'convert' => [$convert, 'Convert the data type'], // @translate
            'order_values' => [$orderValues, 'Sort by language'], // @translate
            'properties_visibility' => [$visibility, 'Modify the visibility'], // @translate
        ];
        foreach ($operations as $name => [$operation, $label]) {
            $elements = $operation->getElements();
            foreach (array_keys($elements) as $elementName) {
                $operation->remove($elementName);
            }
            $operation->add([
                'name' => 'enabled',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => $label,
                    'use_hidden_element' => false,
                ],
                'attributes' => [
                    'id' => 'modify_' . $name . '_enabled',
                    'class' => 'bulkedit-operation-enabled',
                ],
            ]);
            foreach ($elements as $element) {
                $operation->add($element);
            }
            $modify->add($operation->setLabel($this->translator->translate($label)));
        }

        return $this;
    }

    protected function appendFieldsetReplace(): self
    {
        $this
            ->add([
                'name' => 'replace',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Replace'), // @translate
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
                'name' => 'properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'value_part',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Part', // @translate
                    'value_options' => [
                        'value' => 'Value', // @translate
                        'uri' => 'Uri', // @translate
                        'uri_label' => 'Uri label', // @translate
                        'literal' => 'Literal', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'replace_value_part',
                    'value' => 'value',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Mode', // @translate
                    'value_options' => [
                        'raw' => 'Text', // @translate
                        'raw_i' => 'Text, case insensitive', // @translate
                        'html' => 'Text and html entities', // @translate
                        'regex' => 'Regex', // @translate
                        'unicode' => 'Fix encoding (Ã© to é)', // @translate
                        'basename' => 'Base name', // @translate
                        'filename' => 'Base name without extension', // @translate
                        'whole' => 'Whole value', // @translate
                        'remove' => 'Remove value', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'replace_mode',
                    'class' => 'bulkedit-replace-mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all"
                    // working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Search', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_from',
                    // This attribute is required to make "batch edit all"
                    // working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'By', // @translate
                    'info' => 'Placeholders, also for prepend and append: {resource_id}, {resource_url}, {resource_url_admin}, {resource_title}, or any term, like {dcterms:identifier}.', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_to',
                    // This attribute is required to make "batch edit all"
                    // working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'prepend',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Prepend', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_prepend',
                    // This attribute is required to make "batch edit all"
                    // working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'append',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Append', // @translate
                ],
                'attributes' => [
                    'id' => 'replace_append',
                    // This attribute is required to make "batch edit all"
                    // working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Add the language', // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
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
        ;
        return $this;
    }

    /**
     * Copy is the same than displace, except it does not remove source.
     */
    /**
     * Display the filters of a fieldset in the same way for all actions.
     *
     * An empty filter means all values.
     */
    protected function prepareFilterElements(Fieldset $fieldset): self
    {
        if ($fieldset->has('datatypes')) {
            $fieldset->get('datatypes')
                ->setEmptyOption('')
                ->setAttribute('data-placeholder', $this->translator->translate('All data types')); // @translate
        }
        if ($fieldset->has('languages')) {
            $fieldset->get('languages')->setAttribute('placeholder', $this->translator->translate('All languages')); // @translate
        }
        if ($fieldset->has('contains')) {
            $fieldset->get('contains')->setAttribute('placeholder', $this->translator->translate('Any value')); // @translate
        }
        if ($fieldset->has('match')) {
            $fieldset->get('match')->setAttribute('placeholder', '~^value$~i');
        }
        // The visibility of the copy and the displacement is a filter.
        if ($fieldset->has('visibility') && in_array($fieldset->getName(), ['copy', 'displace'])) {
            $fieldset->get('visibility')->setValueOptions([
                '' => 'Any', // @translate
                '1' => 'Public', // @translate
                '0' => 'Not public', // @translate
            ]);
        }
        return $this;
    }

    protected function appendFieldsetCopy(): self
    {
        $this
            ->add([
                'name' => 'copy',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Copy'), // @translate
                ],
                'attributes' => [
                    'id' => 'copy',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('copy');
        $fieldset
            ->add([
                'name' => 'from',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'From', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'copy_from',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'To', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'copy_to',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select property', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Data types', // @translate
                    'empty_option' => '[All data types]', // @translate
                ],
                'attributes' => [
                    'id' => 'copy_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select data types…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'languages',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Languages', // @translate
                    'value_separator' => ' ',
                ],
                'attributes' => [
                    'id' => 'copy_languages',
                    // 'class' => 'value-language active',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'contains',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Contains', // @translate
                ],
                'attributes' => [
                    'id' => 'copy_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'match',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Regex', // @translate
                ],
                'attributes' => [
                    'id' => 'copy_match',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'visibility',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
                    'value_options' => [
                        '1' => 'Public', // @translate
                        '0' => 'Not public', // @translate
                        '' => 'Any', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'copy_visibility',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatype',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'New data type (without conversion)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'copy_datatype',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select a data type…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'max_values',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Max values', // @translate
                ],
                'attributes' => [
                    'id' => 'copy_max_values',
                    'min' => '0',
                    'step' => '1',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        $this->prepareFilterElements($fieldset);

        return $this;
    }

    /**
     * Copy is the same than displace, except it does not remove source.
     */
    protected function appendFieldsetDisplace(): self
    {
        $this
            ->add([
                'name' => 'displace',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Displace'), // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'From', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'displace_from',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'To', // @translate
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
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Data types', // @translate
                    'empty_option' => '[All data types]', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select data types…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'languages',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Languages', // @translate
                    'value_separator' => ' ',
                ],
                'attributes' => [
                    'id' => 'displace_languages',
                    // 'class' => 'value-language active',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'contains',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Contains', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'match',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Regex', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_match',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'visibility',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
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
                'name' => 'datatype',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'New data type (without conversion)', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'displace_datatype',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select a data type…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'max_values',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Max values', // @translate
                ],
                'attributes' => [
                    'id' => 'displace_max_values',
                    'min' => '0',
                    'step' => '1',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        $this->prepareFilterElements($fieldset);

        return $this;
    }

    protected function appendFieldsetExplode(): self
    {
        $this
            ->add([
                'name' => 'explode',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Explode'), // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'separator',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Separator', // @translate
                    'info' => 'Use \n for newline, \r for carriage return, \t for tab. Use \\n for a literal \n.', // @translate
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
                    'label' => 'Contains', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'match',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Regex', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_match',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'max_values',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Max values', // @translate
                    'info' => 'From start, or from end with a negative number.', // @translate
                ],
                'attributes' => [
                    'id' => 'explode_max_values',
                    'step' => '1',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetMerge(): self
    {
        $this
            ->add([
                'name' => 'merge',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Merge as uri'), // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
                    'info' => 'The values are merged two by two, whatever order and initial data type. The number of values must be even and clean.', // @translate
                    'term_as_value' => true,
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'merge_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetConvert(): self
    {
        $this
            ->add([
                'name' => 'convert',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Convert'), // @translate
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
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'From', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_from',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-datatypes' => json_encode($this->dataTypesMain, 320),
                    'data-placeholder' => 'Select data type', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'to',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'To', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'convert_to',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select data type', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'literal_value',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Content', // @translate
                    'value_options' => [
                        'label_uri' => 'Label and uri', // @translate
                        'uri_label' => 'Uri and label', // @translate
                        'label_or_uri' => 'Label or uri', // @translate
                        'label' => 'Label only', // @translate
                        'uri' => 'Uri only', // @translate
                        'coordinates' => 'Geonames to coordinates', // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Text only', // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Only html/xml strings', // @translate
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
                'name' => 'resource_value_preprocess',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Identifier', // @translate
                    'value_options' => [
                        'full' => 'Whole value', // @translate
                        'basename' => 'Base name', // @translate
                        'filename' => 'Base name without extension', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'resource_value_preprocess',
                    'value' => 'full',
                    'data-info-datatype' => 'resource',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'resource_properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Identifier properties', // @translate
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'uri_extract_label',
                'type' => CommonElement\OptionalCheckbox::class,
                'options' => [
                    'label' => 'Extract label after uri', // @translate
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
                    'label' => 'Label', // @translate
                ],
                'attributes' => [
                    'id' => 'convert_uri_label',
                    'data-info-datatype' => 'uri',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'uri_language',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Language', // @translate
                    'value_options' => [
                        'eng' => 'English', // @translate
                        'fra' => 'French', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'convert_uri_language',
                    'data-info-datatype' => 'uri',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'uri_base_site',
                'type' => CommonElement\OptionalSiteSelect::class,
                'options' => [
                    'label' => 'Base url', // @translate
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
                    'label' => 'Contains', // @translate
                ],
                'attributes' => [
                    'id' => 'convert_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetOrderValues(): self
    {
        $this
            ->add([
                'name' => 'order_values',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Sort'), // @translate
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
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Languages first', // @translate
                    'info' => 'List the language you want to order before other values.', // @translate
                    'value_separator' => ' ',
                ],
                'attributes' => [
                    'id' => 'ordervalues_languages',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'properties',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => '[All properties]', // @translate
                    ],
                    'empty_option' => '',
                    'used_terms' => true,
                ],
                'attributes' => [
                    'id' => 'ordervalues_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetPropertiesVisibility(): self
    {
        $this
            ->add([
                'name' => 'properties_visibility',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Visibility'), // @translate
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
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Data types', // @translate
                    'empty_option' => '[All data types]', // @translate
                ],
                'attributes' => [
                    'id' => 'propvis_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select data types…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'languages',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Languages', // @translate
                    'value_separator' => ' ',
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
                    'label' => 'Contains', // @translate
                ],
                'attributes' => [
                    'id' => 'propvis_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetFillData(): self
    {
        $this
            ->add([
                'name' => 'fill_data',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Owner'), // @translate
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
                'type' => CommonElement\OptionalUserSelect::class,
                'options' => [
                    'label' => 'Owner', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        '0' => 'Remove owner', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'filldata_owner',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select a user…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        return $this;
    }

    /**
     * Adapted
     * @see \BulkEdit\Form\BulkEditFieldset::appendFieldsetFillValues()
     * @see \Urify\Form\UrifyForm::init()
     */
    protected function appendFieldsetFillValues(): self
    {
        // TODO All datatypes are managed to fill an uri from value through value suggest.

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
                    'label' => $this->translator->translate('Value Suggest'), // @translate
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
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Mode', // @translate
                    'value_options' => [
                        [
                            'value' => 'label_missing',
                            'label' => 'Fill missing labels', // @translate
                            'attributes' => [
                                'data-fill-main' => 'label',
                            ],
                        ],
                        [
                            'value' => 'label_all',
                            'label' => 'Refill all labels', // @translate
                            'attributes' => [
                                'data-fill-main' => 'label',
                            ],
                        ],
                        [
                            'value' => 'label_remove',
                            'label' => 'Remove labels', // @translate
                            'attributes' => [
                                'data-fill-main' => 'label',
                            ],
                        ],
                        [
                            'value' => 'uri_missing',
                            'label' => 'Fill missing uris', // @translate
                            'attributes' => [
                                'data-fill-main' => 'uri',
                            ],
                        ],
                        [
                            'value' => 'uri_all',
                            'label' => 'Refill all uris', // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
                    'term_as_value' => true,
                    'used_terms' => true,
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'fill_properties',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'note_uri',
                'type' => CommonElement\Note::class,
                'options' => [
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
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Data types', // @translate
                    'value_options' => array_merge([
                        [
                            'value' => 'all',
                            'label' => '[All data types]', // @translate
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
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'fill_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select data types…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatype',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Data type', // @translate
                    'info' => 'For literal or uri values.', // @translate
                    'empty_option' => '',
                    'value_options' => $datatypesVSAttrs,
                ],
                'attributes' => [
                    'id' => 'fill_datatype',
                    'class' => 'chosen-select',
                    'multiple' => false,
                    'data-placeholder' => 'Select a data type…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'language',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Language', // @translate
                ],
                'attributes' => [
                    'id' => 'fill_language',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'update_language',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Value language', // @translate
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
                'type' => CommonElement\OptionalCheckbox::class,
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

    protected function appendFieldsetRemove(): self
    {
        $this
            ->add([
                'name' => 'remove',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Remove'), // @translate
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
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'label' => 'Properties', // @translate
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
                    'data-placeholder' => 'Select properties…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'datatypes',
                'type' => CommonElement\DataTypeSelect::class,
                'options' => [
                    'label' => 'Data types', // @translate
                    'empty_option' => '[All data types]', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_datatypes',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'data-placeholder' => 'Select data types…', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'languages',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Languages', // @translate
                    'value_separator' => ' ',
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
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
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
                    'label' => 'Equals', // @translate
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
                    'label' => 'Contains', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_contains',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'match',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Regex', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_match',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'keep_max_values',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Values to keep', // @translate
                    'info' => 'From start, or from end with a negative number.', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_keep_max_values',
                    'min' => '0',
                    'step' => '1',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'max_values',
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Values to remove', // @translate
                    'info' => 'From start, or from end with a negative number.', // @translate
                ],
                'attributes' => [
                    'id' => 'remove_max_values',
                    'step' => '1',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        return $this;
    }

    protected function appendFieldsetThumbnail(): self
    {
        $this
            ->add([
                'name' => 'thumbnails',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Thumbnail'), // @translate
                ],
                'attributes' => [
                    'id' => 'thumbnails',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('thumbnails');
        $fieldset
            ->add([
                'name' => 'mode',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Mode', // @translate
                    'value_options' => [
                        'fill' => 'Attach to all', // @translate
                        'append' => 'Attach when no thumbnail', // @translate
                        'append_no_primary' => 'Attach when no primary media', // @translate
                        'append_no_primary_no_thumbnail' => 'Attach when no thumbnail nor primary media', // @translate
                        'replace' => 'Replace existing thumbnail', // @translate
                        'remove' => 'Remove this thumbnail', // @translate
                        'remove_primary' => 'Remove when primary media', // @translate
                        'delete' => 'Remove all', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'thumbnails_mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'asset',
                'type' => OmekaElement\Asset::class,
                'options' => [
                    'label' => 'Asset', // @translate
                ],
                'attributes' => [
                    'id' => 'thumbnails_asset',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetExplodeItem(): self
    {
        $this
            ->add([
                'name' => 'explode_item',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Explode by media'), // @translate
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
                'name' => 'mode',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Media metadata', // @translate
                    'info' => 'Check first in jobs and logs that there is no background process working on medias, for example data extraction or indexation.', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'append' => 'Append to item', // @translate
                        'update' => 'Replace item ones when set', // @translate
                        'replace' => 'Replace all item ones', // @translate
                        'none' => 'Keep in media', // @translate
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

    protected function appendFieldsetExplodePdf(): self
    {
        $this
            ->add([
                'name' => 'explode_pdf',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Explode pdf'), // @translate
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
                'name' => 'mode',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Pdf', // @translate
                    'info' => 'Create one jpeg by page for iiif viewers. Check first in jobs and logs that there is no background process working on medias, for example data extraction or indexation.', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'all' => 'All pdf', // @translate
                        'first' => 'First pdf', // @translate
                        'last' => 'Last pdf', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'explodepdf_mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'process',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Pages', // @translate
                    'value_options' => [
                        'all' => 'All pages', // @translate
                        'skip' => 'Missing pages', // @translate
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
                'type' => CommonElement\OptionalNumber::class,
                'options' => [
                    'label' => 'Resolution', // @translate
                    'info' => 'Generally 72, 96, 150, 300, 400 (default), 600 or more.', // @translate
                ],
                'attributes' => [
                    'id' => 'explodepdf_resolution',
                    'min' => '0',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'processor',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Processor', // @translate
                    'value_options' => [
                        'auto' => 'Auto', // @translate
                        'pdftoppm' => 'pdftoppm (poppler)', // @translate
                        'gs' => 'gs (ghostscript)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'explodepdf_processor',
                    'value' => 'auto',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'process_events',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Process events', // @translate
                    'info' => 'Derivatives, extraction, indexation… Disabled by default to avoid memory and speed issues.', // @translate
                    'value_options' => [
                        '0' => 'No', // @translate
                        '1' => 'Yes', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'explodepdf_process_events',
                    'value' => '0',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetRemoveMedia(): self
    {
        $this
            ->add([
                'name' => 'media_remove',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Remove'), // @translate
                ],
                'attributes' => [
                    'id' => 'media_remove',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('media_remove');
        $fieldset
            ->add([
                'name' => 'mode',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Media', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'media_type' => 'By media type', // @translate
                        'media_extension' => 'By extension', // @translate
                        // 'media_query' => 'Limited with query on media below', // @translate
                        'all' => 'All', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mediaremove_mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'mediatypes',
                'type' => CommonElement\MediaTypeSelect::class,
                'options' => [
                    'label' => 'Media types', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'mediaremove_mediatypes',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select media-types', // @translate
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'extensions',
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Extensions', // @translate
                    'value_separator' => ' ',
                ],
                'attributes' => [
                    'id' => 'mediaremove_extensions',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            /* // Too much risky.
            ->add([
                'type' => OmekaElement\Query::class,
                'name' => 'query',
                'options' => [
                    'label' => 'Media query', // @translate
                    'info' => 'Limit the media to remove (Warning: check your query)', // @translate
                    'query_resource_type' => 'media',
                    'query_partial_excludelist' => [],
                ],
                'attributes' => [
                    'id' => 'mediaremove_query',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            */
        ;

        return $this;
    }

    protected function appendFieldsetMediaOrder(): self
    {
        $this
            ->add([
                'name' => 'media_order',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Sort'), // @translate
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
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Sort by', // @translate
                    'value_options' => [
                        'simple' => [
                            'label' => 'Simple', // @translate
                            'options' => [
                                'title' => 'Title', // @translate
                                'source' => 'Source', // @translate
                                'basename' => 'Source base name', // @translate
                                'mediatype' => 'Media type', // @translate
                                'extension' => 'Extension', // @translate
                            ],
                        ],
                        'mediatype_first' => [
                            'label' => 'Media type first', // @translate
                            'options' => [
                                'mediatype/title' => 'Media type, title', // @translate
                                'mediatype/source' => 'Media type, source', // @translate
                                'mediatype/basename' => 'Media type, source base name', // @translate
                            ],
                        ],
                        'mediatype_after' => [
                            'label' => 'Media type last', // @translate
                            'options' => [
                                'title/mediatype' => 'Title, media type', // @translate
                                'source/mediatype' => 'Source, media type', // @translate
                                'basename/mediatype' => 'Source base name, media type', // @translate
                            ],
                        ],
                        'extension_first' => [
                            'label' => 'Extension first', // @translate
                            'options' => [
                                'extension/title' => 'Extension, title', // @translate
                                'extension/source' => 'Extension, source', // @translate
                                'extension/basename' => 'Extension, source base name', // @translate
                            ],
                        ],
                        'extension_after' => [
                            'label' => 'Extension last', // @translate
                            'options' => [
                                'title/extension' => 'Title, extension', // @translate
                                'source/extension' => 'Source, extension', // @translate
                                'basename/extension' => 'Source base name, extension', // @translate
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
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Media types first', // @translate
                    'value_separator' => ' ',
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
                'type' => CommonElement\ArrayText::class,
                'options' => [
                    'label' => 'Extensions first', // @translate
                    'value_separator' => ' ',
                ],
                'attributes' => [
                    'id' => 'mediaorder_extensions',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMediaHtml(): self
    {
        $this
            ->add([
                'name' => 'media_html',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('HTML'), // @translate
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
                'name' => 'mode',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Mode', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'raw' => 'Text', // @translate
                        'raw_i' => 'Text, case insensitive', // @translate
                        'html' => 'Text and html entities', // @translate
                        'regex' => 'Regex', // @translate
                        'unicode' => 'Fix encoding (Ã© to é)', // @translate
                        'remove' => 'Remove html', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'mediahtml_mode',
                    'class' => 'bulkedit-replace-mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Search', // @translate
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
                'name' => 'prepend',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Prepend', // @translate
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
                    'label' => 'Append', // @translate
                ],
                'attributes' => [
                    'id' => 'mediahtml_append',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMediaSource(): self
    {
        // This is similar to appendFieldsetReplace, but for technical reasons,
        // it is managed separately (the api cannot update media source).

        $this
            ->add([
                'name' => 'media_source',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Source'), // @translate
                ],
                'attributes' => [
                    'id' => 'media_source',
                    'class' => 'field-container',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        $fieldset = $this->get('media_source');
        $fieldset
            ->add([
                'name' => 'mode',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Mode', // @translate
                    'empty_option' => '',
                    'value_options' => [
                        'raw' => 'Text', // @translate
                        'raw_i' => 'Text, case insensitive', // @translate
                        'regex' => 'Regex', // @translate
                        'unicode' => 'Fix encoding (Ã© to é)', // @translate
                        'basename' => 'Base name', // @translate
                        'filename' => 'Base name without extension', // @translate
                        'remove' => 'Remove source', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'media_source_mode',
                    'class' => 'bulkedit-replace-mode',
                    'value' => '',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'from',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Search', // @translate
                ],
                'attributes' => [
                    'id' => 'media_source_from',
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
                    'id' => 'media_source_to',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'prepend',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Prepend', // @translate
                ],
                'attributes' => [
                    'id' => 'media_source_prepend',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'append',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Append', // @translate
                ],
                'attributes' => [
                    'id' => 'media_source_append',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);
        return $this;
    }

    protected function appendFieldsetMediaType(): self
    {
        $this
            ->add([
                'name' => 'media_type',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Media type'), // @translate
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
                    'label' => 'From', // @translate
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
                    'label' => 'To', // @translate
                ],
                'attributes' => [
                    'id' => 'mediatype_to',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ]);

        return $this;
    }

    protected function appendFieldsetMediaVisibility(): self
    {
        $this
            ->add([
                'name' => 'media_visibility',
                'type' => Fieldset::class,
                'options' => [
                    'label' => $this->translator->translate('Visibility'), // @translate
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
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Visibility', // @translate
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
                'type' => CommonElement\MediaTypeSelect::class,
                'options' => [
                    'label' => 'Media types', // @translate
                    'empty_option' => 'All media types', // @translate
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
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Ingesters', // @translate
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
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Renderers', // @translate
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
