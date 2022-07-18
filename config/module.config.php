<?php declare(strict_types=1);

namespace BulkEdit;

return [
    'controller_plugins' => [
        'factories' => [
            'cleanLanguageCodes' => Service\ControllerPlugin\CleanLanguageCodesFactory::class,
            'cleanLanguages' => Service\ControllerPlugin\CleanLanguagesFactory::class,
            'deduplicateValues' => Service\ControllerPlugin\DeduplicateValuesFactory::class,
            'specifyDatatypes' => Service\ControllerPlugin\SpecifyDatatypesFactory::class,
            'trimValues' => Service\ControllerPlugin\TrimValuesFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\Element\OptionalSelect::class => Form\Element\OptionalSelect::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\BulkEditFieldset::class => Service\Form\BulkEditFieldsetFactory::class,
            Form\Element\OptionalPropertySelect::class => Service\Form\Element\OptionalPropertySelectFactory::class,
            Form\Element\DataTypeSelect::class => Service\Form\Element\DataTypeSelectFactory::class,
        ],
        'aliases' => [
            // Use aliases to keep core keys.
            'Omeka\Form\Element\DataTypeSelect' => Form\Element\DataTypeSelect::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Advanced bulk edit', // @translate
        'Cleaning', // @translate
        'Convert datatype', // @translate
        'Displace values', // @translate
        'Explode values', // @translate
        'Fill values', // @translate
        'Media HTML', // @translate
        'Media types', // @translate
        'Order values', // @translate
        'Replace literal values', // @translate
        'The actions are processed in the order of the form. Be careful when mixing them.', // @translate
        'Visibility of values', // @translate
    ],
    'bulkedit' => [
        'settings' => [
            'bulkedit_deduplicate_on_save' => false,
        ],
    ],
];
