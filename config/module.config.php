<?php declare(strict_types=1);

namespace BulkEdit;

return [
    'service_manager' => [
        'factories' => [
            'BulkEdit' => Service\Stdlib\BulkEditFactory::class,
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'formNote' => Form\View\Helper\FormNote::class,
        ],
        'delegators' => [
            'Laminas\Form\View\Helper\FormElement' => [
                __NAMESPACE__ => Service\Delegator\FormElementDelegatorFactory::class,
            ],
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\Note::class => Form\Element\Note::class,
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
                'type' => 'gettext',
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
        'The actions are processed in the order of the form. Be careful when mixing them.', // @translate
        'To convert values to/from mapping markers, use module DataTypeGeometry.', // @translate
        'Processes that manage files and remote data can be slow, so it is recommended to process it in background with "batch edit all", not "batch edit selected".', // @translate
    ],
    'bulkedit' => [
        'settings' => [
            'bulkedit_deduplicate_on_save' => false,
        ],
    ],
];
