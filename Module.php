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
use Laminas\Math\Rand;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Adapter\MediaAdapter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\File\TempFile;
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

    /**
     * @var string
     */
    protected $basePath;

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

            // Clean batch update request one time.
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
            // Nevertheless, some processes can be done one time or via sql
            // queries.
            $sharedEventManager->attach(
                $adapter,
                'api.batch_update.post',
                [$this, 'handleResourcesBatchUpdatePost']
            );
        }

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
        $resourceType = $form->getOption('resource_type');

        /** @var \BulkEdit\Form\BulkEditFieldset $fieldset */
        $fieldset = $formElementManager->get(BulkEditFieldset::class, [
            'resource_type' => $resourceType,
        ]);
        $form->add($fieldset);
    }

    /**
     * Process action on create/update.
     *
     * - preventive trim on property values.
     * - preventive deduplication on property values
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

    /**
     * Clean the request one time only.
     *
     *  Batch process is divided in chunk (100) and each bulk may be run three
     *  times (replace, remove or append). Omeka S v4.1 cleaned process.
     */
    public function handleResourceBatchUpdatePreprocess(Event $event): void
    {
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

    /**
     * Process tasks that can be done via api.update.pre for a single resource.
     */
    public function handleResourceUpdatePreBatchUpdate(Event $event): void
    {
        /**
         * A batch update process is launched one to three times in the core, at
         * least with option "collectionAction" = "replace" (Omeka < 4.1).
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

        // Skip process that can be done globally via a single sql or on another
        // resource or cannot be done via api.
        $postProcesses = [
            // Start with complex processes.
            'explode_item' => null,
            'explode_pdf' => null,
            'media_html' => null,
            'media_type' => null,
            'media_visibility' => null,
            // Then simple queries.
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
     * Data may need to be reindexed if a module like AdvancedSearch is used
     * for processes that use sql.
     */
    public function handleResourcesBatchUpdatePost(Event $event): void
    {
        /**
         * A batch update process is launched one to three times in the core,
         * at least with option "collectionAction" = "replace".
         * Batch updates are always partial.
         *
         * Warning: on batch update all, there is no collectionAction "replace",
         * so it should be set by default.
         *
         * @see \Omeka\Job\BatchUpdate::perform()
         * @var \Omeka\Api\Request $request
         */
        $request = $event->getParam('request');
        if ($request->getOption('collectionAction', 'replace') !== 'replace') {
            return;
        }

        $data = $request->getContent('data');
        if (empty($data['bulkedit'])) {
            return;
        }

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $ids = (array) $request->getIds();
        if (empty($ids)) {
            return;
        }

        $resourceName = $request->getResource();

        $postProcesses = [
            'items' => [
                'explode_item' => null,
                'explode_pdf' => null,
                'media_html' => null,
                'media_type' => null,
                'media_visibility' => null,
            ],
            'media' => [
                'media_html' => null,
                'media_type' => null,
                'media_visibility' => null,
            ],
        ];

        $postProcessesResource = $postProcesses[$resourceName] ?? [];

        $postProcesses = array_merge($postProcessesResource, [
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
        $this->updateResourcesPost($adapter, $ids, $bulkedit);
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
            'explode_item' => null,
            'explode_pdf' => null,
            'media_order' => null,
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
                'equal' => $params['equal'],
                'contains' => $params['contains'],
            ];
        }

        $params = $bulkedit['explode_item'] ?? [];
        if (!empty($params['mode'])) {
            $processes['explode_item'] = [
                'mode' => $params['mode'],
            ];
        }

        $params = $bulkedit['explode_pdf'] ?? [];
        if (!empty($params['mode'])) {
            $processes['explode_pdf'] = [
                'mode' => $params['mode'],
                'process' => $params['process'] ?? null,
                'resolution' => (int) $params['resolution'] ?? null,
                // TODO Use server-url from job.
                'base_uri' => $this->getBaseUri(),
            ];
        }

        $params = $bulkedit['media_order'] ?? [];
        $order = $params['order'] ?? '';
        if (mb_strlen($order)) {
            $processes['media_order'] = [
                'order' => $order,
                'mediatypes' => $params['mediatypes'] ?? [],
                'extensions' => $params['extensions'] ?? [],
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
            // TODO Add the check of the validity of the regex in the form.
            // Early check validity of the regex.
            if (!($params['mode'] === 'regex' && @preg_match($from, '') === false)) {
                $processes['media_html'] = [
                    'from' => $from,
                    'to' => $to,
                    'mode' => $params['mode'],
                    'remove' => $remove,
                    'prepend' => $prepend,
                    'append' => $append,
                ];
            }
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

    /**
     * Run process for a single resource.
     */
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
            case 'media_order':
                $this->updateMediaOrderForResource($resource, $data, $params);
                break;
            default:
                break;
        }

        return $data;
    }

    /**
     * Run process for multiple resources, possibly via sql.
     */
    protected function updateResourcesPost(
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
            case 'explode_item':
                $this->explodeItemByMedia($adapter, $resourceIds, $params);
                break;
            case 'explode_pdf':
                $this->explodePdf($adapter, $resourceIds, $params);
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
            $settings['datatypes'] = $datatypes;
            $settings['visibility'] = $visibility;
            $settings['contains'] = $contains;
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
            $settings['datatypes'] = $datatypes;
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
            $datatype = empty($params['datatype']) ? null : $params['datatype'];
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

            if (!$datatype && count($datatypes) === 1 && in_array(reset($datatypes), $managedDatatypes)) {
                $datatype = reset($datatypes);
            }

            if ((in_array('literal', $datatypes) || in_array('uri', $datatypes)) && in_array($datatype, [null, 'literal', 'uri'])) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->warn(new Message('When "literal" or "uri" is used, the precise datatype should be specified.')); // @translate
                $skip = true;
            }

            $isModeUri = $mode === 'uri_missing' || $mode === 'uri_all';
            if ($isModeUri && (!in_array($datatype, $datatypes) || in_array($datatype, [null, 'literal', 'uri']))) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->warn(new Message('When filling an uri, the precise datatype should be specified.')); // @translate
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
            $settings['featuredSubject'] = $featuredSubject;
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
                    $vtype = in_array($value['type'], ['literal', 'uri']) ? $datatype : $value['type'];
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
            $equal = (string) $params['equal'];
            $contains = (string) $params['contains'];

            if (empty($properties)) {
                return;
            }

            $processAllProperties = in_array('all', $properties);
            $checkDatatype = !empty($datatypes);
            $checkLanguage = !empty($languages);
            $checkVisibility = !is_null($visibility);
            $checkEqual = (bool) mb_strlen($equal);
            $checkContains = (bool) mb_strlen($contains);

            $mainDataType = $this->getServiceLocator()->get('ViewHelperManager')->get('mainDataType');
            $mainDataTypes = [];
            foreach ($datatypes as $datatype) {
                $mainDataTypes[$datatype] = $mainDataType($datatype);
            }

            $settings = $params;
            $settings['datatypes'] = $datatypes;
            $settings['visibility'] = $visibility;
            $settings['mainDataType'] = $mainDataType;
            $settings['mainDataTypes'] = $mainDataTypes;
            $settings['processAllProperties'] = $processAllProperties;
            $settings['checkDatatype'] = $checkDatatype;
            $settings['checkLanguage'] = $checkLanguage;
            $settings['checkVisibility'] = $checkVisibility;
            $settings['checkEqual'] = $checkEqual;
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

        if (!$checkDatatype
            && !$checkLanguage
            && !$checkVisibility
            && !$checkEqual
            && !$checkContains
        ) {
            $data = array_diff_key($data, array_flip($properties));
            return;
        }

        foreach ($properties as $property) {
            foreach ($data[$property] as $key => $value) {
                $value += ['@language' => null, 'is_public' => 1, '@value' => null, 'value_resource_id' => null, '@id' => null];
                if ($checkDatatype && !in_array($value['type'], $datatypes)) {
                    continue;
                }
                if ($checkLanguage && !in_array($value['@language'], $languages)) {
                    continue;
                }
                if ($checkVisibility && (int) $value['is_public'] !== $visibility) {
                    continue;
                }
                if ($checkEqual || $checkContains) {
                    $valueMainDataType = $mainDataType($value['type']);
                    if ($checkEqual) {
                        if (($valueMainDataType === 'literal' && $value['@value'] !== $equal)
                            || ($valueMainDataType === 'resource' && (int) $value['value_resource_id'] !== (int) $equal)
                            || ($valueMainDataType === 'uri' && $value['@id']  !== $equal)
                        ) {
                            continue;
                        }
                    }
                    if ($checkContains) {
                        if (($valueMainDataType === 'literal' && strpos((string) $value['@value'], $contains) === false)
                            // || ($valueMainDataType === 'resource' && (int) $value['value_resource_id'] !== (int) $contains)
                            || ($valueMainDataType === 'uri' && strpos((string) $value['@id'], $contains) === false)
                        ) {
                            continue;
                        }
                    }
                }
                unset($data[$property][$key]);
            }
        }
    }

    /**
     * Update the media positions for an item.
     */
    protected function updateMediaOrderForResource(
        AbstractResourceEntityRepresentation $resource,
        array &$data,
        array $params
    ): void {
        static $settings;

        if (is_null($settings)) {
            $order = $params['order'];
            $mediaTypes = $params['mediatypes'];
            $extensions = $params['extensions'];

            $orders = [
                'title',
                'source',
                'basename',
                'mediatype',
                'extension',
            ];

            $mainOrder = null;
            $subOrder = null;
            if (in_array($order, $orders)) {
                $mainOrder = $order;
            } elseif (strpos($order, '/')) {
                [$mainOrder, $subOrder] = explode('/', $order, 2);
            }

            if (!in_array($mainOrder, $orders)
                || ($subOrder && !in_array($subOrder, $orders))
            ) {
                $logger = $this->getServiceLocator()->get('Omeka\Logger');
                $logger->err(new Message(
                    'Order "%s" is invalid.', // @translate
                    $order
                ));
                $order = '';
            }

            $settings = $params;
            $settings['order'] = $order;
            $settings['mainOrder'] = $mainOrder;
            $settings['subOrder'] = $subOrder;
        } else {
            extract($settings);
        }

        if (!$order) {
            return;
        }

        if (empty($data['o:media']) || count($data['o:media']) <= 1) {
            return;
        }

        $needTitle = $mainOrder === 'title' || $subOrder === 'title';
        $needSource = $mainOrder === 'source' || $subOrder === 'source';
        $needBasename = $mainOrder === 'basename' || $subOrder === 'basename';
        $needMediaType = $mainOrder === 'mediatype' || $subOrder === 'mediatype';
        $needExtension = $mainOrder === 'extension' || $subOrder === 'extension';

        // Filter on media-type or extension as first or last, so remove
        // extension from the other order field.
        $removeExtension = ($needSource || $needBasename)
            && ($needMediaType || $needExtension);

        $mediaData = [
            'position' => [],
            'title' => [],
            'source' => [],
            'basename' => [],
            'mediatype' => [],
            'mainmediatype' => [],
            'extension' => [],
        ];

        /**
         * @var \Omeka\Api\Representation\ItemRepresentation $resource
         * @var \Omeka\Api\Representation\MediaRepresentation $media
         */

        // A quick loop to get all data as string.
        $position = 0;
        foreach ($resource->media() as $media) {
            $mediaId = $media->id();
            $mediaData['position'][$mediaId] = ++$position;
            if ($needTitle) {
                $mediaData['title'][$mediaId] = (string) $media->title();
            }
            if ($needSource) {
                $mediaData['source'][$mediaId] = (string) $media->source();
            }
            if ($needBasename) {
                $source = (string) $media->source();
                $mediaData['basename'][$mediaId] = $source ? basename($source) : '';
            }
            if ($needMediaType) {
                $mediaType = (string) $media->mediaType();
                $mediaData['mediatype'][$mediaId] = $mediaType;
                $mediaData['mainmediatype'][$mediaId] = $mediaType ? strtok($mediaType, '/') : '';
            }
            if ($needExtension) {
                $mediaData['extension'][$mediaId] = (string) $media->extension();
            }
        }

        // Do the order.
        if (!$subOrder || !count(array_filter($mediaData[$subOrder], 'strlen'))) {
            // Manage order with a list.
            if (($mainOrder === 'mediatype' && $mediaTypes)
                || ($mainOrder === 'extension' && $extensions)
            ) {
                $newOrder = $this->orderWithList($mediaData, $mainOrder, $mediaTypes, $extensions);
            }
            // Simple one column order.
            else {
                $newOrder = $mediaData[$mainOrder];
                natcasesort($newOrder);
            }
        }
        // Do the order with two criterias.
        else {
            $firstOrder = $mediaData[$mainOrder];
            $secondOrder = $mediaData[$subOrder];

            // Remove extension first when needed.
            if ($removeExtension) {
                $cleanSub = $mainOrder === 'mediatype' || $mainOrder === 'extension';
                $cleanOrder = $cleanSub ? $secondOrder : $firstOrder;
                if (($cleanSub ? $subOrder : $mainOrder) === 'source') {
                    foreach ($cleanOrder as &$string) {
                        $extension = pathinfo($string, PATHINFO_EXTENSION);
                        $length = mb_strlen($extension);
                        if ($length) {
                            $string = mb_substr($string, 0, -$length - 1);
                        }
                    }
                } elseif (($cleanSub ? $subOrder : $mainOrder) === 'basename') {
                    foreach ($cleanOrder as &$string) {
                        $string = pathinfo($string, PATHINFO_FILENAME);
                    }
                }
                unset($string);
                $cleanSub ? ($secondOrder = $cleanOrder) : ($firstOrder = $cleanOrder);
            }

            // Create a single array to order.
            $toOrder = [];
            foreach ($firstOrder as $mediaId => $value) {
                $toOrder[$mediaId] = [
                    $value,
                    $secondOrder[$mediaId],
                    $mediaData['mainmediatype'][$mediaId],
                    // TODO Use media id to be consistent before php 8.0.
                    // $mediaId,
                ];
            }

            if (($mainOrder === 'mediatype' && !$mediaTypes)
                || ($mainOrder === 'extension' && !$extensions)
                || ($subOrder === 'mediatype' && !$mediaTypes)
                || ($subOrder === 'extension' && !$extensions)
            ) {
                $newOrder = $this->orderWithTwoCriteria($toOrder);
            } elseif ($mainOrder === 'mediatype' || $mainOrder === 'extension') {
                $newOrder = $this->orderWithListThenField($toOrder, $mainOrder, $subOrder, $mediaTypes, $extensions);
            } else {
                $newOrder = $this->orderWithFieldThenList($toOrder, $mainOrder, $subOrder, $mediaTypes, $extensions);
            }
        }

        // The media ids are the keys.
        // Keep only the position, starting from 1.
        $newOrder = array_keys($newOrder);
        array_unshift($newOrder, null);
        unset($newOrder[0]);
        $newOrder = array_flip($newOrder);

        if ($mediaData['position'] === $newOrder) {
            return;
        }

        $oMedias = [];
        foreach ($data['o:media'] as $oMedia) {
            $oMedias[$oMedia['o:id']] = $oMedia;
        }
        $data['o:media'] = array_values(array_replace($newOrder, $oMedias));
    }

    /**
     * Explode an item by media.
     *
     * This process cannot be done via a pre-process because the media are
     * reattached to another item. Furthermore, with standard api, items and
     * media may be resaved later. So doctrine may not be in sync.
     */
    protected function explodeItemByMedia(
        ItemAdapter $adapter,
        array $resourceIds,
        array $params
    ): void {
        $mode = $params['mode'];
        if (!in_array($mode, [
            'append',
            'update',
            'replace',
            'none',
        ])) {
            return;
        }

        /** @var \Omeka\Api\Manager $api */
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');
        $logger = $this->getServiceLocator()->get('Omeka\Logger');
        $properties = $this->getPropertyIds();
        $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4', '<');

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');

        $sqlMedia = <<<'SQL'
UPDATE media SET item_id = %1$d, position = 1 WHERE id = %2$d;
SQL;
        if (!$isOldOmeka) {
            $sqlMedia .= PHP_EOL . <<<'SQL'
UPDATE item SET primary_media_id = %2$d WHERE id = %1$d;
SQL;
        }

        foreach ($resourceIds as $resourceId) {
            try {
                /** @var \Omeka\Api\Representation\ItemRepresentation $item */
                $item = $api->read('items', ['id' => $resourceId], [], ['initialize' => false])->getContent();
            } catch (\Exception $e) {
                continue;
            }

            $medias = $item->media();
            // The process is done for metadata, even with only one media.
            if (!count($medias)) {
                continue;
            }

            $itemsAndMedias = [];

            // Keep current data as fully serialized data.
            // All data are copied for new items, included template, class, etc.
            // $currentItemData = $item->jsonSerialize();
            $currentItemData = json_decode(json_encode($item), true);

            $isFirstMedia = true;
            foreach ($medias as $media) {
                $itemData = $currentItemData;
                switch ($mode) {
                    default:
                    case 'append':
                        foreach ($media->values() as $term => $propertyData) {
                            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
                            foreach ($propertyData['values'] as $value) {
                                // $itemData[$term][] = $value->jsonSerialize();
                                $itemData[$term][] = json_decode(json_encode($value), true);
                            }
                        }
                        break;
                    case 'update':
                        foreach ($media->values() as $term => $propertyData) {
                            if (!empty($propertyData['values'])) {
                                $itemData[$term] = [];
                                foreach ($propertyData['values'] as $value) {
                                    // $itemData[$term][] = $value->jsonSerialize();
                                    $itemData[$term][] = json_decode(json_encode($value), true);
                                }
                            }
                        }
                        break;
                    case 'replace':
                        $itemData = array_diff_key($itemData, $properties);
                        foreach ($media->values() as $term => $propertyData) {
                            if (!empty($propertyData['values'])) {
                                $itemData[$term] = [];
                                foreach ($propertyData['values'] as $value) {
                                    // $itemData[$term][] = $value->jsonSerialize();
                                    $itemData[$term][] = json_decode(json_encode($value), true);
                                }
                            }
                        }
                        break;
                    case 'none':
                        break;
                }

                // The current item uses the first media.
                // The media are removed only when all other items are created.
                if ($isFirstMedia) {
                    $isFirstMedia = false;
                    // Store data for first item.
                    try {
                        $newItem = $api->update('items', ['id' => $resourceId], $itemData, [], ['initialize' => false, 'finalize' => false, 'isPartial' => true])->getContent();
                    } catch (\Exception $e) {
                        $logger->err(new Message(
                            'Item #%1$d cannot be exploded: %2$s', // @translate
                            $resourceId, $e->getMessage())
                        );
                        continue 2;
                    }
                    $itemsAndMedias[$newItem->getId()] = $media->id();
                }
                // Next ones are new items.
                else {
                    try {
                        $itemData['o:id'] = null;
                        $newItem = $api->create('items', $itemData, [], ['initialize' => false, 'finalize' => false, 'isPartial' => true])->getContent();
                    } catch (\Exception $e) {
                        $logger->err(new Message(
                            'Item #%1$d cannot be exploded: %2$s', // @translate
                            $resourceId, $e->getMessage())
                        );
                        continue 2;
                    }
                    $itemsAndMedias[$newItem->getId()] = $media->id();
                }
            }

            $sqls = '';
            foreach ($itemsAndMedias as $newItemId => $mediaId) {
                $sqls .= sprintf($sqlMedia, $newItemId, $mediaId) . PHP_EOL;
            }
            $connection->executeStatement($sqls);
        }
    }

    /**
     * Explode an pdf into images.
     *
     * This process cannot be done via a pre-process because the media are
     * reattached to another item. Furthermore, with standard api, items and
     * media may be resaved later. So doctrine may not be in sync.
     *
     * @see https://ghostscript.readthedocs.io/en/latest/Use.html#parameter-switches-d-and-s
     */
    protected function explodePdf(
        ItemAdapter $adapter,
        array $resourceIds,
        array $params
    ): void {
        /**
         * @var \Omeka\Stdlib\Cli $cli
         * @var \Omeka\Api\Manager $api
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\File\TempFileFactory $tempFileFactory
         */
        $services = $this->getServiceLocator();
        $cli = $services->get('Omeka\Cli');
        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        $tempFileFactory = $services->get('Omeka\File\TempFileFactory');

        $commandPath = $cli->validateCommand('/usr/bin', 'gs');
        if (!$commandPath) {
            $logger->err('Ghostscript is not available.'); // @translate
            return;
        }

        $tmpDir = $services->get('Config')['temp_dir'];
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        /**@see \ExtractOcr\Job\ExtractOcr::extractOcrForMedia() */
        // It's not possible to save a local file via the "upload" ingester. So
        // the ingester "url" can be used, but it requires the file to be in the
        // omeka files directory.
        // Else, use module FileSideload or inject sql.

        $this->basePath = $basePath;

        if (!$this->checkDir($tmpDir . '/bulkedit')) {
            $logger->err(new Message(
                'Unable to create temp directory "%s".', // @translate
                '/bulkedit'
            ));
            return;
        }

        $baseDestination = '/temp/bulkedit';
        if (!$this->checkDir($basePath . $baseDestination)) {
            $logger->err(new Message(
                'Unable to create temp directory "%s" inside "/files".', // @translate
                $baseDestination
            ));
            return;
        }

        $mode = $params['mode'] ?? 'all';
        $process = $params['process'] ?? 'all';

        $baseUri = empty($params['base_uri']) ? $this->getBaseUri() : $params['base_uri'];

        // Default is 72 in ghostscript, but it has bad output for native pdf.
        $resolution = empty($params['resolution']) ? 400 : (int) $params['resolution'];

        foreach ($resourceIds as $resourceId) {
            try {
                /** @var \Omeka\Api\Representation\ItemRepresentation $item */
                $item = $api->read('items', ['id' => $resourceId], [], ['initialize' => false])->getContent();
            } catch (\Exception $e) {
                continue;
            }

            $medias = $item->media();
            if (!count($medias)) {
                continue;
            }

            // To avoid issues with multiple pdf, get the list of pdf first.
            $pdfMedias = [];
            $imageJpegSourceNames = [];
            foreach ($medias as $media) {
                $mediaId = $media->id();
                $mediaType = $media->mediaType();
                if ($mediaType === 'application/pdf') {
                    $pdfMedias[$mediaId] = $media;
                } elseif ($mediaType === 'image/jpeg') {
                    $imageJpegSourceNames[$mediaId] = $media->source();
                }
            }
            if (!count($pdfMedias)) {
                continue;
            }

            if ($mode === 'first' && count($pdfMedias) > 1) {
                $pdfMedia = reset($pdfMedias);
                $pdfMedias = [$pdfMedia->id() => $pdfMedia];
            } elseif ($mode === 'last' && count($pdfMedias) > 1) {
                $pdfMedia = array_pop($pdfMedias);
                $pdfMedias = [$pdfMedia->id() => $pdfMedia];
            }

            $currentPosition = count($medias);

            $tmpDirResource = $tmpDir . '/bulkedit/' . $resourceId;
            $baseDestinationResource = '/temp/bulkedit/' . $resourceId;
            $filesTempDirResource = $basePath . $baseDestinationResource;

            foreach ($pdfMedias as $pdfMedia) {
                // To avoid space issue, files are removed after each loop.
                if (!$this->checkDir($tmpDirResource)) {
                    $logger->err(new Message(
                        'Unable to create temp directory "%1$s" for item #%2$d.', // @translate
                        '/bulkedit/' . $resourceId, $resourceId
                    ));
                    return;
                }

                if (!$this->checkDir($filesTempDirResource)) {
                    $logger->err(new Message(
                        'Unable to create temp directory "%1$s" inside "/files" for resource #%2$d.', // @translate
                        $baseDestinationResource, $resourceId
                    ));
                    return;
                }

                $filepath = $basePath . '/original/' . $pdfMedia->filename();
                $ready = file_exists($filepath) && is_readable($filepath) && filesize($filepath);
                if (!$ready) {
                    $logger->err(new Message(
                        'Unable to read pdf #%d.', // @translate
                        $pdfMedia->id()
                    ));
                    continue;
                }

                /** @see \Omeka\File\TempFile::getStorageId() */
                $storage = bin2hex(Rand::getBytes(16));

                // Sanitize source name, since it's reused as source for images.
                $sourceBasename = basename($pdfMedia->source(), '.pdf');
                $sourceBasename = $this->sanitizeName($sourceBasename);
                $sourceBasename = $this->convertNameToAscii($sourceBasename);

                $logger->info(new Message(
                    'Step 1/2 for item #%1$d, pdf #%2$d: Extracting pages as image.', // @translate
                    $resourceId, $pdfMedia->id()
                ));

                // Manage windows, that escapes argument differently (quote or
                // double quote).
                $tmpPath = escapeshellarg($tmpDirResource . '/' . $storage);
                $wrap = mb_substr($tmpPath, -1);
                $command = sprintf(
                    'gs -sDEVICE=jpeg -sOutputFile=%s -r%s -dNOTRANSPARENCY -dNOPAUSE -dQUIET -dBATCH %s',
                    $wrap . mb_substr($tmpPath, 1, -1) . '%04d.jpg' . $wrap,
                    (int) $resolution,
                    escapeshellarg($filepath)
                );
                $result = $cli->execute($command);
                if ($result === false) {
                    $logger->err(new Message(
                        'Unable to extract images from item #%1$d pdf #%2$d.', // @translate
                        $resourceId, $pdfMedia->id()
                    ));
                    continue;
                }

                $index = 0;
                $totalImages = 0;
                while (++$index) {
                    $source = sprintf('%s/%s%04d.jpg', $tmpDirResource, $storage, $index);
                    if (!file_exists($source)) {
                        break;
                    }
                    ++$totalImages;
                }

                if (!$totalImages) {
                    $logger->warn(new Message(
                        'For item #%1$d, pdf #%2$d cannot be exploded into images.', // @translate
                        $resourceId, $pdfMedia->id()
                    ));
                    continue;
                }

                // Create media from files and append them to item.
                $logger->info(new Message(
                    'Step 2/2 for item #%1$d, pdf #%2$d: Creating %3$d media.', // @translate
                    $resourceId, $pdfMedia->id(), $totalImages
                ));

                $index = 0;
                // $hasError = false;
                while (++$index) {
                    $source = sprintf('%s/%s%04d.jpg', $tmpDirResource, $storage, $index);
                    if (!file_exists($source)) {
                        break;
                    }

                    $destination = sprintf('%s/%s.%04d.jpg', $filesTempDirResource, $sourceBasename, $index);
                    $storageId = basename($source);
                    $sourceFilename = basename($destination);

                    if ($process === 'skip' && in_array($sourceFilename, $imageJpegSourceNames)) {
                        ++$currentPosition;
                        continue;
                    }

                    $result = @copy($source, $destination);
                    if (!$result) {
                        // $hasError = true;
                        $logger->err(new Message(
                            'File cannot be saved in temporary directory "%1$s" (temp file: "%2$s")', // @translate
                            basename($destination), $source
                        ));
                        break;
                    }

                    $fileImage = [
                        'filepath' => $destination,
                        'filename' => $sourceFilename,
                        'url' => $baseUri . $baseDestinationResource . '/' . $sourceFilename,
                        'url_file' => $baseDestinationResource . '/' . $sourceFilename,
                        'storageId' => $storageId,
                    ];

                    $data = [
                        'o:ingester' => 'url',
                        'o:item' => [
                            'o:id' => $resourceId,
                        ],
                        'o:source' => $sourceFilename,
                        'ingest_url' => $fileImage['url'],
                        'file_index' => 0,
                        'values_json' => '{}',
                        'o:lang' => null,
                        'position' => ++$currentPosition,
                    ];

                    // TODO Extract ocr into extracted text. See ExtractOcr.
                    // TODO Extract Alto.

                    try {
                        $media = $api->create('media', $data, [])->getContent();
                    } catch (\Omeka\Api\Exception\ExceptionInterface $e) {
                        // Generally a bad or missing pdf file.
                        // $hasError = true;
                        $logger->err($e->getMessage() ?: $e);
                        break;
                    } catch (\Exception $e) {
                        // $hasError = true;
                        $logger->err($e);
                        break;
                    }
                }

                // Delete temp files in all cases.
                $this->rmDir($tmpDirResource);
                $this->rmDir($baseDestinationResource);
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
                    $isValidRegex = @preg_match($from, '') !== false;
                    if (!$isValidRegex) {
                        $this->getServiceLocator()->get('Omeka\Logger')
                            ->err('Update media html: Invalid regex.'); // @translate
                        return;
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
            ], [
                'position' => 'ASC',
                'id' => 'ASC',
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

        $dom = $this->fetchUrlXml($url);
        if (!$dom) {
            return null;
        }

        $xpath = new \DOMXPath($dom);

        switch ($datatype) {
            case 'valuesuggest:geonames:geonames':
                // Probably useless.
                $xpath->registerNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                $xpath->registerNamespace('gn', 'http://www.geonames.org/ontology#');
                break;
            default:
                break;
        }

        $queries = (array) $endpointData['path'];
        foreach (array_filter($queries) as $query) {
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
                    // If there is a language, use it first, else use name.
                    $language ? '/rdf:RDF/gn:Feature/gn:officialName[@xml:lang="' . $language . '"][1]' : null,
                    $language ? '/rdf:RDF/gn:Feature/gn:alternateName[@xml:lang="' . $language . '"][1]' : null,
                    '/rdf:RDF/gn:Feature/gn:name[1]',
                    '/rdf:RDF/gn:Feature/gn:shortName[1]',
                    '/rdf:RDF/gn:Feature/gn:officialName[1]',
                    '/rdf:RDF/gn:Feature/gn:alternateName[1]',
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

    protected function fetchUrl(string $url): ?string
    {
        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Laminas\Http\Client $httpClient
         */
        static $logger;
        static $httpClient;

        if (!$logger) {
            $services = $this->getServiceLocator();
            $logger = $services->get('Omeka\Logger');
            // Use omeka http client instead of the simple static client.
            $httpClient = $services->get('Omeka\HttpClient');
        }

        $headers = [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:115.0) Gecko/20100101 Firefox/115.0',
            'Content-Type' => 'application/xml',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        // TODO Should we reset cookies each time?
        $httpClient
            ->reset()
            ->setUri($url)
            ->setHeaders($headers);

        try {
            $response = $httpClient->send();
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

        $string = $response->getBody();
        if (!strlen($string)) {
            $logger->warn(new Message(
                'Output is empty for url "%s".', // @translate
                $url
            ));
        }

        return $string;
    }

    protected function fetchUrlXml(string $url): ?\DOMDocument
    {
        static $logger = null;

        if (!$logger) {
            $logger = $this->getServiceLocator()->get('Omeka\Logger');
        }

        $xml = $this->fetchUrl($url);
        if (!$xml) {
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
                'Output is not a valid xml for url "%s".', // @translate
                $url
            ));
            return null;
        }

        return $doc;
    }

    protected function orderWithTwoCriteria($toOrder): array
    {
        $cmp = function($a, $b) {
            return strcasecmp($a[0], $b[0])
                ?: strcasecmp($a[1], $b[1]);
        };
        uasort($toOrder, $cmp);
        return $toOrder;
    }

    protected function orderWithList($data, $mainOrder, $mediaTypes, $extensions): array
    {
        $newOrder = [];

        if ($mainOrder === 'mediatype') {
            // Order each media type separately.
            foreach ($mediaTypes as $mediaType) {
                // Get all medias with the specified media type and not
                // already filtered.
                $mainOrderType = strpos($mediaType, '/') ? 'mediatype' : 'mainmediatype';
                foreach ($data[$mainOrderType] as $mediaId => $mediaMediaType) {
                    if ($mediaMediaType === $mediaType && !isset($newOrder[$mediaId])) {
                        $newOrder[$mediaId] = $mediaId;
                    }
                }
            }
        } else {
            // Order each extensions separately.
            foreach ($extensions as $extension) {
                foreach ($data['extension'] as $mediaId => $mediaExtension) {
                    if ($mediaExtension === $extension && !isset($newOrder[$mediaId])) {
                        $newOrder[$mediaId] = $mediaId;
                    }
                }
            }
        }

        // Other media are ordered alphabetically.
        $remainingOrder = array_diff_key($data[$mainOrder], $newOrder);
        if (count($remainingOrder)) {
            natcasesort($remainingOrder);
            $newOrder += $remainingOrder;
        }

        return $newOrder;
    }

    protected function orderWithListThenField($toOrder, $mainOrder, $subOrder, $mediaTypes, $extensions): array
    {
        if (($mainOrder === 'mediatype' && !$mediaTypes)
            || ($mainOrder === 'extension' && !$extensions)
        ) {
            return $this->orderWithTwoCriteria($toOrder);
        }

        $newOrder = [];

        if ($mainOrder === 'mediatype') {
            // Order each media type separately.
            foreach ($mediaTypes as $mediaType) {
                // Get all medias with the specified media type and not
                // already filtered.
                $partialOrder = [];
                $mainOrderType = strpos($mediaType, '/') ? 0 : 2;
                foreach ($toOrder as $mediaId => $mediaData) {
                    if ($mediaData[$mainOrderType] === $mediaType && !isset($newOrder[$mediaId])) {
                        $partialOrder[$mediaId] = $mediaData[1];
                    }
                }
                // Order the partial order with the second field.
                uasort($partialOrder, 'strcasecmp');
                $newOrder += $partialOrder;
            }
        } else {
            // Order each media type separately.
            foreach ($extensions as $extension) {
                // Get all medias with the specified media type and not
                // already filtered.
                $partialOrder = [];
                foreach ($toOrder as $mediaId => $mediaData) {
                    if ($mediaData[0] === $extension && !isset($newOrder[$mediaId])) {
                        $partialOrder[$mediaId] = $mediaData[1];
                    }
                }
                // Order the partial order with the second field.
                uasort($partialOrder, 'strcasecmp');
                $newOrder += $partialOrder;
            }
        }

        // Other media are ordered alphabetically.
        $remainingOrder = array_diff_key($toOrder, $newOrder);
        if (count($remainingOrder)) {
            $newOrder += $this->orderWithTwoCriteria($remainingOrder);
        }

        return $newOrder;
    }

    protected function orderWithFieldThenList($toOrder, $mainOrder, $subOrder, $mediaTypes, $extensions): array
    {
        if (($subOrder === 'mediatype' && !$mediaTypes)
            || ($subOrder === 'extension' && !$extensions)
        ) {
            return $this->orderWithTwoCriteria($toOrder);
        }

        if ($subOrder === 'mediatype') {
            $cmp = function ($a, $b) use ($mediaTypes) {
                $result = strcasecmp($a[0], $b[0]);
                if ($result) {
                    return $result;
                }
                foreach ($mediaTypes as $mediaType) {
                    $mainOrderType = strpos($mediaType, '/') ? 1 : 2;
                    $aa = $a[$mainOrderType];
                    $bb = $b[$mainOrderType];
                    if ($aa === $mediaType && $bb !== $mediaType) {
                        return -1;
                    } elseif ($aa !== $mediaType && $bb === $mediaType) {
                        return 1;
                    }
                }
                return strcasecmp($a[1], $b[1]);
            };

        } else {
            $cmp = function ($a, $b) use ($extensions) {
                $result = strcasecmp($a[0], $b[0]);
                if ($result) {
                    return $result;
                }
                foreach ($extensions as $extension) {
                    if ($a[1] === $extension && $b[1] !== $extension) {
                        return -1;
                    } elseif ($a[1] !== $extension && $b[1] === $extension) {
                        return 1;
                    }
                }
                return strcasecmp($a[1], $b[1]);
            };
        }

        uasort($toOrder, $cmp);

        return $toOrder;
    }

    /**
     * Save a temp file into the files/temp directory.
     *
     * @see \DerivativeMedia\Module::makeTempFileDownloadable()
     * @see \Ebook\Mvc\Controller\Plugin\Ebook::saveFile()
     * @see \ExtractOcr\Job\ExtractOcr::makeTempFileDownloadable()
     */
    protected function makeTempFileDownloadable(TempFile $tempFile, $base = '')
    {
        $baseDestination = '/temp';
$destinationDir = $this->basePath . $baseDestination . $base;
        if (!$this->checkDir($destinationDir)) {
            return null;
        }

        $source = $tempFile->getTempPath();

        // Find a unique meaningful filename instead of a hash.
        $name = date('Ymd_His') . '_pdf2jpg';
        $extension = 'jpg';
        $i = 0;
        do {
            $filename = $name . ($i ? '-' . $i : '') . '.' . $extension;
            $destination = $destinationDir . '/' . $filename;
            if (!file_exists($destination)) {
                $result = @copy($source, $destination);
                if (!$result) {
                        $this->getServiceLocator()->get('Omeka\Logger')->err(new Message('File cannot be saved in temporary directory "%1$s" (temp file: "%2$s")', // @translate
                        $destination, $source
                    ));
                    return null;
                }
                $storageId = $base . $name . ($i ? '-' . $i : '');
                break;
            }
        } while (++$i);

        return [
            'filepath' => $destination,
            'filename' => $filename,
            'url' => $this->baseUri . $baseDestination . $base . '/' . $filename,
            'url_file' => $baseDestination . $base . '/' . $filename,
            'storageId' => $storageId,
        ];
    }

    /**
     * @todo To get the base uri is useless now, since base uri is passed as job argument.
     */
    protected function getBaseUri()
    {
        $services = $this->getServiceLocator();
        $config = $services->get('Config');
        $baseUri = $config['file_store']['local']['base_uri'];
        if (!$baseUri) {
            $helpers = $services->get('ViewHelperManager');
            $serverUrlHelper = $helpers->get('serverUrl');
            $basePathHelper = $helpers->get('basePath');
            $baseUri = $serverUrlHelper($basePathHelper('files'));
            if ($baseUri === 'http:///files' || $baseUri === 'https:///files') {
                $t = $services->get('MvcTranslator');
                throw new \Omeka\Mvc\Exception\RuntimeException(
                    sprintf(
                        $t->translate('The base uri is not set (key [file_store][local][base_uri]) in the config file of Omeka "config/local.config.php". It must be set for now (key [file_store][local][base_uri]) in order to process background jobs.'), //@translate
                        $baseUri
                    )
                );
            }
        }
        return $baseUri;
    }

    /**
     * Check or create the destination folder.
     *
     * @param string $dirPath
     * @return bool
     */
    protected function checkDir($dirPath)
    {
        if (!file_exists($dirPath)) {
            if (!is_writeable($this->basePath)) {
                return false;
            }
            @mkdir($dirPath, 0755, true);
        } elseif (!is_dir($dirPath) || !is_writeable($dirPath)) {
            return false;
        }
        return true;
    }

    /**
     * Remove a dir from filesystem.
     *
     * @param string $dirpath Absolute path.
     */
    private function rmDir(string $dirPath): bool
    {
        if (!file_exists($dirPath)) {
            return true;
        }
        if (strpos($dirPath, '/..') !== false || substr($dirPath, 0, 1) !== '/') {
            return false;
        }
        $files = array_diff(scandir($dirPath), ['.', '..']);
        foreach ($files as $file) {
            $path = $dirPath . '/' . $file;
            if (is_dir($path)) {
                $this->rmDir($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dirPath);
    }

    /**
     * Returns a sanitized string for folder or file path.
     *
     * The string should be a simple name, not a full path or url, because "/",
     * "\" and ":" are removed (so a path should be sanitized by part).
     *
     * @param string $string The string to sanitize.
     * @return string The sanitized string.
     */
    protected function sanitizeName($string): string
    {
        $string = strip_tags((string) $string);
        // The first character is a space and the last one is a no-break space.
        $string = trim($string, ' /\\?<>:*%|"\'`&; ');
        $string = str_replace(['(', '{'], '[', $string);
        $string = str_replace([')', '}'], ']', $string);
        $string = preg_replace('/[[:cntrl:]\/\\\?<>:\*\%\|\"\'`\&\;#+\^\$\s]/', ' ', $string);
        return substr(preg_replace('/\s+/', ' ', $string), -180);
    }

    /**
     * Returns an unaccentued string for folder or file name.
     *
     * Note: The string should be already sanitized.
     *
     * See \ArchiveRepertoryPlugin::convertFilenameTo()
     *
     * @param string $string The string to convert to ascii.
     * @return string The converted string to use as a folder or a file name.
     */
    protected function convertNameToAscii($string): string
    {
        $string = htmlentities($string, ENT_NOQUOTES, 'utf-8');
        $string = preg_replace('#\&([A-Za-z])(?:acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml|caron)\;#', '\1', $string);
        $string = preg_replace('#\&([A-Za-z]{2})(?:lig)\;#', '\1', $string);
        $string = preg_replace('#\&[^;]+\;#', '_', $string);
        $string = preg_replace('/[^[:alnum:]\[\]_\-\.#~@+:]/', '_', $string);
        return substr(preg_replace('/_+/', '_', $string), -180);
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
