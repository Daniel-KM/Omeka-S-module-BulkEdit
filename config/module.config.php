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
    'controllers' => [
        'factories' => [
            'BulkEdit\Controller\Admin\Index' => Service\Controller\Admin\IndexControllerFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'bulk-edit-preview' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/bulk-edit/preview',
                            'defaults' => [
                                '__NAMESPACE__' => 'BulkEdit\Controller\Admin',
                                'controller' => 'Index',
                                'action' => 'preview',
                            ],
                        ],
                    ],
                ],
            ],
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
        'Preview', // @translate
        'Preview the updated resources', // @translate
        'The actions on the values are processed without saving, except Value Suggest, the cleaning and the processes on media.', // @translate
        'Public', // @translate
        'Private', // @translate
        'Loading…', // @translate
        'An error occurred.', // @translate
        'No value is updated in the {count} checked resources.', // @translate
        'Property', // @translate
        'Before', // @translate
        'After', // @translate
        'Resources {from}-{to} checked on {total}.', // @translate
        'Previous', // @translate
        'Next', // @translate
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
