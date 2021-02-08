<?php declare(strict_types=1);

namespace BulkEdit;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use BulkEdit\Form\BulkEditFieldset;
use Generic\AbstractModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\Message;

/**
 * BulkEdit
 *
 * Improve the bulk edit process with new features.
 *
 * @copyright Daniel Berthereau, 2018-2021
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $adapters = [
            \Omeka\Api\Adapter\ItemAdapter::class,
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            \Omeka\Api\Adapter\MediaAdapter::class,
        ];
        foreach ($adapters as $adapter) {
            $sharedEventManager->attach(
                $adapter,
                'api.create.pre',
                [$this, 'handleResourceProcessPre']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.update.pre',
                [$this, 'handleResourceProcessPre']
            );

            $sharedEventManager->attach(
                $adapter,
                'api.preprocess_batch_update',
                [$this, 'handleResourceBatchUpdatePreprocess']
            );
            // Batch update is designed to do the same process to all resources,
            // but BulkEdit needs to check each data separately.
            $sharedEventManager->attach(
                $adapter,
                'api.update.pre',
                [$this, 'handleResourceUpdatePreBatchUpdate']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.batch_update.post',
                [$this, 'handleResourceBatchUpdatePost']
            );
        }

        // Special listener to manage media html from items.
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.batch_update.pre',
            [$this, 'handleResourceBatchUpdatePre']
        );

        $sharedEventManager->attach(
            '*',
            'view.batch_edit.before',
            [$this, 'viewBatchEditBefore']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_elements',
            [$this, 'formAddElementsResourceBatchUpdateForm']
        );
        $sharedEventManager->attach(
            \Omeka\Form\ResourceBatchUpdateForm::class,
            'form.add_input_filters',
            [$this, 'formAddInputFiltersResourceBatchUpdateForm']
        );
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $options = [
            'listDataTypesForSelect' => $this->listDataTypesForSelect(),
        ];
        $fieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(BulkEditFieldset::class, $options);
        $form->add($fieldset);
    }

    public function formAddInputFiltersResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Laminas\InputFilter\InputFilterInterface $inputFilter */
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter = $inputFilter->get('bulkedit');
        $inputFilter->get('cleaning')
            ->add([
                'name' => 'clean_language_codes_properties',
                'required' => false,
            ]);
        $inputFilter->get('replace')
            ->add([
                'name' => 'mode',
                'required' => false,
            ])
            ->add([
                'name' => 'remove',
                'required' => false,
            ])
            ->add([
                'name' => 'language_clear',
                'required' => false,
            ])
            ->add([
                'name' => 'properties',
                'required' => false,
            ]);
        $inputFilter->get('order_values')
            ->add([
                'name' => 'properties',
                'required' => false,
            ]);
        $inputFilter->get('properties_visibility')
            ->add([
                'name' => 'visibility',
                'required' => false,
            ])
            ->add([
                'name' => 'properties',
                'required' => false,
            ])
            ->add([
                'name' => 'datatypes',
                'required' => false,
            ]);
        $inputFilter->get('displace')
            ->add([
                'name' => 'from',
                'required' => false,
            ])
            ->add([
                'name' => 'to',
                'required' => false,
            ])
            ->add([
                'name' => 'datatypes',
                'required' => false,
            ])
            ->add([
                'name' => 'visibility',
                'required' => false,
            ]);
        $inputFilter->get('explode')
            ->add([
                'name' => 'properties',
                'required' => false,
            ]);
        $inputFilter->get('merge')
            ->add([
                'name' => 'properties',
                'required' => false,
            ]);
        $inputFilter->get('convert')
            ->add([
                'name' => 'from',
                'required' => false,
            ])
            ->add([
                'name' => 'to',
                'required' => false,
            ])
            ->add([
                'name' => 'properties',
                'required' => false,
            ])
            ->add([
                'name' => 'literal_value',
                'required' => false,
            ])
            ->add([
                'name' => 'resource_properties',
                'required' => false,
            ]);
        $inputFilter->get('media_html')
            ->add([
                'name' => 'mode',
                'required' => false,
            ])
            ->add([
                'name' => 'remove',
                'required' => false,
            ]);
    }

    /**
     * Process action on create/update.
     *
     * - preventive trim on property values.
     * - preventive deduplication on property values
     *
     * @param Event $event
     */
    public function handleResourceProcessPre(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        $trimUnicode = function ($v) {
            return preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', $v);
        };

        // Trimming.
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (!is_string($term) || mb_strpos($term, ':') === false || !is_array($values) || empty($values)) {
                continue;
            }
            $first = reset($values);
            if (empty($first['property_id'])) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $v = $trimUnicode($value['@value']);
                    $value['@value'] = mb_strlen($v) ? $v : null;
                }
                if (isset($value['@id'])) {
                    $v = $trimUnicode($value['@id']);
                    $value['@id'] = mb_strlen($v) ? $v : null;
                }
                if (isset($value['@language'])) {
                    $v = $trimUnicode($value['@language']);
                    $value['@language'] = mb_strlen($v) ? $v : null;
                }
                if (isset($value['o:label'])) {
                    $v = $trimUnicode($value['o:label']);
                    $value['o:label'] = mb_strlen($v) ? $v : null;
                }
            }
            unset($value);
        }
        unset($values);

        // Specifying.
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');
        $resourceNameToTypes = [
            'items' => 'resource:item',
            'media' => 'resource:media',
            'item_sets' => 'resource:itemset',
            'annotations' => 'resource:annotation',
        ];
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (!is_string($term) || mb_strpos($term, ':') === false || !is_array($values) || empty($values)) {
                continue;
            }
            $first = reset($values);
            if (empty($first['property_id'])) {
                continue;
            }
            foreach ($values as &$value) {
                if ($value['type'] === 'resource' || strpos($value['type'], 'resource') === 0) {
                    try {
                        $linkedResource = $api->read('resources', ['id' => $value['value_resource_id']], ['initialize' => false, 'finalize' => false])->getContent();
                        $linkedResourceName = $linkedResource->resourceName();
                        if (isset($resourceNameToTypes[$linkedResourceName])) {
                            $value['type'] = $resourceNameToTypes[$linkedResourceName];
                        }
                    } catch (\Exception $e) {
                    }
                }
            }
        }
        unset($values);

        // Deduplicating.
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (!is_string($term) || mb_strpos($term, ':') === false || !is_array($values) || empty($values)) {
                continue;
            }
            $first = reset($values);
            if (empty($first['property_id'])) {
                continue;
            }
            // Reorder all keys of all the values to simplify strict check.
            foreach ($values as &$value) {
                ksort($value);
            }
            unset($value);
            $test = [];
            foreach ($values as $key => $value) {
                if (in_array($value, $test, true)) {
                    unset($values[$key]);
                } else {
                    $test[$key] = $value;
                }
            }
        }
        unset($values);

        $request->setContent($data);
    }

    public function viewBatchEditBefore(Event $event): void
    {
        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/bulk-edit.css', 'BulkEdit'));
        $view->headScript()
            ->appendFile($assetUrl('js/bulk-edit.js', 'BulkEdit'), 'text/javascript', ['defer' => 'defer']);
    }

    public function handleResourceBatchUpdatePreprocess(Event $event): void
    {
        // Clean the request one time only (batch process is divided in bulks).

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $event->getParam('data');
        $bulkedit = $request->getValue('bulkedit');
        $data['bulkedit'] = $this->prepareProcesses($bulkedit);
        $event->setParam('data', $data);
    }

    public function handleResourceBatchUpdatePre(Event $event): void
    {
        $processes = $this->prepareProcesses();
        $request = $event->getParam('request');
        $this->updateResourcesPre($event->getTarget(), $request->getIds(), $processes);
    }

    public function handleResourceUpdatePreBatchUpdate(Event $event): void
    {
        /**
         * A batch update process is launched one to three times in the core,
         * at least with option "collectionAction" = "replace".
         * Batch updates are always partial,.
         * @see \Omeka\Job\BatchUpdate::perform()
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');
        if (!$request->getOption('isPartial') || $request->getOption('collectionAction') !== 'replace') {
            return;
        }

        $data = $request->getContent('data');
        if (empty($data['bulkedit'])) {
            return;
        }

        // Some batch processes are done globally via a single sql.
        $postProcesses = [
            // This process is different, because on another resource.
            'media_html' => null,
            // Post processes.
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ];
        $bulkedit = array_diff_key($data['bulkedit'], $postProcesses);
        if (!count($bulkedit)) {
            return;
        }

        /** @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter */
        $adapter = $event->getTarget();
        try {
            $resource = $adapter->findEntity($request->getId());
        } catch (\Exception $e) {
            return;
        }

        // Keep data that are currently to be updated, but not yet flushed.
        $representation = $adapter->getRepresentation($resource);
        $data = $this->updateResourcePre($adapter, $representation, $data, $bulkedit);
        $request->setContent($data);
    }

    /**
     * Process action on batch update (all or partial).
     *
     * Data may need to be reindexed if a module like Search is used, even if
     * the results are probably the same with a simple trimming.
     *
     * @param Event $event
     */
    public function handleResourceBatchUpdatePost(Event $event): void
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $ids = (array) $request->getIds();
        if (empty($ids)) {
            return;
        }

        $postProcesses = [
            // This process is different, because on another resource.
            // 'media_html' => null,
            // Post processes.
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ];
        $processes = $this->prepareProcesses();
        $bulkedit = array_intersect_key($processes, $postProcesses);
        if (!count($bulkedit)) {
            return;
        }

        $adapter = $event->getTarget();
        $this->updateValues($adapter, $ids, $bulkedit);
    }

    protected function prepareProcesses($bulkedit = null)
    {
        static $processes;
        if (!is_null($processes)) {
            return $processes;
        }

        if (empty($bulkedit)) {
            return [];
        }

        $processes = [
            'replace' => null,
            'order_values' => null,
            'properties_visibility' => null,
            'displace' => null,
            'explode' => null,
            'merge' => null,
            'convert' => null,
            'media_html' => null,
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ];

        $params = $bulkedit['replace'] ?? [];
        if (!empty($params['properties'])) {
            $from = $params['from'];
            $to = $params['to'];
            $remove = (bool) $params['remove'];
            $prepend = ltrim($params['prepend']);
            $append = rtrim($params['append']);
            $language = trim($params['language']);
            $languageClear = (bool) ($params['language_clear']);
            if (mb_strlen($from)
                || mb_strlen($to)
                || $remove
                || mb_strlen($prepend)
                || mb_strlen($append)
                || mb_strlen($language)
                || $languageClear
            ) {
                $processes['replace'] = [
                    'from' => $from,
                    'to' => $to,
                    'mode' => $params['mode'],
                    'remove' => $remove,
                    'prepend' => $prepend,
                    'append' => $append,
                    'language' => $language,
                    'language_clear' => $languageClear,
                    'properties' => $params['properties'],
                ];
            }
        }

        $params = $bulkedit['order_values'] ?? [];
        if (!empty($params['languages'])) {
            $languages = preg_replace('/[^a-zA-Z-]/', "\n", $params['languages']);
            $languages = array_filter(explode("\n", $languages));
            $properties = $params['properties'];
            if ($languages && $properties) {
                $processes['order_values'] = [
                    'languages' => $languages,
                    'properties' => $properties,
                ];
            }
        }

        $params = $bulkedit['properties_visibility'] ?? [];
        if (isset($params['visibility'])
            && $params['visibility'] !== ''
            && !empty($params['properties'])
        ) {
            $visibility = (int) (bool) $params['visibility'];
            $processes['properties_visibility'] = [
                'visibility' => $visibility,
                'properties' => $params['properties'],
                'datatypes' => $params['datatypes'],
                'languages' => $this->stringToList($params['languages']),
                'contains' => $params['contains'],
            ];
        }

        $params = $bulkedit['displace'] ?? [];
        if (!empty($params['from'])) {
            $to = $params['to'];
            if (mb_strlen($to)) {
                $processes['displace'] = [
                    'from' => $params['from'],
                    'to' => $to,
                    'datatypes' => $params['datatypes'],
                    'languages' => $this->stringToList($params['languages']),
                    'visibility' => $params['visibility'],
                    'contains' => $params['contains'],
                ];
            }
        }

        $params = $bulkedit['explode'] ?? [];
        if (!empty($params['properties'])) {
            $separator = $params['separator'];
            if (mb_strlen($separator)) {
                $processes['explode'] = [
                    'properties' => $params['properties'],
                    'separator' => $separator,
                    'contains' => $params['contains'],
                ];
            }
        }

        $params = $bulkedit['merge'] ?? [];
        if (!empty($params['properties'])) {
            $processes['merge'] = [
                'properties' => $params['properties'],
            ];
        }

        $params = $bulkedit['convert'] ?? [];
        if (!empty($params['from']) && !empty($params['to']) && !empty($params['properties'])) {
            $from = $params['from'];
            $to = $params['to'];
            if ($from !== $to) {
                $processes['convert'] = [
                    'from' => $from,
                    'to' => $to,
                    'properties' => $params['properties'],
                    'literal_value' => $params['literal_value'],
                    'resource_properties' => $params['resource_properties'],
                    'uri_label' => $params['uri_label'],
                ];
            }
        }

        $params = $bulkedit['media_html'] ?? [];
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $remove = isset($params['remove']) && (bool) $params['remove'];
        $prepend = isset($params['prepend']) ? ltrim($params['prepend']) : '';
        $append = isset($params['prepend']) ? rtrim($params['append']) : '';
        if (mb_strlen($from)
            || mb_strlen($to)
            || $remove
            || mb_strlen($prepend)
            || mb_strlen($append)
        ) {
            $processes['media_html'] = [
                'from' => $from,
                'to' => $to,
                'mode' => $params['mode'],
                'remove' => $remove,
                'prepend' => $prepend,
                'append' => $append,
            ];
        }

        // Direct processes.

        if (!empty($bulkedit['cleaning']['clean_language_codes'])) {
            $processes['clean_language_codes'] = [
                'from' => $bulkedit['cleaning']['clean_language_codes_from'] ?? null,
                'to' => $bulkedit['cleaning']['clean_language_codes_to'] ?? null,
                'properties' => $bulkedit['cleaning']['clean_language_codes_properties'] ?? null,
            ];
        }

        $processes['trim_values'] = empty($bulkedit['cleaning']['trim_values']) ? null : true;
        $processes['specify_datatypes'] = empty($bulkedit['cleaning']['specify_datatypes']) ? null : true;
        $processes['clean_languages'] = empty($bulkedit['cleaning']['clean_languages']) ? null : true;
        $processes['deduplicate_values'] = empty($bulkedit['cleaning']['deduplicate_values']) ? null : true;

        $processes = array_filter($processes);
        if (!count($processes)) {
            return [];
        }

        $this->getServiceLocator()->get('Omeka\Logger')->info(new Message(
            "Cleaned params used for bulk edit:\n%s", // @translate
            json_encode($processes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)
        ));

        return $processes;
    }

    protected function updateResourcesPre(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $processes
    ): void {
        // This process is specific, because not for current resources.
        if (!empty($processes['media_html'])) {
            $this->updateMediaHtmlForResources($adapter, $resourceIds, $processes['media_html']);
        }
    }

    protected function updateResourcePre(
        AbstractResourceEntityAdapter$adapter,
        AbstractResourceEntityRepresentation $resource,
        array $dataToUpdate,
        array $processes
    ): array {
        // It's simpler to process data as a full array.
        $data = json_decode(json_encode($resource), true);
        // Keep only properties values: a batch edit is partial and Bulk Edit
        // manages only properties.
        $properties = $this->getPropertyTerms();
        $data = array_intersect_key($data, array_flip($properties));

        // Keep data that may have been added during batch pre-process,
        $data = array_replace($data, $dataToUpdate);

        // TODO Remove toUpdate that is not used anymore.
        $toUpdate = false;
        foreach ($processes as $process => $params) switch ($process) {
            case 'replace':
                $this->updateValuesForResource($resource, $data, $toUpdate, $params);
                break;
            case 'order_values':
                $this->orderValuesForResource($resource, $data, $toUpdate, $params);
                break;
            case 'properties_visibility':
                $this->applyVisibilityForResourceValues($resource, $data, $toUpdate, $params);
                break;
            case 'displace':
                $this->displaceValuesForResource($resource, $data, $toUpdate, $params);
                break;
            case 'explode':
                $this->explodeValuesForResource($resource, $data, $toUpdate, $params);
                break;
            case 'merge':
                $this->mergeValuesForResource($resource, $data, $toUpdate, $params);
                break;
            case 'convert':
                $this->convertDatatypeForResource($resource, $data, $toUpdate, $params);
                break;
            default:
                break;
        }

        return $data;
    }

    protected function updateValues(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $processes
    ): void {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');

        // These processes are specific, because they use a direct sql.

        if (!empty($processes['trim_values'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
            $trimValues = $plugins->get('trimValues');
            $trimValues($resourceIds);
        }
        if (!empty($processes['specify_datatypes'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\SpecifyDatatypes $specifyDatatypes */
            $specifyDatatypes = $plugins->get('specifyDatatypes');
            $specifyDatatypes($resourceIds);
        }
        if (!empty($processes['clean_languages'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\CleanLanguages $cleanLanguages */
            $cleanLanguages = $plugins->get('cleanLanguages');
            $cleanLanguages($resourceIds);
        }
        if (!empty($processes['clean_language_codes'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\CleanLanguageCodes $cleanLanguages */
            $cleanLanguageCodes = $plugins->get('cleanLanguageCodes');
            $cleanLanguageCodes(
                $resourceIds,
                $processes['clean_language_codes']['from'] ?? null,
                $processes['clean_language_codes']['to'] ?? null,
                $processes['clean_language_codes']['properties'] ?? null
            );
        }
        if (!empty($processes['deduplicate_values'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues $deduplicateValues */
            $deduplicateValues = $plugins->get('deduplicateValues');
            $deduplicateValues($resourceIds);
        }
    }

    /**
     * Update values for resources.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function updateValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $from = $params['from'];
            $to = $params['to'];
            $mode = $params['mode'];
            $remove = $params['remove'];
            $prepend = $params['prepend'];
            $append = $params['append'];
            $languageClear = $params['language_clear'];
            $language = $languageClear ? '' : $params['language'];
            $fromProperties = $params['properties'];

            $processAllProperties = in_array('all', $fromProperties);
            $checkFrom = mb_strlen($from);

            if ($checkFrom) {
                switch ($mode) {
                    case 'regex':
                        // Check the validity of the regex.
                        // TODO Add the check of the validity of the regex in the form.
                        $isValidRegex = @preg_match($from, null) !== false;
                        if (!$isValidRegex) {
                            $from = '';
                        }
                        break;
                    case 'html':
                        $from = [
                            $from,
                            htmlentities($from, ENT_NOQUOTES | ENT_HTML5 | ENT_SUBSTITUTE),
                        ];
                        $to = [
                            $to,
                            $to,
                        ];
                        break;
                }
            }

            $settings = $params;
            $settings['from'] = $from;
            $settings['to'] = $to;
            $settings['languageClear'] = $languageClear;
            $settings['language'] = $language;
            $settings['fromProperties'] = $fromProperties;
            $settings['processAllProperties'] = $processAllProperties;
            $settings['checkFrom'] = $checkFrom;
        } else {
            extract($settings);
        }

        // Note: this is the original values.
        $properties = $processAllProperties
            ? array_keys($resource->values())
            : array_intersect($fromProperties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        if ($remove) {
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    if ($value['type'] !== 'literal') {
                        continue;
                    }
                    $toUpdate = true;
                    // Unsetting is done in last step.
                    $data[$property][$key]['@value'] = '';
                }
            }
        } elseif ($checkFrom) {
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    if ($value['type'] !== 'literal') {
                        continue;
                    }
                    switch ($mode) {
                        case 'regex':
                            $newValue = preg_replace($from, $to, $data[$property][$key]['@value']);
                            break;
                        case 'raw_i':
                            $newValue = str_ireplace($from, $to, $data[$property][$key]['@value']);
                            break;
                        case 'raw':
                        default:
                            $newValue = str_replace($from, $to, $data[$property][$key]['@value']);
                            break;
                    }
                    if ($value['@value'] === $newValue) {
                        continue;
                    }
                    $toUpdate = true;
                    $data[$property][$key]['@value'] = $newValue;
                }
            }
        }

        if (mb_strlen($prepend) || mb_strlen($append)) {
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    if ($value['type'] !== 'literal') {
                        continue;
                    }
                    $newValue = $prepend . $data[$property][$key]['@value'] . $append;
                    if ($value['@value'] === $newValue) {
                        continue;
                    }
                    $toUpdate = true;
                    $data[$property][$key]['@value'] = $newValue;
                }
            }
        }

        if ($languageClear || mb_strlen($language)) {
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    if ($value['type'] !== 'literal') {
                        continue;
                    }
                    $currentLanguage = $value['@language'] ?? '';
                    if ($currentLanguage === $language) {
                        continue;
                    }
                    $toUpdate = true;
                    $data[$property][$key]['@language'] = $language;
                }
            }
        }

        // Force trimming values and check if a value is empty to remove it.
        foreach ($properties as $property) {
            foreach ($data[$property] as $key => $value) {
                if ($value['type'] !== 'literal') {
                    continue;
                }
                if (!isset($data[$property][$key])) {
                    continue;
                }
                $data[$property][$key]['@value'] = trim($data[$property][$key]['@value']);
                if (!mb_strlen($data[$property][$key]['@value'])) {
                    $toUpdate = true;
                    unset($data[$property][$key]);
                }
            }
        }
    }

    /**
     * Order values in a list of properties.
     *
     * This feature is generally used for title, description and subjects.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function orderValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        $languages = $params['languages'];
        $forProperties = $params['properties'];
        if (empty($languages) || empty($forProperties)) {
            return;
        }

        $languages = array_fill_keys($languages, []);
        $processAllProperties = in_array('all', $forProperties);

        // Note: this is the original values.
        $properties = $processAllProperties
            ? array_keys($resource->values())
            : array_intersect($forProperties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        $toUpdate = true;

        foreach ($properties as $property) {
            // This two loops process is quicker with many languages.
            $values = $languages;
            foreach ($data[$property] as $value) {
                if ($value['type'] !== 'literal' || empty($value['@language'])) {
                    $values[''][] = $value;
                    continue;
                }
                $values[$value['@language']][] = $value;
            }
            $vals = [];
            foreach ($values as $vs) {
                $vals = array_merge($vals, $vs);
            }
            $data[$property] = $vals;
        }
    }

    /**
     * Set visibility to the specified properties of the specified resources.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function applyVisibilityForResourceValues(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $visibility = (int) (bool) $params['visibility'];
            $properties = $params['properties'];
            $datatypes = $params['datatypes'];
            $languages = $params['languages'];
            $contains = $params['contains'];

            $checkDatatype = !empty($datatypes) && !in_array('all', $datatypes);
            $checkLanguage = !empty($languages);
            $checkContains = (bool) mb_strlen($contains);

            $settings = $params;
            $settings['properties'] = $properties;
            $settings['visibility'] = $visibility;
            $settings['checkDatatype'] = $checkDatatype;
            $settings['checkLanguage'] = $checkLanguage;
            $settings['checkContains'] = $checkContains;
        } else {
            extract($settings);
        }

        if (empty($properties)) {
            return;
        }

        // Note: this is the original values.
        $processAllProperties = in_array('all', $properties);
        $properties = $processAllProperties
            ? array_keys($resource->values())
            : array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        foreach ($properties as $property) {
            foreach ($data[$property] as $key => $value) {
                $value += ['@language' => null, 'type' => null, '@value' => null];
                $currentVisibility = isset($value['is_public']) ? (int) $value['is_public'] : 1;
                if ($currentVisibility === $visibility) {
                    continue;
                }
                if ($checkDatatype && !in_array($value['type'], $datatypes)) {
                    continue;
                }
                if ($checkLanguage && !in_array($value['@language'], $languages)) {
                    continue;
                }
                if ($checkContains && strpos($value['@value'], $contains) === false) {
                    continue;
                }
                $toUpdate = true;
                $data[$property][$key]['is_public'] = $visibility;
            }
        }
    }

    /**
     * Displace values from a list of properties to another one.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function displaceValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $fromProperties = $params['from'];
            $toProperty = $params['to'];
            $datatypes = $params['datatypes'];
            $languages = $params['languages'];
            $visibility = $params['visibility'] === '' ? null : (int) (bool) $params['visibility'];
            $contains = $params['contains'];

            $to = array_search($toProperty, $fromProperties);
            if ($to !== false) {
                unset($fromProperties[$to]);
            }

            if (empty($fromProperties) || empty($toProperty)) {
                return;
            }

            $processAllProperties = in_array('all', $fromProperties);
            $checkDatatype = !empty($datatypes) && !in_array('all', $datatypes);
            $checkLanguage = !empty($languages);
            $checkVisibility = !is_null($visibility);
            $checkContains = (bool) mb_strlen($contains);

            $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');
            $toId = $api->searchOne('properties', ['term' => $toProperty], ['returnScalar' => 'id'])->getContent();

            $settings = $params;
            $settings['fromProperties'] = $fromProperties;
            $settings['toProperty'] = $toProperty;
            $settings['visibility'] = $visibility;
            $settings['to'] = $to;
            $settings['processAllProperties'] = $processAllProperties;
            $settings['checkDatatype'] = $checkDatatype;
            $settings['checkLanguage'] = $checkLanguage;
            $settings['checkVisibility'] = $checkVisibility;
            $settings['checkContains'] = $checkContains;
            $settings['toId'] = $toId;
        } else {
            extract($settings);
        }

        if (empty($fromProperties) || empty($toProperty)) {
            return;
        }

        // Note: this is the original values.
        if ($processAllProperties) {
            $properties = array_keys($resource->values());
            $to = array_search($toProperty, $properties);
            if ($to !== false) {
                unset($properties[$to]);
            }
        } else {
            $properties = array_intersect($fromProperties, array_keys($resource->values()));
        }
        if (empty($properties)) {
            return;
        }

        $toUpdate = true;

        foreach ($properties as $property) {
            if ($property === $toProperty) {
                continue;
            }
            foreach ($data[$property] as $key => $value) {
                $value += ['@language' => null, 'is_public' => 1, '@value' => null];
                if ($checkDatatype && !in_array($value['type'], $datatypes)) {
                    continue;
                }
                if ($checkLanguage && !in_array($value['@language'], $languages)) {
                    continue;
                }
                if ($checkVisibility && (int) $value['is_public'] !== $visibility) {
                    continue;
                }
                if ($checkContains && strpos($value['@value'], $contains) === false) {
                    continue;
                }
                $value['property_id'] = $toId;
                unset($value['property_label']);
                $data[$toProperty][] = $value;
                unset($data[$property][$key]);
            }
        }
    }

    /**
     * Explode values from a list of properties into multiple values.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function explodeValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $properties = $params['properties'];
            $separator = $params['separator'];
            $contains = $params['contains'];

            if (empty($properties) || !mb_strlen($separator)) {
                return;
            }

            $checkContains = (bool) mb_strlen($contains);

            $settings = $params;
            $settings['checkContains'] = $checkContains;
        } else {
            extract($settings);
        }

        if (empty($properties) || !mb_strlen($separator)) {
            return;
        }

        // Note: this is the original values.
        $properties = array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        $toUpdate = true;

        foreach ($properties as $property) {
            // This variable is used to keep order of original values.
            $values = [];
            foreach ($data[$property] as $value) {
                if ($value['type'] !== 'literal') {
                    $values[] = $value;
                    continue;
                }
                if ($checkContains && strpos($value['@value'], $contains) === false) {
                    $values[] = $value;
                    continue;
                }
                $vs = array_filter(array_map('trim', explode($separator, $value['@value'])), 'strlen');
                if (empty($vs)) {
                    continue;
                }
                $explodedValue = $value;
                foreach ($vs as $v) {
                    $explodedValue['@value'] = $v;
                    $values[] = $explodedValue;
                }
            }
            $data[$property] = $values;
        }
    }

    /**
     * Merge values from a list of properties into one.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function mergeValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $properties = $params['properties'];

            if (empty($properties)) {
                return;
            }

            $settings = $params;
        } else {
            extract($settings);
        }

        if (empty($properties)) {
            return;
        }

        // Note: this is the original values.
        $properties = array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        foreach ($properties as $property) {
            // Skip properties with an odd number of values.
            if (!count($data[$property]) || count($data[$property]) % 2 !== 0) {
                continue;
            }

            // First loop to create pairs.
            $pairs = [];
            foreach (array_values($data[$property]) as $key => $value) {
                if ($key % 2 === 1) {
                    --$key;
                }
                $pairs[$key][] = $value;
            }

            // Second loop to check values.
            foreach ($pairs as $pair) {
                // Check if values are two uri.
                if ($pair[0]['type'] === 'uri' && $pair[1]['type'] === 'uri') {
                    continue 2;
                }

                // When they are uri, check if the value has already a url and a label.
                if (($pair[0]['type'] === 'uri' && mb_strlen($pair[0]['@id']) && isset($pair[0]['o:label']) && mb_strlen($pair[0]['o:label']))
                    || ($pair[1]['type'] === 'uri' && mb_strlen($pair[1]['@id']) && isset($pair[1]['o:label']) && mb_strlen($pair[1]['o:label']))
                ) {
                    continue 2;
                }

                $mainValueA = $pair[0]['type'] === 'uri' ? $pair[0]['@id'] : $pair[0]['@value'];
                $mainValueB = $pair[1]['type'] === 'uri' ? $pair[1]['@id'] : $pair[1]['@value'];

                // There should be one and only one url unless they are the same.
                $isUrlA = mb_strpos($mainValueA, 'http://') === 0 || mb_strpos($mainValueA, 'https://') === 0;
                $isUrlB = mb_strpos($mainValueB, 'http://') === 0 || mb_strpos($mainValueB, 'https://') === 0;
                if ($isUrlA && $isUrlB) {
                    if ($mainValueA !== $mainValueB) {
                        continue 2;
                    }
                } elseif (!$isUrlA && !$isUrlB) {
                    continue 2;
                }
            }

            if (empty($pairs)) {
                continue;
            }

            $toUpdate = true;

            // Third loop to update data.
            $data[$property] = [];
            foreach ($pairs as $pair) {
                $mainValueA = $pair[0]['type'] === 'uri' ? $pair[0]['@id'] : $pair[0]['@value'];
                $mainValueB = $pair[1]['type'] === 'uri' ? $pair[1]['@id'] : $pair[1]['@value'];
                $isUrlA = mb_strpos($mainValueA, 'http://') === 0 || mb_strpos($mainValueA, 'https://') === 0;
                $data[$property][] = [
                    'type' => 'uri',
                    'property_id' => $pair[0]['property_id'],
                    'is_public' => (int) !empty($pair[0]['is_public']),
                    '@language' => null,
                    '@value' => null,
                    '@id' => $isUrlA ? $mainValueA : $mainValueB,
                    'o:label' => $isUrlA ? $mainValueB : $mainValueA,
                ];
            }
        }
    }

    /**
     * Convert datatype of a list of properties to another one.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $data
     * @param bool $toUpdate
     * @param array $params
     */
    protected function convertDatatypeForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        &$toUpdate,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
            $api = $plugins->get('api');
            $findResourcesFromIdentifiers = $plugins->has('findResourcesFromIdentifiers') ? $plugins->get('findResourcesFromIdentifiers') : null;
            $fromDatatype = $params['from'];
            $toDatatype = $params['to'];
            $properties = $params['properties'];
            $literalValue = $params['literal_value'];
            $resourceProperties = $params['resource_properties'];
            $uriLabel = mb_strlen($params['uri_label']) ? $params['uri_label'] : null;

            $settings = $params;
            $settings['api'] = $api;
            $settings['findResourcesFromIdentifiers'] = $findResourcesFromIdentifiers;
            $settings['fromDatatype'] = $fromDatatype;
            $settings['toDatatype'] = $toDatatype;
            $settings['properties'] = $properties;
            $settings['literalValue'] = $literalValue;
            $settings['resourceProperties'] = $resourceProperties;
            $settings['uriLabel'] = $uriLabel;
        } else {
            extract($settings);
        }

        if (($fromDatatype === $toDatatype)
            || !in_array($fromDatatype, ['literal', 'resource', 'uri'])
            || !in_array($toDatatype, ['literal', 'resource', 'uri'])
        ) {
            return;
        }

        // Note: this is the original values.
        $properties = array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        $fromTo = $fromDatatype . ' => ' . $toDatatype;
        if ($fromTo === 'literal => resource') {
            if (!$findResourcesFromIdentifiers) {
                $this->getServiceLocator()->get('Omeka\Logger')->warn(new Message(
                    'Conversion from data type "%s" to "%s" requires the module Bulk Import.', // @translate
                    'literal', 'resource'));
                return;
            }
            if (empty($resourceProperties)) {
                $this->getServiceLocator()->get('Omeka\Logger')->warn(new Message(
                    'To convert into the data type "resource", the properties where to find the identifier should be set.' // @translate
                ));
                return;
            }
        }

        $toUpdate = true;

        foreach ($properties as $property) {
            foreach ($data[$property] as $key => $value) {
                if ($value['type'] !== $fromDatatype) {
                    continue;
                }
                switch ($fromTo) {
                    case 'literal => resource':
                        $valueResourceId = $findResourcesFromIdentifiers($value['@value'], $resourceProperties);
                        if (!$valueResourceId) {
                            continue 2;
                        }
                        $value = ['property_id' => $value['property_id'], 'type' => 'resource', '@language' => null, '@value' => null, '@id' => null, 'o:label' => null, 'value_resource_id' => $valueResourceId];
                        break;

                    case 'literal => uri':
                        $value = ['property_id' => $value['property_id'], 'type' => 'uri', '@language' => null, '@value' => null, '@id' => $value['@value'], 'o:label' => $uriLabel];
                        break;

                    case 'resource => literal':
                        if (isset($value['display_title']) && strlen($value['display_title'])) {
                            $label = $value['display_title'];
                        } else {
                            $label = $api->searchOne($value['value_resource_id'])->getContent();
                            if (!$label) {
                                continue 2;
                            }
                            $label = $label->displayTitle();
                        }
                        $value = ['property_id' => $value['property_id'], 'type' => 'literal', '@language' => null, '@value' => $label, '@id' => null, 'o:label' => null];
                        break;

                    case 'resource => uri':
                        $this->getServiceLocator()->get('Omeka\Logger')->warn(new Message(
                            'Conversion from data type "%s" to "%s" is not managed.', // @translate
                                'resource', 'uri'));
                        return;

                    case 'uri => literal':
                        $currentUri = &$value['@id'];
                        $currentLabel = &$value['o:label'];
                        switch ($literalValue) {
                            case 'label_uri':
                                $label = strlen($currentLabel) ? $currentLabel . ' (' . $currentUri . ')' : $currentUri;
                                $value = ['property_id' => $value['property_id'], 'type' => 'literal', '@language' => null, '@value' => $label, '@id' => null, 'o:label' => null];
                                break;
                            case 'uri_label':
                                $label = strlen($currentLabel) ? $currentUri . ' (' . $currentLabel . ')' : $currentUri;
                                $value = ['property_id' => $value['property_id'], 'type' => 'literal', '@language' => null, '@value' => $label, '@id' => null, 'o:label' => null];
                                break;
                            case 'uri':
                                $value = ['property_id' => $value['property_id'], 'type' => 'literal', '@language' => null, '@value' => $currentUri, '@id' => null, 'o:label' => null];
                                break;
                            case 'label':
                                if (!strlen($currentLabel)) {
                                    continue 3;
                                }
                                $value = ['property_id' => $value['property_id'], 'type' => 'literal', '@language' => null, '@value' => $currentLabel, '@id' => null, 'o:label' => null];
                                break;
                        }
                        break;

                    case 'uri => resource':
                        $this->getServiceLocator()->get('Omeka\Logger')->warn(new Message(
                            'Conversion from data type "%s" to "%s" is not managed.', // @translate
                                'uri', 'resource'));
                        return;
                }
                $data[$property][$key] = $value;
            }
        }
    }

    /**
     * Update the html of a media of type html from items.
     *
     * @param ItemAdapter $adapter
     * @param array $resourceIds
     * @param array $params
     */
    protected function updateMediaHtmlForResources(
        ItemAdapter$adapter,
        array $resourceIds,
        array $params
    ): void {
        $from = $params['from'];
        $to = $params['to'];
        $mode = $params['mode'];
        $remove = $params['remove'];
        $prepend = $params['prepend'];
        $append = $params['append'];

        $checkFrom = mb_strlen($from);

        if ($checkFrom) {
            switch ($mode) {
                case 'regex':
                    // Check the validity of the regex.
                    // TODO Add the check of the validity of the regex in the form.
                    $isValidRegex = @preg_match($from, null) !== false;
                    if (!$isValidRegex) {
                        $from = '';
                    }
                    break;
                case 'html':
                    $from = [
                        $from,
                        htmlentities($from, ENT_NOQUOTES | ENT_HTML5 | ENT_SUBSTITUTE),
                    ];
                    $to = [
                        $to,
                        $to,
                    ];
                    break;
            }
        }

        /**
         * @var \Omeka\Api\Adapter\MediaAdapter $mediaAdapter
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Doctrine\ORM\EntityRepository $repository
         */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        foreach ($resourceIds as $resourceId) {
            $medias = $repository->findBy(['item' => $resourceId, 'ingester' => 'html']);
            if (!count($medias)) {
                continue;
            }

            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $media) {
                $mediaData = $media->getData();
                if (!is_array($mediaData) || empty($mediaData['html'])) {
                    continue;
                }
                $html = $mediaData['html'];
                $currentHtml = $html;

                if ($remove) {
                    $html = '';
                } elseif ($checkFrom) {
                    switch ($mode) {
                        case 'regex':
                            $html = preg_replace($from, $to, $html);
                            break;
                        case 'raw_i':
                            $html = str_ireplace($from, $to, $html);
                            break;
                        case 'raw':
                        default:
                            $html = str_replace($from, $to, $html);
                            break;
                    }
                }

                $html = $prepend . $html . $append;

                // Force trimming values and check if a value is empty to remove it.
                $html = trim($html);

                if ($currentHtml === $html) {
                    continue;
                }

                // TODO Purify html if set. Reindex full text, etc.

                $mediaData['html'] = $html;
                $media->setData($mediaData);
                $entityManager->persist($media);
                // No flush here.
            }
        }
    }

    /**
     * List datatypes for options.
     *
     * @see \Omeka\View\Helper\DataType::getSelect()
     *
     * @return array
     */
    protected function listDataTypesForSelect()
    {
        $dataTypeManager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
        $dataTypes = $dataTypeManager->getRegisteredNames();

        $options = [];
        $optgroupOptions = [];
        foreach ($dataTypes as $dataTypeName) {
            $dataType = $dataTypeManager->get($dataTypeName);
            $label = $dataType->getLabel();
            if ($optgroupLabel = $dataType->getOptgroupLabel()) {
                // Hash the optgroup key to avoid collisions when merging with
                // data types without an optgroup.
                $optgroupKey = md5($optgroupLabel);
                // Put resource data types before ones added by modules.
                $optionsVal = in_array($dataTypeName, ['resource', 'resource:item', 'resource:itemset', 'resource:media'])
                    ? 'options'
                    : 'optgroupOptions';
                if (!isset(${$optionsVal}[$optgroupKey])) {
                    ${$optionsVal}[$optgroupKey] = [
                        'label' => $optgroupLabel,
                        'options' => [],
                    ];
                }
                ${$optionsVal}[$optgroupKey]['options'][$dataTypeName] = $label;
            } else {
                $options[$dataTypeName] = $label;
            }
        }
        // Always put data types not organized in option groups before data
        // types organized within option groups.
        return array_merge($options, $optgroupOptions);
    }

    /**
     * Get all property terms.
     */
    public function getPropertyTerms(): array
    {
        static $properties;
        if (is_null($properties)) {
            /** @var \Doctrine\DBAL\Connection $connection */
            $connection = $this->getServiceLocator()->get('Omeka\Connection');
            $qb = $connection->createQueryBuilder();
            $qb
                ->select([
                    'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                    // Only the first select is needed, but some databases require
                    // "order by" or "group by" value to be in the select.
                    'vocabulary.id',
                    'property.id',
                ])
                ->from('property', 'property')
                ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
                ->orderBy('vocabulary.id', 'asc')
                ->addOrderBy('property.id', 'asc')
                ->addGroupBy('property.id')
            ;
            $stmt = $connection->executeQuery($qb);
            $properties = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $properties;
    }
}
