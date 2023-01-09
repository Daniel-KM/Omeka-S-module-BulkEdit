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
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\Message;

/**
 * BulkEdit
 *
 * Improve the bulk edit process with new features.
 *
 * @copyright Daniel Berthereau, 2018-2023
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
            \Annotate\Api\Adapter\AnnotationAdapter::class,
        ];
        foreach ($adapters as $adapter) {
            // Trim, specify resource type, deduplicate on save.
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
            // Batch update via sql queries.
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

        // Extend the batch edit form via js.
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

        // Main settings.
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event): void
    {
        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $services = $this->getServiceLocator();
        $formElementManager = $services->get('FormElementManager');
        /** @var \BulkEdit\Form\BulkEditFieldset $fieldset */
        $fieldset = $formElementManager->get(BulkEditFieldset::class);
        $form->add($fieldset);
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
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $deduplicationOnSave = (bool) $settings->get('bulkedit_deduplicate_on_save');

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
            if (!is_array($first) || empty($first['property_id'])) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    // Some datatypes may have an array for value.
                    if (is_string($value['@value'])) {
                        $v = $trimUnicode($value['@value']);
                        $value['@value'] = mb_strlen($v) ? $v : null;
                    }
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
        /** @var \Omeka\Api\Manager $api */
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $resourceNameToTypes = [
            'items' => 'resource:item',
            'media' => 'resource:media',
            'item_sets' => 'resource:itemset',
            'annotations' => 'resource:annotation',
        ];
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (empty($values)
                || !is_array($values)
                || empty($term)
                || !is_string($term)
                || !$this->isPropertyTerm($term)
            ) {
                continue;
            }
            $first = reset($values);
            if (empty($first['property_id'])) {
                continue;
            }
            foreach ($values as &$value) {
                if (($value['type'] ?? null) === 'resource') {
                    try {
                        $linkedResourceName = $api->read('resources', ['id' => $value['value_resource_id']], [], ['initialize' => false, 'finalize' => false])
                            ->getContent()->getResourceName();
                        $value['type'] = $resourceNameToTypes[$linkedResourceName] ?? $value['type'];
                    } catch (\Exception $e) {
                    }
                }
            }
        }
        unset($values);

        // Deduplicating.
        if ($deduplicationOnSave) {
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
        }

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
        $bulkedit = $this->prepareProcesses($request->getValue('bulkedit'));
        if (empty($bulkedit)) {
            unset($data['bulkedit']);
        } else {
            $data['bulkedit'] = $bulkedit;
        }
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
         * Batch updates are always partial.
         *
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

        // Some batch processes are done globally via a single sql or on another
        // resource or cannot be done via api, so remove them from the standard
        // process.
        $postProcesses = [
            'media_html' => null,
            'media_type' => null,
            'media_visibility' => null,
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
     * Process action on batch update (all or partial) via direct sql.
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

        if ($request->getResource() === 'media') {
            $postProcesses = [
                'media_html' => null,
                'media_type' => null,
                'media_visibility' => null,
            ];
        } else {
            $postProcesses = [];
        }

        $postProcesses = array_merge($postProcesses, [
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ]);
        $processes = $this->prepareProcesses();
        $bulkedit = array_intersect_key($processes, $postProcesses);
        if (!count($bulkedit)) {
            return;
        }

        $adapter = $event->getTarget();
        $this->updateViaSql($adapter, $ids, $bulkedit);
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
            'displace' => null,
            'explode' => null,
            'merge' => null,
            'convert' => null,
            'order_values' => null,
            'properties_visibility' => null,
            'fill_data' => null,
            'fill_values' => null,
            'remove' => null,
            'media_html' => null,
            'media_type' => null,
            'media_visibility' => null,
            // Cleaning is done separately.
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

        $params = $bulkedit['displace'] ?? [];
        if (!empty($params['from'])) {
            $to = $params['to'];
            if (mb_strlen($to)) {
                $processes['displace'] = [
                    'from' => $params['from'],
                    'to' => $to,
                    'datatypes' => $params['datatypes'] ?: [],
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
        if (!empty($params['from'])
            && !empty($params['to'])
            && !empty($params['properties'])
            && $params['from'] !== $params['to']
        ) {
            $processes['convert'] = [
                'from' => $params['from'],
                'to' => $params['to'],
                'properties' => $params['properties'],
                'literal_value' => $params['literal_value'],
                'literal_extract_html_text' => $params['literal_extract_html_text'],
                'literal_html_only_tagged_string' => $params['literal_html_only_tagged_string'],
                'resource_properties' => $params['resource_properties'],
                'uri_extract_label' => !empty($params['uri_extract_label']),
                'uri_label' => $params['uri_label'],
                'uri_base_site' => $params['uri_base_site'],
                'contains' => $params['contains'],
            ];
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
                'datatypes' => $params['datatypes'] ?: [],
                'languages' => $this->stringToList($params['languages']),
                'contains' => $params['contains'],
            ];
        }

        $params = $bulkedit['fill_data'] ?? [];
        if (array_key_exists('owner', $params) && is_numeric($params['owner'])) {
            $processes['fill_data'] = [
                'owner' => (int) $params['owner'] ?: null,
            ];
        }

        $params = $bulkedit['fill_values'] ?? [];
        if (!empty($params['mode'])
            && in_array($params['mode'], ['label_missing', 'label_all', 'label_remove', 'uri_missing', 'uri_all'])
            && !empty($params['properties'])
        ) {
            $processes['fill_values'] = [
                'mode' => $params['mode'],
                'properties' => $params['properties'],
                'datatypes' => $params['datatypes'],
                'datatype' => $params['datatype'],
                'language' => $params['language'],
                'update_language' => $params['update_language'],
                'featured_subject' => (bool) $params['featured_subject'],
            ];
            // TODO Use a job only to avoid to fetch the same values multiple times or prefill values.
            // $this->preFillValues($processes['fill_values']);
        }

        $params = $bulkedit['remove'] ?? [];
        if (!empty($params['properties'])) {
            $processes['remove'] = [
                'properties' => $params['properties'],
                'datatypes' => $params['datatypes'] ?? [],
                'languages' => $this->stringToList($params['languages']),
                'visibility' => $params['visibility'],
                'contains' => $params['contains'],
            ];
        }

        $params = $bulkedit['media_html'] ?? [];
        $from = $params['from'] ?? '';
        $to = $params['to'] ?? '';
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

        $params = $bulkedit['media_type'] ?? [];
        $from = $params['from'] ?? '';
        $to = $params['to'] ?? '';
        if (mb_strlen(trim($from))
            && mb_strlen(trim($to))
            && preg_match('~^(application|audio|font|example|image|message|model|multipart|text|video|x-[\w-]+)/([\w\.+-]+)(;[\w\.+-]+=[\w\.+-]+){0,3}$~', strtolower(trim($to)))
        ) {
            $processes['media_type'] = [
                'from' => strtolower(trim($from)),
                'to' => strtolower(trim($to)),
            ];
        }

        $params = $bulkedit['media_visibility'] ?? [];
        if (isset($params['visibility'])
            && $params['visibility'] !== ''
        ) {
            $visibility = (int) (bool) $params['visibility'];
            $processes['media_visibility'] = [
                'visibility' => $visibility,
                'media_types' => $params['media_types'] ?: [],
                'ingesters' => $params['ingesters'] ?: [],
                'renderers' => $params['renderers'] ?: [],
            ];
        }

        // Direct processes.

        if (!empty($bulkedit['cleaning']['clean_language_codes'])
            // A property or "all" is required to avoid to fill all properties.
            && !empty($bulkedit['cleaning']['clean_language_codes_properties'])
        ) {
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
        if ($adapter instanceof ItemAdapter) {
            if (!empty($processes['media_html'])) {
                $this->updateMediaHtmlForResources($adapter, $resourceIds, $processes['media_html']);
            }
            if (!empty($processes['media_type'])) {
                $this->updateMediaTypeForResources($adapter, $resourceIds, $processes['media_type']);
            }
            if (!empty($processes['media_visibility'])) {
                $this->updateMediaVisibilityForResources($adapter, $resourceIds, $processes['media_visibility']);
            }
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

        // Keep data that may have been added during batch pre-process.
        $data = array_replace($data, $dataToUpdate);

        // Note: $data is passed by reference to each process.
        foreach ($processes as $process => $params) switch ($process) {
            case 'replace':
                $this->updateValuesForResource($resource, $data, $params);
                break;
            case 'displace':
                $this->displaceValuesForResource($resource, $data, $params);
                break;
            case 'explode':
                $this->explodeValuesForResource($resource, $data, $params);
                break;
            case 'merge':
                $this->mergeValuesForResource($resource, $data, $params);
                break;
            case 'convert':
                $this->convertDatatypeForResource($resource, $data, $params);
                break;
            case 'order_values':
                $this->orderValuesForResource($resource, $data, $params);
                break;
            case 'properties_visibility':
                $this->applyVisibilityForResourceValues($resource, $data, $params);
                break;
            case 'fill_data':
                $this->fillDataForResource($resource, $data, $params);
                break;
            case 'fill_values':
                $this->fillValuesForResource($resource, $data, $params);
                break;
            case 'remove':
                $this->removeValuesForResource($resource, $data, $params);
                break;
            default:
                break;
        }

        return $data;
    }

    protected function updateViaSql(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $processes
    ): void {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');

        // These processes are specific, because they use a direct sql.

        foreach (array_filter($processes) as $process => $params) switch ($process) {
            case 'trim_values':
                /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
                $trimValues = $plugins->get('trimValues');
                $trimValues($resourceIds);
                break;
            case 'specify_datatypes':
                /** @var \BulkEdit\Mvc\Controller\Plugin\SpecifyDatatypes $specifyDatatypes */
                $specifyDatatypes = $plugins->get('specifyDatatypes');
                $specifyDatatypes($resourceIds);
                break;
            case 'clean_languages':
                /** @var \BulkEdit\Mvc\Controller\Plugin\CleanLanguages $cleanLanguages */
                $cleanLanguages = $plugins->get('cleanLanguages');
                $cleanLanguages($resourceIds);
                break;
            case 'clean_language_codes':
                /** @var \BulkEdit\Mvc\Controller\Plugin\CleanLanguageCodes $cleanLanguages */
                $cleanLanguageCodes = $plugins->get('cleanLanguageCodes');
                $cleanLanguageCodes(
                    $resourceIds,
                    $params['from'] ?? null,
                    $params['to'] ?? null,
                    $params['properties'] ?? null
                );
                break;
            case 'deduplicate_values':
                /** @var \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues $deduplicateValues */
                $deduplicateValues = $plugins->get('deduplicateValues');
                $deduplicateValues($resourceIds);
                break;
            case 'media_html':
                $this->updateMediaHtmlForResources($adapter, $resourceIds, $params);
                break;
            case 'media_type':
                $this->updateMediaTypeForResources($adapter, $resourceIds, $params);
                break;
            case 'media_visibility':
                $this->updateMediaVisibilityForResources($adapter, $resourceIds, $params);
                break;
            default:
                break;
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
                        $isValidRegex = @preg_match($from, '') !== false;
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
                    unset($data[$property][$key]);
                }
            }
        }
    }

    /**
     * Displace values from a list of properties to another one.
     */
    protected function displaceValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $fromProperties = $params['from'];
            $toProperty = $params['to'];
            $datatypes = array_filter($params['datatypes'] ?? []);
            $languages = $params['languages'];
            $visibility = $params['visibility'] === '' ? null : (int) (bool) $params['visibility'];
            $contains = (string) $params['contains'];

            $to = array_search($toProperty, $fromProperties);
            if ($to !== false) {
                unset($fromProperties[$to]);
            }

            if (empty($fromProperties) || empty($toProperty)) {
                return;
            }

            $processAllProperties = in_array('all', $fromProperties);
            $checkDatatype = !empty($datatypes);
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
     */
    protected function explodeValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $properties = $params['properties'];
            $separator = $params['separator'];
            $contains = (string) $params['contains'];

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
     */
    protected function mergeValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
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
     */
    protected function convertDatatypeForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $services = $this->getServiceLocator();
            $plugins = $services->get('ControllerPluginManager');
            $api = $plugins->get('api');
            $logger = $services->get('Omeka\Logger');
            $findResourcesFromIdentifiers = $plugins->has('findResourcesFromIdentifiers') ? $plugins->get('findResourcesFromIdentifiers') : null;
            $fromDatatype = $params['from'];
            $toDatatype = $params['to'];
            $properties = $params['properties'];
            $literalValue = $params['literal_value'];
            $literalExtractHtmlText = !empty($params['literal_extract_html_text']);
            $literalHtmlOnlyTaggedString = !empty($params['literal_html_only_tagged_string']);
            $resourceProperties = $params['resource_properties'];
            $uriExtractLabel = !empty($params['uri_extract_label']);
            $uriLabel = strlen($params['uri_label']) ? $params['uri_label'] : null;

            $contains = (string) $params['contains'];

            $checkContains = (bool) mb_strlen($contains);

            /** @var \Omeka\DataType\DataTypeInterface $toDatatypeAdapter */
            $toDatatypeAdapter = $services->get('Omeka\DataTypeManager')->has($toDatatype)
                ? $services->get('Omeka\DataTypeManager')->get($toDatatype)
                : null;

            $mainDataType = $services->get('ViewHelperManager')->get('mainDataType');
            $fromDatatypeMain = $mainDataType($fromDatatype);
            $toDatatypeMain = $mainDataType($toDatatype);

            $fromToMain = $fromDatatypeMain . ' => ' . $toDatatypeMain;
            $fromTo = $fromDatatype . ' => ' . $toDatatype;

            $toDatatypeItem = $toDatatype === 'resource:item'
                || (substr($toDatatype, 0, 11) === 'customvocab' && $toDatatypeMain === 'resource');

            $processAllProperties = in_array('all', $properties);

            // TODO Use a more conventional way to get base url (domain + base path)?
            $uriBasePath = dirname($resource->apiUrl(), 3) . '/';

            $uriBaseResource = null;
            $uriBaseSite = empty($params['uri_base_site']) ? null : $params['uri_base_site'];
            $uriIsApi = $uriBaseSite === 'api';
            if ($uriBaseSite) {
                if ($uriIsApi) {
                    $uriBaseResource = $uriBasePath . 'api/';
                } else {
                    $siteSlug = is_numeric($uriBaseSite)
                        ? $api->searchOne('sites', ['id' => $uriBaseSite], ['initialize' => false, 'returnScalar' => 'slug'])->getContent()
                        : $uriBaseSite;
                    if ($siteSlug) {
                        $uriBaseResource = $uriBasePath . 's/' . $siteSlug . '/';
                    }
                }
            }

            $settings = $params;
            $settings['api'] = $api;
            $settings['logger'] = $logger;
            $settings['findResourcesFromIdentifiers'] = $findResourcesFromIdentifiers;
            $settings['fromDatatype'] = $fromDatatype;
            $settings['toDatatype'] = $toDatatype;
            $settings['toDatatypeAdapter'] = $toDatatypeAdapter;
            $settings['fromDatatypeMain'] = $fromDatatypeMain;
            $settings['toDatatypeMain'] = $toDatatypeMain;
            $settings['toDatatypeItem'] = $toDatatypeItem;
            $settings['fromToMain'] = $fromToMain;
            $settings['fromTo'] = $fromTo;
            $settings['properties'] = $properties;
            $settings['processAllProperties'] = $processAllProperties;
            $settings['literalValue'] = $literalValue;
            $settings['literalExtractHtmlText'] = $literalExtractHtmlText;
            $settings['literalHtmlOnlyTaggedString'] = $literalHtmlOnlyTaggedString;
            $settings['resourceProperties'] = $resourceProperties;
            $settings['uriExtractLabel'] = $uriExtractLabel;
            $settings['uriLabel'] = $uriLabel;
            $settings['uriBasePath'] = $uriBasePath;
            $settings['uriBaseResource'] = $uriBaseResource;
            $settings['uriIsApi'] = $uriIsApi;
            $settings['checkContains'] = $checkContains;
        } else {
            extract($settings);
        }

        if ($fromDatatype === $toDatatype) {
            return;
        }

        // Check if the resource has properties to process.
        // Note: this is the original values.
        $properties = $processAllProperties
            ? array_keys($resource->values())
            : array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        if (!$fromDatatypeMain || !$toDatatypeMain || !$toDatatypeAdapter) {
            $logger->warn(new Message(
                'A conversion requires valid "from" datatype and "to" datatype.' // @translate
            ));
            return;
        }

        if ($fromToMain === 'literal => resource') {
            if (!$findResourcesFromIdentifiers) {
                $logger->warn(new Message(
                    'A conversion from data type "%1$s" to "%2$s" requires the module Bulk Import.', // @translate
                    'literal', 'resource'
                ));
                return;
            }
            if (empty($resourceProperties)) {
                $logger->warn(new Message(
                    'To convert into the data type "%s", the properties where to find the identifier should be set.', // @translate
                    $toDatatype
                ));
                return;
            }
        } elseif ($fromToMain === 'resource => uri' && !$uriBaseResource) {
            $logger->warn(new Message(
                'The conversion from data type "%1$s" to "%2$s" requires a site or api to create the url.', // @translate
                $fromDatatype, $toDatatype
            ));
            return;
        }

        $datatypeToValueKeys = [
            'literal' => '@value',
            'resource' => 'value_resource_id',
            'uri' => '@id',
        ];

        $resourceFromId = function ($id, $property) use ($resource, $api, $logger): ?AbstractResourceEntityRepresentation {
            try {
                return $api->read('resources', $id, ['initialize' => false, 'finalize' => false])->getContent();
            } catch (\Exception $e) {
                $logger->info(new Message(
                    'No linked resource found for resource #%1$s, property "%2$s", value resource #%3$s.', // @translate
                    $resource->id(), $property, $id
                ));
                return null;
            }
        };

        $checkResourceNameAndToDatatype = function ($vr, $valueResourceName, $property) use ($resource, $toDatatype, $toDatatypeItem, $logger) {
            $resourceControllerNames = [
                'resource' => 'resources',
                'resources' => 'resources',
                'item' => 'items',
                'items' => 'items',
                'item-set' => 'item_sets',
                'item_sets' => 'item_sets',
                'media' => 'media',
                'annotation' => 'annotations',
                'annotations' => 'annotations',
            ];
            if (!$vr) {
                return false;
            }
            $vrResourceName = $vr->resourceName();
            if ($valueResourceName && $resourceControllerNames[$valueResourceName] !== $vrResourceName) {
                $logger->warn(new Message(
                    'For resource #%1$s, property "%2$s", the linked resource #%3$s is not a %4$s, but a %5$s.', // @translate
                    $resource->id(), $property, $vr->id(), $resourceControllerNames[$valueResourceName], $vrResourceName
                ));
                return false;
            }
            if (($toDatatypeItem && $vrResourceName !== 'items')
                || ($toDatatype === 'resource:itemset' && $vrResourceName !== 'item_sets')
                || ($toDatatype === 'resource:media' && $vrResourceName !== 'media')
                || ($toDatatype === 'resource:annotation' && $vrResourceName !== 'annotations')
                || ($toDatatype === 'annotation' && $vrResourceName !== 'annotations')
            ) {
                return false;
            }
            return true;
        };

        foreach ($properties as $property) {
            foreach ($data[$property] as $key => $value) {
                if ($value['type'] !== $fromDatatype
                    || $value['type'] === $toDatatype
                ) {
                    continue;
                }
                if ($checkContains) {
                    if ($fromDatatype === 'literal' && mb_strpos($value['@value'], $contains) === false) {
                        continue;
                    }
                    if ($fromDatatype === 'uri' && mb_strpos($value['@id'], $contains) === false) {
                        continue;
                    }
                }
                $newValue = null;
                switch ($fromDatatypeMain) {
                    case 'literal':
                        if (!isset($value['@value'])) {
                            continue 2;
                        }
                        if ($literalHtmlOnlyTaggedString
                            && in_array($toDatatype, ['html', 'xml'])
                            && (substr(trim((string) $value['@value']), 0, 1) !== '<' || substr(trim((string) $value['@value']), -1) !== '>')
                        ) {
                            continue 2;
                        }
                        if ($literalExtractHtmlText && in_array($fromDatatype, ['html', 'xml'])) {
                            $value['@value'] = strip_tags($value['@value']);
                        }
                        switch ($toDatatypeMain) {
                            case 'literal':
                                // For custom vocab or specific data type.
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => (string) $value['@value'], '@id' => null, 'o:label' => null];
                                break;
                            case 'resource':
                                $valueResourceId = $findResourcesFromIdentifiers($value['@value'], $resourceProperties);
                                if (!$valueResourceId) {
                                    $logger->info(new Message(
                                        'No linked resource found with properties %1$s for resource #%2$d, property "%3$s", identifier "%4$s"', // @translate
                                        implode(', ', $resourceProperties), $resource->id(), $property, $value['@value']
                                    ));
                                    continue 3;
                                }
                                $vr = $api->read('resources', ['id' => $valueResourceId])->getContent();
                                if (!$checkResourceNameAndToDatatype($vr, null, $property)) {
                                    continue 3;
                                }
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => null, 'o:label' => null, 'value_resource_id' => $valueResourceId];
                                break;
                            case 'uri':
                                if ($uriExtractLabel) {
                                    [$uri, $label] = explode(' ', $value['@value'] . ' ', 2);
                                    $label = trim($label);
                                    $label = strlen($label) ? $label : $uriLabel;
                                    $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => $uri, 'o:label' => $label];
                                } else {
                                    $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => $value['@value'], 'o:label' => $uriLabel];
                                }
                                break;
                            default:
                                return;
                        }
                        break;

                    case 'resource':
                        if (!isset($value['value_resource_id'])) {
                            continue 2;
                        }
                        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $vr */
                        $vr = $resourceFromId($value['value_resource_id'], $property);
                        if (!$vr) {
                            continue 2;
                        }
                        switch ($toDatatypeMain) {
                            case 'resource':
                                // For custom vocab or specific resource type.
                                if (!$checkResourceNameAndToDatatype($vr, null, $property)) {
                                    continue 3;
                                }
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => null, 'o:label' => null, 'value_resource_id' => $value['value_resource_id']];
                                break;
                            case 'literal':
                                $label = isset($value['display_title']) && strlen($value['display_title']) ? $value['display_title'] : $vr->displayTitle();
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => $label, '@id' => null, 'o:label' => null];
                                break;
                            case 'uri':
                                $uri = $uriBaseResource . ($uriIsApi ? $vr->resourceName() : $vr->getControllerName()) . '/' . $value['value_resource_id'];
                                $label = $uriLabel ?? (isset($value['display_title']) && strlen($value['display_title']) ? $value['display_title'] : $vr->displayTitle());
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => $uri, 'o:label' => $label];
                                break;
                            default:
                                return;
                        }
                        break;

                    case 'uri':
                        if (!isset($value['@id'])) {
                            continue 2;
                        }
                        switch ($toDatatypeMain) {
                            case 'uri':
                                // For custom vocab or value suggest.
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => $value['@id'], 'o:label' => $value['o:label'] ?? null];
                                break;
                            case 'literal':
                                switch ($literalValue) {
                                    case 'label_uri':
                                        $label = isset($value['o:label']) && strlen($value['o:label']) ? $value['o:label'] . ' (' . $value['@id'] . ')' : $value['@id'];
                                        $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => $label, '@id' => null, 'o:label' => null];
                                        break;
                                    case 'uri_label':
                                        $label = isset($value['o:label']) && strlen($value['o:label']) ? $value['@id'] . ' (' . $value['o:label'] . ')' : $value['@id'];
                                        $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => $label, '@id' => null, 'o:label' => null];
                                        break;
                                    case 'label_or_uri':
                                        $label = isset($value['o:label']) && strlen($value['o:label']) ? $value['o:label'] : $value['@id'];
                                        $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => $label, '@id' => null, 'o:label' => null];
                                        break;
                                    case 'label':
                                        if (!isset($value['o:label']) || !strlen($value['o:label'])) {
                                            continue 4;
                                        }
                                        $label = $value['o:label'];
                                        $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => $label, '@id' => null, 'o:label' => null];
                                        break;
                                    case 'uri':
                                        $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => $value['@id'], '@id' => null, 'o:label' => null];
                                        break;
                                    default:
                                        return;
                                }
                                break;
                            case 'resource':
                                $valueResourceId = basename($value['@id']);
                                $valueResourceName = basename(dirname($value['@id']));
                                if (!is_numeric($valueResourceId)
                                    || !in_array($valueResourceName, ['resource', 'item', 'item-set', 'media', 'annotation', 'resources', 'items', 'item_sets', 'annotations'])
                                    || substr($value['@id'], 0, strlen($uriBasePath)) !== $uriBasePath
                                    || !($vr = $resourceFromId($valueResourceId, $property))
                                ) {
                                    $logger->info(new Message(
                                        'For resource #%1$s, property "%2$s", the value "%3$s" is not a resource url.', // @translate
                                        $resource->id(), $property, $value['@value']
                                    ));
                                    continue 3;
                                }
                                if (!$checkResourceNameAndToDatatype($vr, $valueResourceName, $property)) {
                                    continue 3;
                                }
                                $newValue = ['property_id' => $value['property_id'], 'type' => $toDatatype, '@language' => $value['@language'] ?? null, '@value' => null, '@id' => null, 'o:label' => null, 'value_resource_id' => $valueResourceId];
                                break;
                            default:
                                return;
                        }
                        break;

                    default:
                        return;
                }

                if ($newValue) {
                    if (!$toDatatypeAdapter->isValid($newValue)) {
                        $logger->notice(new Message(
                            'Conversion from data type "%1$s" to "%2$s" is not possible in resource #%3$d for value: %4$s', // @translate
                            $fromDatatype, $toDatatype, $resource->id(), $value[$datatypeToValueKeys[$fromDatatypeMain]]
                        ));
                        continue;
                    }
                    $data[$property][$key] = $newValue;
                }
            }
        }
    }

    /**
     * Order values in a list of properties.
     *
     * This feature is generally used for title, description and subjects.
     */
    protected function orderValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
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
     */
    protected function applyVisibilityForResourceValues(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $visibility = (int) (bool) $params['visibility'];
            $properties = $params['properties'];
            $datatypes = array_filter($params['datatypes'] ?? []);
            $languages = $params['languages'];
            $contains = (string) $params['contains'];

            $checkDatatype = !empty($datatypes);
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
                if ($checkContains && strpos((string) $value['@value'], $contains) === false) {
                    continue;
                }
                $data[$property][$key]['is_public'] = $visibility;
            }
        }
    }

    /**
     * Update values for resources.
     */
    protected function fillDataForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $ownerId = (int) $params['owner'] ?: null;

            $settings = $params;
            $settings['ownerId'] = $ownerId;
        } else {
            extract($settings);
        }

        $currentResourceOwner = $resource->owner();
        if (!$currentResourceOwner && !$ownerId) {
            return;
        }
        if ($currentResourceOwner && $currentResourceOwner->id() === $ownerId) {
            return;
        }

        $data['o:owner'] = ['o:id' => $ownerId];
    }

    /**
     * Update values for resources.
     */
    protected function fillValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;

        // TODO Only geonames and idref are managed.
        // TODO Add a query for a single value in ValueSuggest (or dereferenceable).
        $managedDatatypes = [
            'literal',
            'uri',
            'valuesuggest:geonames:geonames',
            'valuesuggest:idref:all',
            'valuesuggest:idref:person',
            'valuesuggest:idref:corporation',
            'valuesuggest:idref:conference',
            'valuesuggest:idref:subject',
            'valuesuggest:idref:rameau',
            /* // No mapping currently.
            'valuesuggest:idref:fmesh',
            'valuesuggest:idref:geo',
            'valuesuggest:idref:family',
            'valuesuggest:idref:title',
            'valuesuggest:idref:authorTitle',
            'valuesuggest:idref:trademark',
            'valuesuggest:idref:ppn',
            'valuesuggest:idref:library',
            */
        ];

        if (is_null($settings)) {
            $mode = $params['mode'];
            $properties = $params['properties'] ?? [];
            $datatypes = $params['datatypes'] ?? [];
            $datatype = $params['datatype'] ?? null;
            $featuredSubject = !empty($params['featured_subject']);
            $language = $params['language'] ?? '';
            $updateLanguage = empty($params['update_language'])
                || !in_array($params['update_language'], ['keep', 'update', 'remove'])
                || ($params['update_language'] === 'update' && !$language)
                ? 'keep'
                : $params['update_language'];

            $processAllProperties = in_array('all', $properties);
            $processAllDatatypes = in_array('all', $datatypes);

            $skip = false;
            if (!in_array($mode, ['label_missing', 'label_all', 'label_remove', 'uri_missing', 'uri_all'])) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->warn(new Message('Process is skipped: mode "%s" is unmanaged', // @translate
                    $mode
                ));
                $skip = true;
            }

            // Flat the list of datatypes.
            $dataTypeManager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
            $datatypes = $processAllDatatypes
                ? array_intersect($dataTypeManager->getRegisteredNames(), $managedDatatypes)
                : array_intersect($dataTypeManager->getRegisteredNames(), $datatypes, $managedDatatypes);

            if ((in_array('literal', $datatypes) || in_array('uri', $datatypes)) && in_array($datatype, ['', 'literal', 'uri'])) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->warn(new Message('When "literal" or "uri" is used, the datatype should be specified.')); // @translate
                $skip = true;
            }

            $isModeUri = $mode === 'uri_missing' || $mode === 'uri_all';
            if ($isModeUri && !in_array($datatype, $datatypes) || in_array($datatype, ['', 'literal', 'uri'])) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->warn(new Message('When filling an uri, the datatype should be specified.')); // @translate
                $skip = true;
            }

            if ($isModeUri && !$this->isModuleActive('ValueSuggest')) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->warn(new Message('When filling an uri, the module Value Suggest should be available.')); // @translate
                $skip = true;
            }

            $labelAndUriOptions = [
                'language' => preg_replace('/[^a-zA-Z0-9_-]+/', '', $language),
                'featured_subject' => $featuredSubject,
            ];

            $settings = $params;
            $settings['mode'] = $mode;
            $settings['properties'] = $properties;
            $settings['processAllProperties'] = $processAllProperties;
            $settings['datatypes'] = $datatypes;
            $settings['datatype'] = $datatype;
            $settings['processAllDatatypes'] = $processAllDatatypes;
            $settings['labelAndUriOptions'] = $labelAndUriOptions;
            $settings['language'] = $language;
            $settings['updateLanguage'] = $updateLanguage;
            $settings['skip'] = $skip;
        } else {
            extract($settings);
        }

        if ($skip) {
            return;
        }

        // Note: this is the original values.
        $properties = $processAllProperties
            ? array_keys($resource->values())
            : array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        if ($mode === 'label_remove') {
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    if ($value['type'] === 'literal'
                        || !in_array($value['type'], $datatypes)
                    ) {
                        continue;
                    }
                    // Don't remove label if there is no id.
                    // Manage and store badly formatted id.
                    if (empty($value['@id'])) {
                        $data[$property][$key]['@id'] = $value['@id'] = null;
                        continue;
                    }
                    $vvalue = $value['o:label'] ?? $value['@value'] ?? null;
                    if (!strlen((string) $vvalue)) {
                        continue;
                    }
                    unset($data[$property][$key]['@value']);
                    unset($data[$property][$key]['o:label']);
                    if ($updateLanguage === 'update') {
                        $data[$property][$key]['@language'] = $language;
                    } elseif ($updateLanguage === 'remove') {
                        unset($data[$property][$key]['@language']);
                    }
                }
            }
            return;
        }

        if ($mode === 'label_missing' || $mode === 'label_all') {
            $onlyMissing = $mode === 'label_missing';
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    // Manage and store badly formatted id.
                    if (empty($value['@id'])) {
                        $data[$property][$key]['@id'] = $value['@id'] = null;
                    }
                    if (!in_array($value['type'], $datatypes)
                        || !in_array($value['type'], ['literal', 'uri', $datatype])
                    ) {
                        continue;
                    }
                    $vuri = $value['type'] === 'literal' ? $value['@value'] : ($value['@id'] ?? null);
                    if (empty($vuri)) {
                        continue;
                    }
                    $vvalue = $value['type'] !== 'literal'
                        ? $value['o:label'] ?? $value['@value'] ?? null
                        : null;
                    if ($onlyMissing && strlen((string) $vvalue)) {
                        continue;
                    }
                    $vtype = in_array($value['type'], ['literal', 'uri']) ? $datatype: $value['type'];
                    $vvalueNew = $this->getLabelForUri($vuri, $vtype, $labelAndUriOptions);
                    if (is_null($vvalueNew)) {
                        continue;
                    }
                    $data[$property][$key]['o:label'] = $vvalueNew;
                    $data[$property][$key]['@value'] = null;
                    $data[$property][$key]['type'] = $vtype;
                    $data[$property][$key]['@id'] = $vuri;
                    if ($updateLanguage === 'update') {
                        $data[$property][$key]['@language'] = $language;
                    } elseif ($updateLanguage === 'remove') {
                        unset($data[$property][$key]['@language']);
                    }
                }
            }
            return;
        }

        if ($mode === 'uri_missing' || $mode === 'uri_all') {
            $onlyMissing = $mode === 'uri_missing';
            foreach ($properties as $property) {
                foreach ($data[$property] as $key => $value) {
                    // Manage and store badly formatted id.
                    if (empty($value['@id'])) {
                        $data[$property][$key]['@id'] = $value['@id'] = null;
                    }
                    if (!in_array($value['type'], $datatypes)
                        || !in_array($value['type'], ['literal', 'uri', $datatype])
                    ) {
                        continue;
                    }
                    if ($value['@id']
                        && $onlyMissing
                        // Manage badly formatted values.
                        && (substr($value['@id'], 0, 8) === 'https://' || substr($value['@id'], 0, 7) === 'http://')
                    ) {
                        continue;
                    }
                    $vvalue = $value['@id'] ?? $value['o:label'] ?? $value['@value'] ?? null;
                    if (empty($vvalue)) {
                        continue;
                    }
                    $vtype = in_array($value['type'], ['literal', 'uri']) ? $datatype: $value['type'];
                    $vuri = $this->getValueSuggestUriForLabel($vvalue, $vtype, $language);
                    if (!$vuri) {
                        continue;
                    }
                    $data[$property][$key]['o:label'] = $vvalue === $vuri ? null : $vvalue;
                    $data[$property][$key]['@value'] = null;
                    $data[$property][$key]['type'] = $vtype;
                    $data[$property][$key]['@id'] = $vuri;
                    if ($updateLanguage === 'update') {
                        $data[$property][$key]['@language'] = $language;
                    } elseif ($updateLanguage === 'remove') {
                        unset($data[$property][$key]['@language']);
                    }
                }
            }
        }
    }

    /**
     * Remove values from a list of properties to another one.
     */
    protected function removeValuesForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;
        if (is_null($settings)) {
            $properties = $params['properties'];
            $datatypes = array_filter($params['datatypes'] ?? []);
            $languages = $params['languages'];
            $visibility = $params['visibility'] === '' ? null : (int) (bool) $params['visibility'];
            $contains = (string) $params['contains'];

            if (empty($properties)) {
                return;
            }

            $processAllProperties = in_array('all', $properties);
            $checkDatatype = !empty($datatypes);
            $checkLanguage = !empty($languages);
            $checkVisibility = !is_null($visibility);
            $checkContains = (bool) mb_strlen($contains);

            $settings = $params;
            $settings['processAllProperties'] = $processAllProperties;
            $settings['checkDatatype'] = $checkDatatype;
            $settings['checkLanguage'] = $checkLanguage;
            $settings['checkVisibility'] = $checkVisibility;
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

        if (!$checkDatatype && !$checkLanguage && !$checkVisibility && !$checkContains) {
            $data = array_diff_key($data, array_flip($properties));
            return;
        }

        foreach ($properties as $property) {
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
                unset($data[$property][$key]);
            }
        }
    }

    /**
     * Update the html of a media of type html from items.
     */
    protected function updateMediaHtmlForResources(
        AbstractResourceEntityAdapter $adapter,
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
                    $isValidRegex = @preg_match($from, '') !== false;
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

        $isMediaIds = $adapter instanceof MediaAdapter;

        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Doctrine\ORM\EntityRepository $repository
         */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        foreach ($resourceIds as $resourceId) {
            $medias = $isMediaIds
                ? $repository->findBy(['id' => $resourceId, 'ingester' => 'html'])
                : $repository->findBy(['item' => $resourceId, 'ingester' => 'html']);
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
     * Update the media type of a media file from items.
     */
    protected function updateMediaTypeForResources(
        AbstractResourceEntityAdapter $adapter,
        array $resourceIds,
        array $params
    ): void {
        // Already checked.
        $from = $params['from'];
        $to = $params['to'];

        if ($from === $to) {
            return;
        }

        $isMediaIds = $adapter instanceof MediaAdapter;
        $keyResourceId = $isMediaIds ? 'id' : 'item';

        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Doctrine\ORM\EntityRepository $repository
         */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        foreach ($resourceIds as $resourceId) {
            $medias = $repository->findBy([
                $keyResourceId => $resourceId,
                'mediaType' => $from,
            ]);
            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $media) {
                $media->setMediaType($to);
                $entityManager->persist($media);
                // No flush here.
            }
        }
    }

    /**
     * Update the media visibility of a media file from items.
     */
    protected function updateMediaVisibilityForResources(
        AbstractResourceEntityAdapter $adapter,
        array $resourceIds,
        array $params
    ): void {
        // Already checked.
        $visibility = (bool) $params['visibility'];
        $mediaTypes = $params['media_types'] ?? [];
        $ingesters = array_filter($params['ingesters'] ?? []);
        $renderers = array_filter($params['renderers'] ?? []);

        $isMediaIds = $adapter instanceof MediaAdapter;

        $keyResourceId = $isMediaIds ? 'id' : 'item';
        $defaultArgs = [
            $keyResourceId => null,
        ];
        if ($mediaTypes) {
            $defaultArgs['mediaType'] = $mediaTypes;
        }
        if ($ingesters) {
            $defaultArgs['ingester'] = $ingesters;
        }
        if ($renderers) {
            $defaultArgs['renderer'] = $renderers;
        }

        /**
         * @var \Doctrine\ORM\EntityManager $entityManager
         * @var \Doctrine\ORM\EntityRepository $repository
         */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $repository = $entityManager->getRepository(\Omeka\Entity\Media::class);
        foreach ($resourceIds as $resourceId) {
            $args = $defaultArgs;
            $args[$keyResourceId] = $resourceId;
            $medias = $repository->findBy($args);
            /** @var \Omeka\Entity\Media $media */
            foreach ($medias as $media) {
                if ($media->isPublic() !== $visibility) {
                    $media->setIsPublic($visibility);
                    $entityManager->persist($media);
                    // No flush here.
                }
            }
        }
    }

    protected function getLabelForUri(string $uri, string $datatype, array $options = []): ?string
    {
        static $filleds = [];
        static $logger = null;

        if (!$logger) {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
        }

        $featuredSubject = !empty($options['featured_subject']);
        $language = $options['language'] ?? '';

        $endpointData = $this->endpointDatatype($datatype, $language, $featuredSubject);
        if (!$endpointData) {
            return null;
        }

        if (array_key_exists($uri, $filleds)) {
            return $filleds[$uri];
        }

        // So get the url from the uri.
        $url = $this->cleanRemoteUri($uri, $datatype, $language, $featuredSubject);
        if (!$url) {
            $filleds[$uri] = null;
            return null;
        }

        if (array_key_exists($url, $filleds)) {
            return $filleds[$url];
        }

        $doc = $this->fetchUrlXml($url);
        if (!$doc) {
            return null;
        }

        $xpath = new \DOMXPath($doc);

        switch ($datatype) {
            case 'valuesuggest:geonames:geonames':
                $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                $xpath->registerNamespace('gn', 'http://www.geonames.org/ontology#');
                break;
            default:
                break;
        }

        $queries = (array) $endpointData['path'];
        foreach ($queries as $query) {
            $nodeList = $xpath->query($query);
            if (!$nodeList || !$nodeList->length) {
                continue;
            }
            $value = trim((string) $nodeList->item(0)->nodeValue);
            if ($value === '') {
                continue;
            }

            $logger->info(new Message(
                'The label for uri "%1$s" is "%2$s".', // @translate
                $uri, $value
            ));

            $filleds[$uri] = $value;
            return $value;
        }

        $logger->err(new Message(
            'The label for uri "%s" was not found.', // @translate
            $uri
        ));
        $filleds[$uri] = null;
        return null;
    }

    /**
     * @see \ValueSuggest\Controller\IndexController::proxyAction()
     */
    protected function getValueSuggestUriForLabel(string $label, string $datatype, ?string $language = null): ?string
    {
        static $filleds = [];
        static $logger = null;
        static $dataTypeManager = null;

        if (!$logger) {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
            $dataTypeManager = $this->getServiceLocator()->get('Omeka\DataTypeManager');
        }

        if (array_key_exists($label, $filleds)) {
            return $filleds[$label];
        }

        if (!strlen($label)) {
            return null;
        }

        if (!$datatype || !$dataTypeManager->has($datatype)) {
            return null;
        }

        $dataType = $dataTypeManager->get($datatype);
        if (!$dataType instanceof \ValueSuggest\DataType\DataTypeInterface) {
            return null;
        }

        $suggester = $dataType->getSuggester();
        if (!$suggester instanceof \ValueSuggest\Suggester\SuggesterInterface) {
            return null;
        }

        $suggestions = $suggester->getSuggestions($label, $language);
        if (!is_array($suggestions) || !count($suggestions)) {
            return null;
        }

        if (count($suggestions) > 1) {
            return null;
        }

        $suggestion = reset($suggestions);
        return $suggestion['data']['uri'] ?? null;
    }

    /**
     * @todo Move these hard-coded mappings into the form.
     */
    protected function endpointDatatype(string $datatype, ?string $language = null, bool $featuredSubject = false): array
    {
        $baseurlIdref = [
            'idref.fr/',
        ];

        $endpointDatatypes = [
            'valuesuggest:geonames:geonames' => [
                'base_url' => [
                    'geonames.org/',
                    'sws.geonames.org/',
                ],
                'path' => [
                    '/rdf:RDF/gn:Feature/gn:officialName[@xml:lang="' . $language . '"][1]',
                    '/rdf:RDF/gn:Feature/gn:name[1]',
                    '/rdf:RDF/gn:Feature/gn:shortName[1]',
                ],
            ],
            'valuesuggest:idref:all' => null,
            'valuesuggest:idref:person' => [
                'base_url' => $baseurlIdref,
                'path' => [
                    '/record/datafield[@tag="900"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="901"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="902"]/subfield[@code="a"][1]',
                ],
            ],
            'valuesuggest:idref:corporation' => [
                'base_url' => $baseurlIdref,
                'path' => [
                    '/record/datafield[@tag="910"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="911"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="912"]/subfield[@code="a"][1]',
                ],
            ],
            'valuesuggest:idref:conference' => [
                'base_url' => $baseurlIdref,
                'path' => [
                    '/record/datafield[@tag="910"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="911"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="912"]/subfield[@code="a"][1]',
                ],
            ],
            'valuesuggest:idref:subject' => [
                'base_url' => $baseurlIdref,
                'path' => [
                    '/record/datafield[@tag="250"]/subfield[@code="a"][1]',
                    '/record/datafield[@tag="915"]/subfield[@code="a"][1]',
                ],
            ],
            'valuesuggest:idref:rameau' => [
                'base_url' => $baseurlIdref,
                'path' => [
                    '/record/datafield[@tag="950"]/subfield[@code="a"][1]',
                ],
            ],
            'valuesuggest:idref:fmesh' => null,
            'valuesuggest:idref:geo' => null,
            'valuesuggest:idref:family' => null,
            'valuesuggest:idref:title' => null,
            'valuesuggest:idref:authorTitle' => null,
            'valuesuggest:idref:trademark' => null,
            'valuesuggest:idref:ppn' => null,
            'valuesuggest:idref:library' => null,
        ];

        // Fix datatypes for rameau.
        if ($featuredSubject && $datatype === 'valuesuggest:idref:rameau') {
            $endpointDatatypes['valuesuggest:idref:rameau']['path'] = [
                '/record/datafield[@tag="250"]/subfield[@code="a"][1]',
                '/record/datafield[@tag="915"]/subfield[@code="a"][1]',
                // If featured subject is missing, use the current subject.
                '/record/datafield[@tag="910"]/subfield[@code="a"][1]',
                '/record/datafield[@tag="950"]/subfield[@code="a"][1]',
            ];
        }

        return $endpointDatatypes[$datatype] ?? [];
    }

    protected function cleanRemoteUri(string $uri, string $datatype, ?string $language = null, bool $featuredSubject = false): ?string
    {
        if (!$uri) {
            return null;
        }

        $endpointData = $this->endpointDatatype($datatype, $language, $featuredSubject);
        if (!$endpointData) {
            return null;
        }

        $isManagedUrl = false;
        foreach ($endpointData['base_url'] as $baseUrl) {
            foreach (['http://', 'https://', 'http://www.', 'https://www.'] as $prefix) {
                if (mb_substr($uri, 0, strlen($prefix . $baseUrl)) === $prefix . $baseUrl) {
                    $isManagedUrl = true;
                    break 2;
                }
            }
        }
        if (!$isManagedUrl) {
            return null;
        }

        switch ($datatype) {
            case 'valuesuggest:geonames:geonames':
                // Extract the id.
                $id = preg_replace('~.*/(?<id>[0-9]+).*~m', '$1', $uri);
                if (!$id) {
                    $logger = $this->getServiceLocator()->get('Omeka\Logger');
                    $logger->err(new Message(
                        'The label for uri "%s" was not found.', // @translate
                        $uri
                    ));
                    return null;
                }
                $url = "https://sws.geonames.org/$id/about.rdf";
                break;
            case substr($datatype, 0, 18) === 'valuesuggest:idref':
                $url = mb_substr($uri, -4) === '.xml' ? $uri : $uri . '.xml';
                break;
            default:
                return null;
        }

        return $url;
    }

    protected function fetchUrlXml(string $url): ?\DOMDocument
    {
        static $logger = null;

        if (!$logger) {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
        }

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:115.0) Gecko/20100101 Firefox/115.0',
            'Content-Type' => 'application/xml',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        try {
            $response = \Laminas\Http\ClientStatic::get($url, [], $headers);
        } catch (\Laminas\Http\Client\Exception\ExceptionInterface $e) {
            $logger->err(new Message(
                'Connection error when fetching url "%1$s": %2$s', // @translate
                $url, $e
            ));
            return null;
        }
        if (!$response->isSuccess()) {
            $logger->err(new Message(
                'Connection issue when fetching url "%1$s": %2$s', // @translate
                $url, $response->getReasonPhrase()
            ));
            return null;
        }

        $xml = $response->getBody();
        if (!$xml) {
            $logger->err(new Message(
                'Output is not xml for url "%s".', // @translate
                $url
            ));
            return null;
        }

        // $simpleData = new SimpleXMLElement($xml, LIBXML_BIGLINES | LIBXML_COMPACT | LIBXML_NOBLANKS
        //     | /* LIBXML_NOCDATA | */ LIBXML_NOENT | LIBXML_PARSEHUGE);

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        try {
            $doc->loadXML($xml);
        } catch (\Exception $e) {
            $logger->err(new Message(
                'Output is not xml for url "%s".', // @translate
                $url
            ));
            return null;
        }

        if (!$doc) {
            $logger->err(new Message(
                'Output is not xml for url "%s".', // @translate
                $url
            ));
            return null;
        }

        return $doc;
    }

    /**
     * Check if a string or a id is a managed term.
     */
    protected function isPropertyTerm($term): bool
    {
        $ids = $this->getPropertyIds();
        return isset($ids[$term]);
    }

    /**
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    protected function getPropertyIds(): array
    {
        static $properties;
        if (isset($properties)) {
            return $properties;
        }

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.id AS id',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
                'property.id'
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        return $properties
            = array_map('intval', $connection->executeQuery($qb)->fetchAllKeyValue());
    }

    /**
     * Get each line of a string separately.
     */
    public function stringToList($string): array
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen');
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    public function fixEndOfLine($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
    }
}
