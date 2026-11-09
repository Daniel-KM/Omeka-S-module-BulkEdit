<?php declare(strict_types=1);

namespace BulkEdit;

return [
    'service_manager' => [
        'factories' => [
            'BulkEdit' => Service\Stdlib\BulkEditFactory::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'bulkedit_local' => Service\Media\Ingester\LocalFileFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\BulkEditFieldset::class => Service\Form\BulkEditFieldsetFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'cleanEmptyValues' => Service\ControllerPlugin\CleanEmptyValuesFactory::class,
            'cleanLanguageCodes' => Service\ControllerPlugin\CleanLanguageCodesFactory::class,
            'cleanLanguages' => Service\ControllerPlugin\CleanLanguagesFactory::class,
            'deduplicateValues' => Service\ControllerPlugin\DeduplicateValuesFactory::class,
            'specifyDataTypeResources' => Service\ControllerPlugin\SpecifyDataTypeResourcesFactory::class,
            'trimValues' => Service\ControllerPlugin\TrimValuesFactory::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => \Laminas\I18n\Translator\Loader\Gettext::class,
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Batch edit', // @translate
        'Advanced', // @translate
        'Expand', // @translate
        'Collapse', // @translate
        'Options', // @translate
        'Values to modify', // @translate
        'Filter the values', // @translate
        'Operations, applied in this order', // @translate
        'Resource id', // @translate
        'The actions are processed in the order of the form. Be careful when mixing them.', // @translate
        'To convert values to/from mapping markers, use module DataTypeGeometry.', // @translate
        'Slow process, always run in background.', // @translate
        'Please enter a valid language tag', // @translate
    ],
    'bulkedit' => [
        'settings' => [
            'bulkedit_deduplicate_on_save' => false,
        ],
    ],
];
