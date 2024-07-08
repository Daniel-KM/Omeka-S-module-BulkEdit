<?php declare(strict_types=1);

namespace BulkEdit;

if (!class_exists(\Common\TraitModule::class)) {
    require_once dirname(__DIR__) . '/Common/TraitModule.php';
}

use BulkEdit\Form\BulkEditFieldset;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Module\AbstractModule;

/**
 * BulkEdit
 *
 * Improve the bulk edit process with new features.
 *
 * @copyright Daniel Berthereau, 2018-2024
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translate = $services->get('ControllerPluginManager')->get('translate');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.60')) {
            $message = new \Omeka\Stdlib\Message(
                $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.60'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

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
            // TODO Checks api.preprocess_batch_update and api.batch_update.pre. See module Access.
            $sharedEventManager->attach(
                $adapter,
                'api.update.pre',
                [$this, 'handleResourceUpdatePreBatchUpdate']
            );
            // Nevertheless, some processes can be done one time or via sql
            // queries.
            // Furthermore, for media:
            // Because the media source or media type cannot be updated via api,
            // a final sql request of flush is required in that case.
            /** @see \Omeka\Api\Adapter\MediaAdapter::hydrate() */
            $sharedEventManager->attach(
                $adapter,
                'api.batch_update.post',
                [$this, 'handleResourcesBatchUpdatePost']
            );
            $sharedEventManager->attach(
                $adapter,
                'api.batch_update.post',
                [$this, 'handleResourcesBatchUpdatePostFlush'],
                // Less prioritary, so process it after.
                -50
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

        /* TODO Finalize use of element groups (keeping fieldsets, that is the normal way to group elements). And keep sub-fieldsets.
        $fieldsetElementGroups = $fieldset->getOption('element_groups');
        $form->setOption('element_groups', array_merge($form->getOption('element_groups') ?: [], $fieldsetElementGroups));
        foreach ($fieldset->getFieldsets() as $subFieldset) {
            $form->add($subFieldset);
        }
        foreach ($fieldset->getElements() as $element) {
            $form->add($element);
        }
        */

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
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $deduplicationOnSave = (bool) $settings->get('bulkedit_deduplicate_on_save');

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $easyMeta = $services->get('EasyMeta');

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        $trimUnicode = fn ($v): string => (string) preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', (string) $v);

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
                    // Some data types may have an array for value.
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
                || !$easyMeta->propertyId($term)
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
            'media_source' => null,
            'media_type' => null,
            'media_visibility' => null,
            // Then simple queries.
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_empty_values' => null,
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
        $processes = $this->getProcessesToBatchUpdateDirectly($event);
        if (!count($processes)) {
            return;
        }

        /**
         * @var \Omeka\Api\Adapter\AbstractResourceEntityAdapter $adapter,
         * @var \Omeka\Api\Request $request
         */
        $adapter = $event->getTarget();
        $request = $event->getParam('request');
        $ids = (array) $request->getIds();
        $this->updateResourcesPost($adapter, $ids, $processes);
    }

    public function handleResourcesBatchUpdatePostFlush(Event $event): void
    {
        $processes = $this->getProcessesToBatchUpdateDirectly($event);
        if (!count($processes)) {
            return;
        }

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $entityManager->flush();
        // The entity manager clear is required to avoid a doctrine issue about
        // non-persisted new entity.
        $entityManager->clear();
    }

    protected function getProcessesToBatchUpdateDirectly(Event $event): array
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
            return [];
        }

        $data = $request->getContent('data');
        if (empty($data['bulkedit'])) {
            return [];
        }

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $ids = (array) $request->getIds();
        if (empty($ids)) {
            return [];
        }

        $resourceName = $request->getResource();

        $postProcesses = [
            'items' => [
                'explode_item' => null,
                'explode_pdf' => null,
                'media_html' => null,
                'media_source' => null,
                'media_type' => null,
                'media_visibility' => null,
            ],
            'media' => [
                'media_html' => null,
                'media_source' => null,
                'media_type' => null,
                'media_visibility' => null,
            ],
        ];

        $postProcessesResource = $postProcesses[$resourceName] ?? [];

        $postProcesses = array_merge($postProcessesResource, [
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_empty_values' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ]);

        $processes = $this->prepareProcesses();
        return array_intersect_key($processes, $postProcesses);
    }

    /**
     * Finalize direct processes using entity manager without flush.
     */
    public function handleResourcesBatchUpdateFlush(Event $event): void
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
                'media_source' => null,
                'media_type' => null,
                'media_visibility' => null,
            ],
            'media' => [
                'media_html' => null,
                'media_source' => null,
                'media_type' => null,
                'media_visibility' => null,
            ],
        ];

        $postProcessesResource = $postProcesses[$resourceName] ?? [];

        $postProcesses = array_merge($postProcessesResource, [
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_empty_values' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ]);

        $processes = $this->prepareProcesses();
        $bulkedit = array_intersect_key($processes, $postProcesses);
        if (!count($bulkedit)) {
            return;
        }

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $entityManager->flush();
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
            'copy' => null,
            'displace' => null,
            'explode' => null,
            'merge' => null,
            'convert' => null,
            'order_values' => null,
            'properties_visibility' => null,
            'fill_data' => null,
            'fill_values' => null,
            'remove' => null,
            'thumbnails' => null,
            'explode_item' => null,
            'explode_pdf' => null,
            'media_remove' => null,
            'media_order' => null,
            'media_html' => null,
            'media_source' => null,
            'media_type' => null,
            'media_visibility' => null,
            // Cleaning is done separately.
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_empty_values' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ];

        $params = $bulkedit['replace'] ?? [];
        if (!empty($params['mode']) && !empty($params['properties'])) {
            $processes['replace'] = [
                'mode' => $params['mode'],
                'from' => $params['from'] ?? '',
                'to' => $params['to'] ?? '',
                'prepend' => ltrim($params['prepend'] ?? ''),
                'append' => rtrim($params['append'] ?? ''),
                'language' => trim($params['language'] ?? ''),
                'language_clear' => !empty($params['language_clear']),
                'properties' => $params['properties'],
            ];
        }

        $params = $bulkedit['copy'] ?? [];
        if (!empty($params['from'])) {
            $to = $params['to'];
            if (mb_strlen($to)) {
                $processes['copy'] = [
                    'from' => $params['from'],
                    'to' => $to,
                    'datatypes' => $params['datatypes'] ?: [],
                    'languages' => $params['languages'] ?: [],
                    'visibility' => $params['visibility'],
                    'contains' => $params['contains'],
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
                    'languages' => $params['languages'] ?: [],
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
                'resource_value_preprocess' => $params['resource_value_preprocess'],
                'resource_properties' => $params['resource_properties'],
                'uri_extract_label' => !empty($params['uri_extract_label']),
                'uri_label' => $params['uri_label'],
                'uri_language' => $params['uri_language'],
                'uri_base_site' => $params['uri_base_site'],
                'contains' => $params['contains'],
            ];
        }

        $params = $bulkedit['order_values'] ?? [];
        if (!empty($params['languages'])) {
            $languages = array_filter($params['languages']);
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
                'languages' => $params['languages'] ?: [],
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
                'featured_subject' => !empty($params['featured_subject']),
            ];
            // TODO Use a job only to avoid to fetch the same values multiple times or prefill values.
            // $this->preFillValues($processes['fill_values']);
        }

        $params = $bulkedit['remove'] ?? [];
        if (!empty($params['properties'])) {
            $processes['remove'] = [
                'properties' => $params['properties'],
                'datatypes' => $params['datatypes'] ?? [],
                'languages' => $params['languages'] ?? [],
                'visibility' => $params['visibility'],
                'equal' => $params['equal'],
                'contains' => $params['contains'],
            ];
        }

        $params = $bulkedit['thumbnails'] ?? [];
        if (!empty($params['mode'])
            && in_array($params['mode'], ['fill', 'append', 'append_no_primary', 'append_no_primary_no_thumbnail', 'replace', 'remove', 'remove_primary', 'delete'])
        ) {
            $processes['thumbnails'] = [
                'mode' => $params['mode'],
                'asset' => $params['asset'] ?? null ,
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
                'processor' => $params['processor'] ?? 'auto',
                // TODO Use server-url from job.
                'base_uri' => $this->getBaseUri(),
            ];
        }

        $params = $bulkedit['media_remove'] ?? [];
        $mode = $params['mode'] ?? '';
        if ($mode) {
            $processes['media_remove'] = [
                'mode' => $mode,
                'mediatypes' => $params['mediatypes'] ?? [],
                'extensions' => $params['extensions'] ?? [],
                // 'query' => $params['query'] ?? '',
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
        if (!empty($params['mode'])) {
            $mode = $params['mode'];
            $from = $params['from'] ?? '';
            $to = $params['to'] ?? '';
            // TODO Add the check of the validity of the regex in the form.
            // Early check validity of the regex.
            if ($mode !== 'regex' || @preg_match($from, '') !== false) {
                $processes['media_html'] = [
                    'mode' => $mode,
                    'from' => $from,
                    'to' => $to,
                    'prepend' => ltrim($params['prepend'] ?? ''),
                    'append' => rtrim($params['append'] ?? ''),
                ];
            }
        }

        $params = $bulkedit['media_source'] ?? [];
        if (!empty($params['mode'])) {
            $mode = $params['mode'];
            $from = $params['from'] ?? '';
            $to = $params['to'] ?? '';
            // TODO Add the check of the validity of the regex in the form.
            // Early check validity of the regex.
            if ($mode !== 'regex' || @preg_match($from, '') !== false) {
                $processes['media_source'] = [
                    'from' => $from,
                    'to' => $to,
                    'mode' => $mode,
                    'prepend' => ltrim($params['prepend'] ?? ''),
                    'append' => rtrim($params['append'] ?? ''),
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
        $processes['clean_empty_values'] = empty($bulkedit['cleaning']['clean_empty_values']) ? null : true;
        $processes['clean_languages'] = empty($bulkedit['cleaning']['clean_languages']) ? null : true;
        $processes['deduplicate_values'] = empty($bulkedit['cleaning']['deduplicate_values']) ? null : true;

        $processes = array_filter($processes);
        if (!count($processes)) {
            return [];
        }

        $this->getServiceLocator()->get('Omeka\Logger')->info(
            "Cleaned params used for bulk edit:\n{json}", // @translate
            ['json' => json_encode($processes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS)]
        );

        return $processes;
    }

    /**
     * Run process for a single resource.
     */
    protected function updateResourcePre(
        AbstractResourceEntityAdapter $adapter,
        AbstractResourceEntityRepresentation $resource,
        array $dataToUpdate,
        array $processes
    ): array {
        /** @var \BulkEdit\Stdlib\BulkEdit $bulkEdit */
        $bulkEdit = $this->getServiceLocator()->get('BulkEdit');

        // It's simpler to process data as a full array.
        $data = json_decode(json_encode($resource), true);

        // Keep data that may have been added during batch pre-process.
        $data = array_replace($data, $dataToUpdate);

        // Note: $data is passed by reference to each process.
        foreach ($processes as $process => $params) switch ($process) {
            case 'replace':
                $bulkEdit->updateValuesForResource($resource, $data, $params);
                break;
            case 'copy':
                $bulkEdit->copyOrDisplaceValuesForResource($resource, $data, $params, false);
                break;
            case 'displace':
                $bulkEdit->copyOrDisplaceValuesForResource($resource, $data, $params, true);
                break;
            case 'explode':
                $bulkEdit->explodeValuesForResource($resource, $data, $params);
                break;
            case 'merge':
                $bulkEdit->mergeValuesForResource($resource, $data, $params);
                break;
            case 'convert':
                $bulkEdit->convertDataTypeForResource($resource, $data, $params);
                break;
            case 'order_values':
                $bulkEdit->orderValuesForResource($resource, $data, $params);
                break;
            case 'properties_visibility':
                $bulkEdit->applyVisibilityForResourceValues($resource, $data, $params);
                break;
            case 'fill_data':
                $bulkEdit->fillDataForResource($resource, $data, $params);
                break;
            case 'fill_values':
                $bulkEdit->fillValuesForResource($resource, $data, $params);
                break;
            case 'remove':
                $bulkEdit->removeValuesForResource($resource, $data, $params);
                break;
            case 'thumbnails':
                $bulkEdit->manageThumbnail($resource, $data, $params);
                break;
            case 'media_remove':
                $bulkEdit->removeMediaFromItems($resource, $data, $params);
                break;
            case 'media_order':
                $bulkEdit->updateMediaOrderForResource($resource, $data, $params);
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
        AbstractResourceEntityAdapter $adapter,
        array $resourceIds,
        array $processes
    ): void {
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');

        /** @var \BulkEdit\Stdlib\BulkEdit $bulkEdit */
        $bulkEdit = $services->get('BulkEdit');

        // These processes are specific, because they use a direct sql.

        foreach (array_filter($processes) as $process => $params) switch ($process) {
            case 'trim_values':
                /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
                $trimValues = $plugins->get('trimValues');
                $trimValues($resourceIds);
                break;
            case 'specify_datatypes':
                /** @var \BulkEdit\Mvc\Controller\Plugin\SpecifyDataTypeResources $specifyDataTypeResources */
                $specifyDataTypeResources = $plugins->get('specifyDataTypeResources');
                $specifyDataTypeResources($resourceIds);
                break;
            case 'clean_empty_values':
                /** @var \BulkEdit\Mvc\Controller\Plugin\CleanEmptyValues $cleanEmptyValues */
                $cleanEmptyValues = $plugins->get('cleanEmptyValues');
                $cleanEmptyValues($resourceIds);
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
                $bulkEdit->explodeItemByMedia($adapter, $resourceIds, $params);
                break;
            case 'explode_pdf':
                $bulkEdit->explodePdf($adapter, $resourceIds, $params);
                break;
            case 'media_html':
                $bulkEdit->updateMediaHtmlForResources($adapter, $resourceIds, $params);
                break;
            case 'media_source':
                $bulkEdit->updateMediaSourceForResources($adapter, $resourceIds, $params);
                break;
            case 'media_type':
                $bulkEdit->updateMediaTypeForResources($adapter, $resourceIds, $params);
                break;
            case 'media_visibility':
                $bulkEdit->updateMediaVisibilityForResources($adapter, $resourceIds, $params);
                break;
            default:
                break;
        }
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
}
