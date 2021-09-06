<?php declare(strict_types=1);

namespace BulkEdit;

return [
    'form_elements' => [
        'factories' => [
            Form\BulkEditFieldset::class => Service\Form\BulkEditFieldsetFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'cleanLanguageCodes' => Service\ControllerPlugin\CleanLanguageCodesFactory::class,
            'cleanLanguages' => Service\ControllerPlugin\CleanLanguagesFactory::class,
            'deduplicateValues' => Service\ControllerPlugin\DeduplicateValuesFactory::class,
            'specifyDatatypes' => Service\ControllerPlugin\SpecifyDatatypesFactory::class,
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
    ],
];
