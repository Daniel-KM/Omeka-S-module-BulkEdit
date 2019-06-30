<?php
namespace BulkEdit;

return [
    'form_elements' => [
        'invokables' => [
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
    'bulkedit' => [
    ],
];
