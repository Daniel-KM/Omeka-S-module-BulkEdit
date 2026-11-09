<?php declare(strict_types=1);

namespace BulkEdit;

// Common may be installed but not registered in autoloader, in particular
// during upgrade. So dynamically register all classes of the module.
if (!defined('COMMON_PSR4_FALLBACK')) {
    foreach ([
        OMEKA_PATH . '/modules/Common/src',
        OMEKA_PATH . '/composer-addons/modules/Common/src',
        dirname(__DIR__) . '/Common/src',
    ] as $commonSrc) {
        if (file_exists($commonSrc . '/TraitModule.php')) {
            define('COMMON_PSR4_FALLBACK', $commonSrc);
            spl_autoload_register(static function ($class): void {
                if (str_starts_with($class, 'Common\\')) {
                    $file = COMMON_PSR4_FALLBACK . '/' . strtr(substr($class, 7), '\\', '/') . '.php';
                    if (file_exists($file)) {
                        require_once $file;
                    }
                }
            });
            break;
        }
    }
}

use BulkEdit\Form\BulkEditFieldset;
use Common\TraitModule;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Module\AbstractModule;

/**
 * BulkEdit
 *
 * Improve the bulk edit process with new features.
 *
 * @copyright Daniel Berthereau, 2018-2026
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    use TraitModule;

    const NAMESPACE = __NAMESPACE__;

    protected function preInstall(): void
    {
        $services = $this->getServiceLocator();
        $translator = $services->get('MvcTranslator');

        if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.94')) {
            $message = new \Omeka\Stdlib\Message(
                $translator->translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
                'Common', '3.4.94'
            );
            throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
        }
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        // The preview reads resources via the api only.
        $this->getServiceLocator()->get('Omeka\Acl')->allow(
            [
                \Omeka\Permissions\Acl::ROLE_AUTHOR,
                \Omeka\Permissions\Acl::ROLE_REVIEWER,
                \Omeka\Permissions\Acl::ROLE_EDITOR,
                \Omeka\Permissions\Acl::ROLE_SITE_ADMIN,
            ],
            ['BulkEdit\Controller\Admin\Index']
        );
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $adapters = [
            \Omeka\Api\Adapter\ItemAdapter::class,
            \Omeka\Api\Adapter\ItemSetAdapter::class,
            \Omeka\Api\Adapter\MediaAdapter::class,
            \Annotate\Api\Adapter\AnnotationAdapter::class,
            \DigitalObject\Api\Adapter\DigitalObjectAdapter::class,
            \Thesaurus\Api\Adapter\ConceptAdapter::class,
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

        $sharedEventManager->attach(
            \Omeka\Media\Ingester\Manager::class,
            'service.registered_names',
            [$this, 'handleMediaIngesterRegisteredNames']
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
        /**
         * @var \Common\Stdlib\EasyMeta $easyMeta
         * @var \Omeka\Api\Request $request
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $deduplicationOnSave = (bool) $settings->get('bulkedit_deduplicate_on_save');
        $easyMeta = $services->get('Common\EasyMeta');
        $request = $event->getParam('request');

        $data = $request->getContent();

        $trimUnicode = fn ($v): string => (string) preg_replace('/^\s+|\s+$/u', '', (string) $v);

        // Trimming.
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (empty($values)
                || !is_array($values)
                || !$easyMeta->propertyId($term)
            ) {
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
        $resourceNameToTypes = [
            'items' => 'resource:item',
            'media' => 'resource:media',
            'item_sets' => 'resource:itemset',
            // Modules with a specific resource type.
            'annotations' => 'resource:annotation',
            'concepts' => 'resource:concept',
            'digital_objects' => 'resource:digitalobject',
        ];
        // Get the linked resources with a single query, not one by value.
        $linkedIds = [];
        foreach ($data as $term => $values) {
            if (!empty($values) && is_array($values) && $easyMeta->propertyId($term)) {
                foreach ($values as $value) {
                    if (is_array($value) && ($value['type'] ?? null) === 'resource' && !empty($value['value_resource_id'])) {
                        $linkedIds[(int) $value['value_resource_id']] = null;
                    }
                }
            }
        }
        if ($linkedIds) {
            /** @var \Omeka\Entity\Resource[] $linkedResources */
            $linkedResources = $this->getServiceLocator()->get('Omeka\EntityManager')
                ->getRepository(\Omeka\Entity\Resource::class)
                ->findBy(['id' => array_keys($linkedIds)]);
            foreach ($linkedResources as $linkedResource) {
                $linkedIds[$linkedResource->getId()] = $resourceNameToTypes[$linkedResource->getResourceName()] ?? null;
            }
            foreach ($data as $term => &$values) {
                if (empty($values) || !is_array($values) || !$easyMeta->propertyId($term)) {
                    continue;
                }
                foreach ($values as &$value) {
                    if (is_array($value)
                        && ($value['type'] ?? null) === 'resource'
                        && !empty($linkedIds[(int) ($value['value_resource_id'] ?? 0)])
                    ) {
                        $value['type'] = $linkedIds[(int) $value['value_resource_id']];
                    }
                }
                unset($value);
            }
            unset($values);
        }

        // Deduplicating.
        if ($deduplicationOnSave) {
            foreach ($data as $term => &$values) {
                // Process properties only.
                if (empty($values)
                    || !is_array($values)
                    || !$easyMeta->propertyId($term)
                ) {
                    continue;
                }
                $first = reset($values);
                if (empty($first['property_id'])) {
                    continue;
                }
                // Reorder all keys of all the values to simplify strict check.
                foreach ($values as &$value) {
                    // Sometime, a module may insert a bad value.
                    if (is_array($value)) {
                        ksort($value);
                    }
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
        // The event is attached to all controllers, but users have no values.
        $controller = $this->getServiceLocator()->get('Application')->getMvcEvent()
            ->getRouteMatch()->getParam('controller');
        if (in_array($controller, ['user', 'Omeka\Controller\Admin\User'])) {
            return;
        }

        $view = $event->getTarget();
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/bulk-edit.css', 'BulkEdit'));
        $view->headScript()
            ->appendFile($assetUrl('js/bulk-edit.js', 'BulkEdit'), 'text/javascript', ['defer' => 'defer'])
            ->appendFile($assetUrl('js/bulk-edit-create-values.js', 'BulkEdit'), 'text/javascript', ['defer' => 'defer']);

        // The templates of the resource form are used to create values. They
        // are outside of the form, so they are not submitted.
        // Value Suggest prepares only the resource forms.
        if ($this->isModuleActive('ValueSuggest')) {
            $valueSuggest = $this->getServiceLocator()->get('ModuleManager')->getModule('ValueSuggest');
            if ($valueSuggest && method_exists($valueSuggest, 'prepareResourceForm')) {
                $valueSuggest->prepareResourceForm(new Event('view.edit.after', $view));
            }
        }

        $dataTypeSelect = $this->getServiceLocator()->get('FormElementManager')
            ->get(\Common\Form\Element\DataTypeSelect::class)
            ->setName('bulkedit_add_value')
            ->setEmptyOption('')
            ->setAttributes([
                'class' => 'bulkedit-add-value',
                'data-placeholder' => $view->translate('Other data type…'), // @translate
            ]);
        echo '<div id="bulkedit-templates" hidden data-preview-url="' . $view->escapeHtmlAttr($view->url('admin/bulk-edit-preview')) . '">'
            . $view->partial('common/resource-form-templates.phtml')
            . $view->formSelect($dataTypeSelect)
            . '</div>';
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
        $rawBulkedit = $request->getValue('bulkedit');
        $bulkedit = $this->prepareProcesses($rawBulkedit);

        // Only the controller of the batch edit of selected resources disables
        // the detachment of entities: it is the only synchronous process. Jobs
        // never set it, so a job cannot dispatch itself in loop.
        if ($bulkedit
            && $request->getOption('detachEntities') === false
            && $request->getOption('collectionAction', 'replace') === 'replace'
        ) {
            $slowProcesses = $this->getSlowProcesses($bulkedit, $request->getResource());
            if ($slowProcesses) {
                $content = $request->getContent();
                $content['bulkedit'] = array_diff_key($rawBulkedit, $slowProcesses);
                $request->setContent($content);
                $request->setOption('bulkedit_background', array_intersect_key($rawBulkedit, $slowProcesses));
                $bulkedit = $this->prepareProcesses($content['bulkedit']);
            }
        }

        if (empty($bulkedit)) {
            unset($data['bulkedit']);
        } else {
            $data['bulkedit'] = $bulkedit;
        }
        $event->setParam('data', $data);
    }

    /**
     * Get the processes that should not be run during a web request.
     *
     * They use remote requests, external commands or process all media of the
     * selected items.
     */
    protected function getSlowProcesses(array $processes, string $resourceName): array
    {
        $names = ['explode_item', 'explode_pdf', 'media_remove', 'media_order'];
        if ($resourceName === 'items') {
            $names = array_merge($names, ['media_html', 'media_source', 'media_type', 'media_visibility']);
        }
        $slowProcesses = array_intersect_key($processes, array_flip($names));

        // Only the removal of labels has no remote request.
        if (!empty($processes['fill_values']) && $processes['fill_values']['mode'] !== 'label_remove') {
            $slowProcesses['fill_values'] = $processes['fill_values'];
        }

        // Some conversions use geonames.
        $convert = $processes['convert'] ?? null;
        if ($convert
            && (in_array($convert['to'], ['place', 'geography:coordinates'])
                || $convert['literal_value'] === 'coordinates')
        ) {
            $slowProcesses['convert'] = $convert;
        }

        return $slowProcesses;
    }

    /**
     * Run in background the slow processes of a synchronous batch update.
     */
    protected function dispatchSlowProcesses(Event $event): void
    {
        $request = $event->getParam('request');
        $background = $request->getOption('bulkedit_background');
        if (!$background) {
            return;
        }

        $ids = [];
        foreach ($event->getParam('response')->getContent() ?: [] as $entity) {
            $ids[] = $entity->getId();
        }
        if (!$ids) {
            return;
        }

        $services = $this->getServiceLocator();
        $job = $services->get('Omeka\Job\Dispatcher')->dispatch(\Omeka\Job\BatchUpdate::class, [
            'resource' => $request->getResource(),
            'query' => ['id' => $ids],
            'data' => ['bulkedit' => $background],
            'data_remove' => [],
            'data_append' => [],
        ]);

        $url = $services->get('ViewHelperManager')->get('url');
        $message = new \Common\Stdlib\PsrMessage(
            'The slow processes are run in background (job {link}#{job_id}{link_end}).', // @translate
            [
                'link' => sprintf('<a href="%s">', htmlspecialchars($url('admin/id', ['controller' => 'job', 'id' => $job->getId()]))),
                'job_id' => $job->getId(),
                'link_end' => '</a>',
            ]
        );
        $message->setEscapeHtml(false);
        $services->get('ControllerPluginManager')->get('messenger')->addSuccess($message);
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

        $data = $request->getContent();
        if (empty($data['bulkedit'])) {
            return;
        }

        // Store original request content to preserve fields from other modules.
        $originalContent = $data;

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
        } catch (\Throwable $e) {
            return;
        }

        // Keep data that are currently to be updated, but not yet flushed.
        $representation = $adapter->getRepresentation($resource);
        $data = $this->updateResourcePre($adapter, $representation, $data, $bulkedit);

        // The core removes the remaining values of a partial update only when
        // a value is passed, so the properties emptied by the processes are
        // cleared explicitly, else the last values of a resource are kept.
        $emptiedPropertyIds = [];
        foreach ($representation->values() as $term => $propertyData) {
            if (empty($data[$term])) {
                $emptiedPropertyIds[] = $propertyData['property']->id();
            }
        }
        if ($emptiedPropertyIds) {
            $data['clear_property_values'] = array_values(array_unique(array_merge(
                (array) ($data['clear_property_values'] ?? []),
                $emptiedPropertyIds
            )));
        }

        // Preserve posted fields from other modules (e.g., item-sets-tree-parent-id
        // from ItemSetsTree).
        $data += $originalContent;

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
        // The core detaches the new values after its flush, but they remain in
        // the collection of their resource, so the next flush would insert them
        // again. The direct processes load their own entities.
        $this->getServiceLocator()->get('Omeka\EntityManager')->clear();

        $adapter = $event->getTarget();
        // Process only the updated resources, not the ones that failed (error
        // or not allowed), that are skipped with option "continueOnError".
        $ids = [];
        foreach ($event->getParam('response')->getContent() ?: [] as $entity) {
            $ids[] = $entity->getId();
        }
        if (!$ids) {
            return;
        }
        $this->updateResourcesPost($adapter, $ids, $processes);
    }

    public function handleResourcesBatchUpdatePostFlush(Event $event): void
    {
        $this->dispatchSlowProcesses($event);

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

        $this->reindexResources($event, $processes);
    }

    /**
     * Update title, full text and search indexes after direct updates.
     *
     * The direct processes use sql or entities, so without api events. A partial
     * update without data appends nothing, but runs the hydration and the events.
     */
    protected function reindexResources(Event $event, array $processes): void
    {
        $ids = [];
        foreach ($event->getParam('response')->getContent() ?: [] as $entity) {
            $ids[] = $entity->getId();
        }
        if (!$ids) {
            return;
        }

        $resourceName = $event->getParam('request')->getResource();
        $mediaProcesses = array_intersect_key($processes, array_flip(['media_html', 'media_source', 'media_type', 'media_visibility']));
        $valueProcesses = array_intersect_key($processes, array_flip(['trim_values', 'specify_datatypes', 'clean_empty_values', 'clean_languages', 'clean_language_codes', 'deduplicate_values']));

        $services = $this->getServiceLocator();
        $resources = [];
        if ($valueProcesses || ($mediaProcesses && $resourceName === 'media')) {
            $resources[$resourceName] = $ids;
        }
        if ($mediaProcesses && $resourceName === 'items') {
            $connection = $services->get('Omeka\Connection');
            $resources['media'] = $connection->executeQuery(
                'SELECT `id` FROM `media` WHERE `item_id` IN (:ids)',
                ['ids' => $ids],
                ['ids' => $connection::PARAM_INT_ARRAY]
            )->fetchFirstColumn();
        }

        $api = $services->get('Omeka\ApiManager');
        $logger = $services->get('Omeka\Logger');
        foreach ($resources as $name => $resourceIds) {
            foreach ($resourceIds as $resourceId) {
                try {
                    $api->update($name, $resourceId, [], [], [
                        'isPartial' => true,
                        'collectionAction' => 'append',
                        'responseContent' => 'resource',
                    ]);
                } catch (\Throwable $e) {
                    $logger->err(
                        'Resource #{resource_id} cannot be reindexed after bulk edit: {message}', // @translate
                        ['resource_id' => $resourceId, 'message' => $e->getMessage()]
                    );
                }
            }
        }
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

        $data = $request->getContent();
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

        // The processes are cached with the raw params of the request, so pass
        // them, else the cache is reset and no process is returned.
        $processes = $this->prepareProcesses($data['bulkedit']);
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

        $data = $request->getContent();
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

        $processes = $this->prepareProcesses($data['bulkedit']);
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
        static $bulkeditHash;

        // Reset cache if bulkedit params changed (different batch operation).
        $currentHash = md5(serialize($bulkedit));
        if ($bulkeditHash !== $currentHash) {
            $processes = null;
            $bulkeditHash = $currentHash;
        }

        if ($processes !== null) {
            return $processes;
        }

        if (empty($bulkedit)) {
            return [];
        }

        $bulkedit = $this->expandModify($bulkedit);
        $bulkedit = $this->expandModifyMedia($bulkedit);

        $processes = [
            'create_values' => null,
            // Processes of the action "modify".
            'replace' => null,
            'explode' => null,
            'convert' => null,
            'order_values' => null,
            'properties_visibility' => null,
            'copy' => null,
            'displace' => null,
            'merge' => null,
            'fill_data' => null,
            'fill_values' => null,
            'remove' => null,
            'thumbnails' => null,
            // The media are modified, removed and sorted before the explosion.
            'media_html' => null,
            'media_source' => null,
            'media_type' => null,
            'media_visibility' => null,
            'media_remove' => null,
            'media_order' => null,
            'explode_item' => null,
            'explode_pdf' => null,
            // Cleaning is done separately.
            'trim_values' => null,
            'specify_datatypes' => null,
            'clean_empty_values' => null,
            'clean_languages' => null,
            'clean_language_codes' => null,
            'deduplicate_values' => null,
        ];

        $params = $bulkedit['create_values'] ?? [];
        $values = $params['values'] ?? [];
        if (is_string($values)) {
            $values = json_decode($values, true);
        }
        $values = is_array($values) ? $values : [];
        if ($values || (!empty($params['properties']) && !empty($params['datatype']))) {
            $processes['create_values'] = [
                'values' => $values,
                'properties' => $params['properties'] ?? null,
                'datatype' => $params['datatype'] ?? null,
                'value' => $params['value'] ?? '',
                'uri_label' => $params['uri_label'] ?? '',
                'value_resource_id' => $params['value_resource_id'] ?? null,
                'language' => $params['language'] ?? '',
                'visibility' => isset($params['visibility']) ? (int) (bool) $params['visibility'] : 1,
            ];
        }

        $params = $bulkedit['replace'] ?? [];
        if (!empty($params['mode']) && !empty($params['properties'])) {
            $processes['replace'] = [
                'value_part' => trim($params['value_part'] ?? ''),
                'mode' => $params['mode'],
                'from' => $params['from'] ?? '',
                'to' => $params['to'] ?? '',
                'prepend' => ltrim($params['prepend'] ?? ''),
                'append' => rtrim($params['append'] ?? ''),
                'language' => trim($params['language'] ?? ''),
                'language_clear' => !empty($params['language_clear']),
                'language_all' => !empty($params['language_all']),
                'language_from' => isset($params['language_from']) ? (string) $params['language_from'] : null,
                'properties' => $params['properties'],
                'datatypes' => array_filter($params['datatypes'] ?? []),
                'languages' => $this->arrayTextSeparatorEqualToSpace((array) ($params['languages'] ?? [])),
                'contains' => (string) ($params['contains'] ?? ''),
                'match' => (string) ($params['match'] ?? ''),
                'is_public' => isset($params['is_public']) && $params['is_public'] !== '' ? (int) (bool) $params['is_public'] : '',
                'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                'contains_exact' => !empty($params['contains_exact']),
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
                    'languages' => $this->arrayTextSeparatorEqualToSpace($params['languages'] ?: []),
                    'visibility' => $params['visibility'],
                    'contains' => $params['contains'],
                    'match' => $params['match'] ?? '',
                    'datatype' => $params['datatype'],
                    'max_values' => empty($params['max_values']) ? null : (int) $params['max_values'],
                    'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                    'contains_exact' => !empty($params['contains_exact']),
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
                    'languages' => $this->arrayTextSeparatorEqualToSpace($params['languages'] ?: []),
                    'visibility' => $params['visibility'],
                    'contains' => $params['contains'],
                    'match' => $params['match'] ?? '',
                    'datatype' => $params['datatype'],
                    'max_values' => empty($params['max_values']) ? null : (int) $params['max_values'],
                    'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                    'contains_exact' => !empty($params['contains_exact']),
                ];
            }
        }

        $params = $bulkedit['explode'] ?? [];
        if (!empty($params['properties'])) {
            // Don't use trim for separator, because space can be a separator.
            // The same for contains.
            $separator = (string) ($params['separator'] ?? '');
            if (mb_strlen($separator)) {
                $processes['explode'] = [
                    'properties' => $params['properties'],
                    'separator' => $separator,
                    'contains' => $params['contains'] ?? '',
                    'match' => $params['match'] ?? '',
                    'max_values' => empty($params['max_values']) ? null : (int) $params['max_values'],
                    'datatypes' => array_filter($params['datatypes'] ?? []),
                    'languages' => $this->arrayTextSeparatorEqualToSpace((array) ($params['languages'] ?? [])),
                    'is_public' => isset($params['is_public']) && $params['is_public'] !== '' ? (int) (bool) $params['is_public'] : '',
                    'contains_exact' => !empty($params['contains_exact']),
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
                'datatypes' => array_filter($params['datatypes'] ?? []),
                'languages' => $this->arrayTextSeparatorEqualToSpace((array) ($params['languages'] ?? [])),
                'match' => (string) ($params['match'] ?? ''),
                'is_public' => isset($params['is_public']) && $params['is_public'] !== '' ? (int) (bool) $params['is_public'] : '',
                'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                'contains_exact' => !empty($params['contains_exact']),
            ];
        }

        $params = $bulkedit['order_values'] ?? [];
        if (!empty($params['languages'])) {
            $languages = $this->arrayTextSeparatorEqualToSpace($params['languages'] ?: []);
            $properties = $params['properties'];
            if ($languages && $properties) {
                $filters = $params['filters'] ?? [];
                $processes['order_values'] = [
                    'languages' => $languages,
                    'properties' => $properties,
                    'filters' => [
                        'datatypes' => array_filter((array) ($filters['datatypes'] ?? [])),
                        'languages' => $this->arrayTextSeparatorEqualToSpace((array) ($filters['languages'] ?? [])),
                        'contains' => (string) ($filters['contains'] ?? ''),
                        'match' => (string) ($filters['match'] ?? ''),
                        'is_public' => isset($filters['is_public']) && $filters['is_public'] !== '' ? (int) (bool) $filters['is_public'] : '',
                        'search_in' => array_values(array_filter((array) ($filters['search_in'] ?? []))) ?: null,
                        'contains_exact' => !empty($filters['contains_exact']),
                    ],
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
                'languages' => $this->arrayTextSeparatorEqualToSpace($params['languages'] ?: []),
                'contains' => $params['contains'],
                'match' => (string) ($params['match'] ?? ''),
                'is_public' => isset($params['is_public']) && $params['is_public'] !== '' ? (int) (bool) $params['is_public'] : '',
                'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                'contains_exact' => !empty($params['contains_exact']),
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
                'languages' => $this->arrayTextSeparatorEqualToSpace((array) ($params['languages'] ?? [])),
                'contains' => (string) ($params['contains'] ?? ''),
                'match' => (string) ($params['match'] ?? ''),
                'is_public' => isset($params['is_public']) && $params['is_public'] !== '' ? (int) (bool) $params['is_public'] : '',
                'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                'contains_exact' => !empty($params['contains_exact']),
            ];
            // TODO Use a job only to avoid to fetch the same values multiple times or prefill values.
            // $this->preFillValues($processes['fill_values']);
        }

        $params = $bulkedit['remove'] ?? [];
        if (!empty($params['properties'])) {
            $processes['remove'] = [
                'properties' => $params['properties'],
                'datatypes' => $params['datatypes'] ?? [],
                'languages' => $this->arrayTextSeparatorEqualToSpace($params['languages'] ?: []),
                'visibility' => $params['visibility'],
                'equal' => $params['equal'] ?? '',
                'contains' => $params['contains'],
                'match' => $params['match'],
                'keep_max_values' => empty($params['keep_max_values']) ? null : (int) $params['keep_max_values'],
                'max_values' => empty($params['max_values']) ? null : (int) $params['max_values'],
                'search_in' => array_values(array_filter((array) ($params['search_in'] ?? []))) ?: null,
                'contains_exact' => !empty($params['contains_exact']),
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
                'resolution' => (int) ($params['resolution'] ?? 0),
                'processor' => $params['processor'] ?? 'auto',
                'process_events' => (bool) (int) ($params['process_events'] ?? 0),
            ];
        }

        $params = $bulkedit['media_remove'] ?? [];
        $mode = $params['mode'] ?? '';
        if ($mode) {
            $processes['media_remove'] = [
                'mode' => $mode,
                'mediatypes' => $params['mediatypes'] ?? [],
                'extensions' => $this->arrayTextSeparatorEqualToSpace($params['extensions'] ?: []),
                // 'query' => $params['query'] ?? '',
            ];
        }

        $params = $bulkedit['media_order'] ?? [];
        $order = $params['order'] ?? '';
        if (mb_strlen($order)) {
            $processes['media_order'] = [
                'order' => $order,
                'mediatypes' => $params['mediatypes'] ?? [],
                'extensions' => $this->arrayTextSeparatorEqualToSpace($params['extensions'] ?: []),
            ];
        }

        $params = $bulkedit['media_html'] ?? [];
        if (!empty($params['mode'])) {
            $mode = $params['mode'];
            $from = $params['from'] ?? '';
            $to = $params['to'] ?? '';
            $prepend = ltrim($params['prepend'] ?? '');
            $append = rtrim($params['append'] ?? '');
            // TODO Add the check of the validity of the regex in the form.
            // Early check validity of the regex.
            // The modes "unicode" and "remove" do not require a string.
            if ((($from . $to . $prepend . $append) !== '' || in_array($mode, ['unicode', 'remove']))
                && ($mode !== 'regex' || @preg_match($from, '') !== false)
            ) {
                $processes['media_html'] = [
                    'mode' => $mode,
                    'from' => $from,
                    'to' => $to,
                    'prepend' => $prepend,
                    'append' => $append,
                    'media_types' => array_filter((array) ($params['media_types'] ?? [])),
                    'ingesters' => array_filter((array) ($params['ingesters'] ?? [])),
                    'renderers' => array_filter((array) ($params['renderers'] ?? [])),
                ];
            }
        }

        $params = $bulkedit['media_source'] ?? [];
        if (!empty($params['mode'])) {
            $mode = $params['mode'];
            $from = $params['from'] ?? '';
            $to = $params['to'] ?? '';
            $prepend = ltrim($params['prepend'] ?? '');
            $append = rtrim($params['append'] ?? '');
            // TODO Add the check of the validity of the regex in the form.
            // Early check validity of the regex.
            // Some modes do not require a string.
            if ((($from . $to . $prepend . $append) !== '' || in_array($mode, ['basename', 'filename', 'unicode', 'remove']))
                && ($mode !== 'regex' || @preg_match($from, '') !== false)
            ) {
                $processes['media_source'] = [
                    'from' => $from,
                    'to' => $to,
                    'mode' => $mode,
                    'prepend' => $prepend,
                    'append' => $append,
                    'media_types' => array_filter((array) ($params['media_types'] ?? [])),
                    'ingesters' => array_filter((array) ($params['ingesters'] ?? [])),
                    'renderers' => array_filter((array) ($params['renderers'] ?? [])),
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
                'media_types' => array_filter((array) ($params['media_types'] ?? [])),
                'ingesters' => array_filter((array) ($params['ingesters'] ?? [])),
                'renderers' => array_filter((array) ($params['renderers'] ?? [])),
            ];
        }

        $params = $bulkedit['media_visibility'] ?? [];
        if (isset($params['visibility'])
            && $params['visibility'] !== ''
        ) {
            $visibility = (int) (bool) $params['visibility'];
            $processes['media_visibility'] = [
                'visibility' => $visibility,
                'media_types' => array_filter((array) ($params['media_types'] ?? [])),
                'ingesters' => array_filter((array) ($params['ingesters'] ?? [])),
                'renderers' => array_filter((array) ($params['renderers'] ?? [])),
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
        // TODO Don't use json_decode(json_encode()).
        $data = json_decode(json_encode($resource), true);

        // Keep data that may have been added during batch pre-process.
        $data = array_replace($data, $dataToUpdate);

        // Note: $data is passed by reference to each process.
        foreach ($processes as $process => $params) switch ($process) {
            case 'create_values':
                $bulkEdit->createValuesForResource($resource, $data, $params);
                break;
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
     * Avoid to display the ingester in item edit, because it's an internal one.
     */
    public function handleMediaIngesterRegisteredNames(Event $event): void
    {
        $names = $event->getParam('registered_names');
        $key = array_search('bulkedit_local', $names);
        if ($key !== false) {
            unset($names[$key]);
            $event->setParam('registered_names', $names);
        }
    }

    /**
     * Manage upgrade of option "language" from "=" to " ".
     *
     * @deprecated To be removed in a future version.
     */
    /**
     * Preview the values updated by the batch edit, without saving.
     *
     * The processes on the values of each resource are run in the real order.
     * The slow or remote processes, the cleaning and the processes on media are
     * skipped. Only the resources with updated values are returned, with the
     * values of the updated properties before and after.
     */
    public function previewBulkEdit(
        string $resourceName,
        array $ids,
        array $bulkedit,
        int $limit = 10,
        int $start = 0,
        int $maxChecked = 200
    ): array {
        $processes = array_intersect_key(
            $this->prepareProcesses($bulkedit),
            array_flip([
                'create_values',
                'replace',
                'explode',
                'convert',
                'order_values',
                'properties_visibility',
                'copy',
                'displace',
                'merge',
                'remove',
            ])
        );
        // The next position allows to paginate the updated resources.
        $ids = array_values($ids);
        $result = [
            'total' => count($ids),
            'start' => $start,
            'next' => null,
            'checked' => 0,
            'resources' => [],
        ];
        if (!$processes) {
            return $result;
        }

        $services = $this->getServiceLocator();
        $api = $services->get('Omeka\ApiManager');
        $adapter = $services->get('Omeka\ApiAdapterManager')->get($resourceName);
        $easyMeta = $services->get('Common\EasyMeta');
        $translator = $services->get('MvcTranslator');
        $dataTypeLabels = array_map([$translator, 'translate'], $easyMeta->dataTypeLabels());
        $previewValue = fn (array $value): array => $this->previewValue($value)
            + ['type_label' => $dataTypeLabels[$value['type'] ?? ''] ?? (string) ($value['type'] ?? '')];

        $position = $start;
        $count = count($ids);
        for (; $position < $count && $result['checked'] < $maxChecked; ++$position) {
            $id = $ids[$position];
            try {
                /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
                $resource = $api->read($resourceName, $id)->getContent();
            } catch (\Throwable $e) {
                continue;
            }
            ++$result['checked'];
            $before = json_decode(json_encode($resource), true);
            $after = $this->updateResourcePre($adapter, $resource, [], $processes);
            $properties = [];
            foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $term) {
                if (!$easyMeta->propertyId($term)) {
                    continue;
                }
                $valuesBefore = array_map($previewValue, array_values($before[$term] ?? []));
                $valuesAfter = array_map($previewValue, array_values($after[$term] ?? []));
                if ($valuesBefore !== $valuesAfter) {
                    $properties[] = [
                        'term' => $term,
                        'label' => $translator->translate((string) $easyMeta->propertyLabel($term)),
                        'before' => $valuesBefore,
                        'after' => $valuesAfter,
                    ];
                }
            }
            if ($properties) {
                $result['resources'][] = [
                    'id' => $resource->id(),
                    'title' => $resource->displayTitle(),
                    'url' => $resource->adminUrl(),
                    'properties' => $properties,
                ];
                if (count($result['resources']) >= $limit) {
                    ++$position;
                    break;
                }
            }
        }
        $result['next'] = $position < $count ? $position : null;

        return $result;
    }

    /**
     * Get the main data of a value to display it in the preview.
     */
    protected function previewValue(array $value): array
    {
        $text = $value['@value'] ?? $value['@id'] ?? null;
        if (!empty($value['value_resource_id'])) {
            $text = '#' . $value['value_resource_id'] . (isset($value['display_title']) ? ' ' . $value['display_title'] : '');
        }
        return [
            'type' => (string) ($value['type'] ?? ''),
            'value' => (string) $text,
            'label' => (string) ($value['o:label'] ?? ''),
            'language' => (string) ($value['@language'] ?? $value['o:lang'] ?? ''),
            'is_public' => !isset($value['is_public']) || (bool) $value['is_public'],
        ];
    }

    /**
     * Dispatch the action "modify" to the processes it groups.
     *
     * Only the enabled operations are processed. The properties and the filters
     * are common to all the operations. The processes set directly are kept,
     * for example from the api.
     */
    protected function expandModify(array $bulkedit): array
    {
        $modify = $bulkedit['modify'] ?? null;
        unset($bulkedit['modify']);
        if (!is_array($modify) || empty($modify['properties'])) {
            return $bulkedit;
        }

        $operations = [];
        foreach (['replace', 'language', 'explode', 'convert', 'order_values', 'properties_visibility'] as $name) {
            if (!empty($modify[$name]['enabled']) && is_array($modify[$name])) {
                $operations[$name] = $modify[$name];
                unset($operations[$name]['enabled']);
            }
        }
        if (!$operations) {
            return $bulkedit;
        }

        $properties = (array) $modify['properties'];
        $filters = [
            'properties' => $properties,
            'datatypes' => array_filter((array) ($modify['datatypes'] ?? [])),
            'languages' => $modify['languages'] ?? [],
            'contains' => (string) ($modify['contains'] ?? ''),
            'match' => (string) ($modify['match'] ?? ''),
            'is_public' => $modify['is_public'] ?? '',
            'search_in' => $modify['search_in'] ?? null,
            'contains_exact' => $modify['contains_exact'] ?? '',
        ];
        if ($filters['match'] !== '' && @preg_match($filters['match'], '') === false) {
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                'The regex "{regex}" is invalid: the values are not modified.', // @translate
                ['regex' => $filters['match']]
            );
            return $bulkedit;
        }

        // The text and the language are managed by the process "replace".
        $replace = $this->prepareModifyReplace($operations['replace'] ?? [], $operations['language'] ?? []);
        unset($operations['replace'], $operations['language']);
        if ($replace) {
            $operations = ['replace' => $replace] + $operations;
        }

        foreach ($operations as $name => $params) {
            // The languages of this process are the ones to order first, so
            // the filters are separated.
            $bulkedit[$name] ??= $name === 'order_values'
                ? array_replace($params, ['properties' => $properties, 'filters' => $filters])
                : array_replace($params, $filters);
        }

        return $bulkedit;
    }

    /**
     * Dispatch the actions "modify media" and "explode items" to the processes.
     *
     * Only the enabled operations are processed, with the common filters. The
     * processes set directly are kept, for example from the api.
     */
    protected function expandModifyMedia(array $bulkedit): array
    {
        $modify = (array) ($bulkedit['media_modify'] ?? []);
        unset($bulkedit['media_modify']);

        $filters = [
            'media_types' => array_filter((array) ($modify['media_types'] ?? [])),
            'ingesters' => array_filter((array) ($modify['ingesters'] ?? [])),
            'renderers' => array_filter((array) ($modify['renderers'] ?? [])),
        ];
        foreach (['media_html', 'media_source', 'media_type', 'media_visibility'] as $name) {
            if (!empty($modify[$name]['enabled']) && is_array($modify[$name])) {
                $params = $modify[$name];
                unset($params['enabled']);
                $bulkedit[$name] ??= array_replace($params, $filters);
            }
        }

        // The explosions of items have no filter.
        $explode = (array) ($bulkedit['media_explode'] ?? []);
        unset($bulkedit['media_explode']);
        foreach (['explode_item', 'explode_pdf'] as $name) {
            if (!empty($explode[$name]['enabled']) && is_array($explode[$name])) {
                $params = $explode[$name];
                unset($params['enabled']);
                $bulkedit[$name] ??= $params;
            }
        }

        return $bulkedit;
    }

    /**
     * Convert the operations on text and language into a replacement.
     */
    protected function prepareModifyReplace(array $text, array $language): array
    {
        $action = $text['action'] ?? '';
        $match = $text['match'] ?? 'raw';
        $modes = [
            'search' => in_array($match, ['raw', 'raw_i', 'regex', 'html']) ? $match : 'raw',
            'whole' => 'whole',
            'filename' => ($text['extension'] ?? 'keep') === 'remove' ? 'filename' : 'basename',
            'unicode' => 'unicode',
            'remove' => 'remove',
        ];

        $replace = [
            'value_part' => in_array($text['value_part'] ?? '', ['value', 'uri', 'uri_label']) ? $text['value_part'] : 'value',
            'mode' => $modes[$action] ?? '',
            'from' => $action === 'search' ? (string) ($text['from'] ?? '') : '',
            'to' => in_array($action, ['search', 'whole']) ? (string) ($text['to'] ?? '') : '',
            'prepend' => (string) ($text['prepend'] ?? ''),
            'append' => (string) ($text['append'] ?? ''),
            'language' => '',
            'language_clear' => '',
            // Unlike the replacement, the language is set whatever the part.
            'language_all' => true,
        ];

        switch ($language['action'] ?? '') {
            case 'set':
                $replace['language'] = trim((string) ($language['language'] ?? ''));
                break;
            case 'remove':
                $replace['language_clear'] = '1';
                break;
            case 'change':
                $replace['language_from'] = trim((string) ($language['code_from'] ?? ''));
                $codeTo = trim((string) ($language['code_to'] ?? ''));
                if ($codeTo === '') {
                    $replace['language_clear'] = '1';
                } else {
                    $replace['language'] = $codeTo;
                }
                break;
        }

        // The language or the added text may be set without replacement.
        if ($replace['mode'] === '') {
            if ($replace['language'] === ''
                && $replace['language_clear'] === ''
                && trim($replace['prepend']) === ''
                && trim($replace['append']) === ''
            ) {
                return [];
            }
            $replace['mode'] = 'raw';
        }

        return $replace;
    }

    protected function arrayTextSeparatorEqualToSpace(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode('=', implode('=', $values))))));
    }
}
