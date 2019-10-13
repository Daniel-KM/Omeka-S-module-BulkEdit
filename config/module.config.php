<?php
namespace BulkEdit;

return [
    'form_elements' => [
        'factories' => [
            Form\BulkEditFieldset::class => Service\Form\BulkEditFieldsetFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'trimValues' => Service\ControllerPlugin\TrimValuesFactory::class,
            'deduplicateValues' => Service\ControllerPlugin\DeduplicateValuesFactory::class,
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
        'Media HTML', // @translate
        'Order values', // @translate
        'Replace literal values', // @translate
        'Visibility of values', // @translate
    ],
    'bulkedit' => [
    ],
];
