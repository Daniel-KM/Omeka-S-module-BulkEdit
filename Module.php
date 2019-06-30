<?php
namespace BulkEdit;

require_once file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
    ? dirname(__DIR__) . '/Generic/AbstractModule.php'
    : __DIR__ . '/Generic/AbstractModule.php';

use Generic\AbstractModule;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Form\Element\PropertySelect;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Form\Element;
use Zend\Form\Fieldset;

/**
 * BulkEdit
 *
 * Improve the bulk edit process with new features.
 *
 * @copyright Daniel Berthereau, 2018-2019
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
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
                'api.batch_update.post',
                [$this, 'handleResourceBatchUpdatePost']
            );
        }

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

    /**
     * Process action on create/update.
     *
     * - preventive trim on property values.
     * - preventive deduplication on property values
     *
     * @param Event $event
     */
    public function handleResourceProcessPre(Event $event)
    {
        if ($this->checkModuleNext()) {
            return;
        }

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();

        $trimUnicode = function ($v) {
            return preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', $v);
        };

        // Trimming.
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (strpos($term, ':') === false || !is_array($values) || empty($values)) {
                continue;
            }
            $first = reset($values);
            if (empty($first['property_id'])) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $v = $trimUnicode($value['@value']);
                    $value['@value'] = strlen($v) ? $v : null;
                }
                if (isset($value['@id'])) {
                    $v = $trimUnicode($value['@id']);
                    $value['@id'] = strlen($v) ? $v : null;
                }
                if (isset($value['@language'])) {
                    $v = $trimUnicode($value['@language']);
                    $value['@language'] = strlen($v) ? $v : null;
                }
                if (isset($value['o:label'])) {
                    $v = $trimUnicode($value['o:label']);
                    $value['o:label'] = strlen($v) ? $v : null;
                }
            }
            unset($value);
        }
        unset($values);

        // Deduplicating.
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (strpos($term, ':') === false || !is_array($values) || empty($values)) {
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

    public function viewBatchEditBefore(Event $event)
    {
        $view = $event->getTarget();
        $view->headLink()
            ->appendStylesheet($view->assetUrl('css/bulk-edit.css', __NAMESPACE__));
        $view->headScript()
            ->appendFile($view->assetUrl('js/bulk-edit.js', __NAMESPACE__));
    }

    /**
     * Process action on batch update (all or partial).
     *
     * - curative trim on property values.
     *
     * Data may need to be reindexed if a module like Search is used, even if
     * the results are probably the same with a simple trimming.
     *
     * @param Event $event
     */
    public function handleResourceBatchUpdatePost(Event $event)
    {
        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');

        $languageProperties = $request->getValue('language_properties', []);
        if (!empty($languageProperties['properties'])) {
            if (!empty($languageProperties['clear'])) {
                $language = '';
            } elseif (!empty($languageProperties['language'])) {
                $language = $languageProperties['language'];
            } else {
                $language = null;
            }
            if (!is_null($language)) {
                $adapter = $event->getTarget();
                $ids = (array) $request->getIds();
                $this->applyLanguageForResourcesValues($adapter, $ids, $languageProperties['properties'], $language);
            }
        }

        if ($this->checkModuleNext()) {
            return;
        }

        $cleaning = $request->getValue('cleaning', []);
        if (!empty($cleaning['trim_values'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
            $trimValues = $plugins->get('trimValues');
            $ids = (array) $request->getIds();
            $trimValues($ids);
        }

        if (!empty($cleaning['deduplicate_values'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues $deduplicateValues */
            $deduplicateValues = $plugins->get('deduplicateValues');
            $ids = (array) $request->getIds();
            $deduplicateValues($ids);
        }
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event)
    {
        $form = $event->getTarget();

        $form->add([
            'name' => 'language_properties',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Language', // @translate
            ],
            'attributes' => [
                'id' => 'properties_language',
                'class' => 'field-container',
            ],
        ]);
        $fieldset = $form->get('language_properties');
        $fieldset->add([
            'name' => 'language',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Set a language…', // @translate
            ],
            'attributes' => [
                'id' => 'langprop_language',
                'class' => 'value-language active',
            ],
        ]);
        $fieldset->add([
            'name' => 'clear',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => '… or remove it…', // @translate
            ],
            'attributes' => [
                'id' => 'langprop_clear',
            ],
        ]);
        $fieldset->add([
            'name' => 'properties',
            'type' => PropertySelect::class,
            'options' => [
                'label' => '… for properties', // @translate
                'term_as_value' => true,
                'prepend_value_options' => [
                    'all' => '[All properties]', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'langprop_properties',
                'class' => 'chosen-select',
                'multiple' => true,
                'data-placeholder' => 'Select properties', // @translate
            ],
        ]);

        if ($this->checkModuleNext()) {
            return;
        }

        $form->add([
            'name' => 'cleaning',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Cleaning', // @translate
            ],
            'attributes' => [
                'class' => 'field-container',
            ],
        ]);
        $fieldset = $form->get('cleaning');
        $fieldset->add([
            'name' => 'trim_values',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Trim property values', // @translate
                'info' => 'Remove initial and trailing whitespace of all values of all properties', // @translate
            ],
            'attributes' => [
                'id' => 'cleaning_trim',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ],
        ]);

        $fieldset->add([
            'name' => 'deduplicate_values',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Deduplicate property values case insensitively', // @translate
                'info' => 'Deduplicate values of all properties, case INsensitively. Trimming values before is recommended, because values are checked strictly.', // @translate
            ],
            'attributes' => [
                'id' => 'cleaning_deduplicate',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ],
        ]);
    }

    public function formAddInputFiltersResourceBatchUpdateForm(Event $event)
    {
        $inputFilter = $event->getParam('inputFilter');

        $inputFilter->get('language_properties')->add([
            'name' => 'properties',
            'required' => false,
        ]);
    }

    /**
     * Set a language to the specified properties of the specified resources.
     *
     * @param AbstractResourceEntityAdapter $adapter
     * @param array $resourceIds
     * @param array $properties
     * @param string $language
     */
    protected function applyLanguageForResourcesValues(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $properties,
        $language
    ) {
        $language = trim($language);
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');
        $resourceType = $adapter->getResourceName();

        foreach ($resourceIds as $resourceId) {
            $resource = $adapter->findEntity(['id' => $resourceId]);
            if (!$resource) {
                continue;
            }

            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
            $resource = $adapter->getRepresentation($resource);

            $data = json_decode(json_encode($resource), true);
            if (in_array('all', $properties)) {
                $properties = array_keys($resource->values());
            }
            $properties = array_intersect_key($data, array_flip($properties));
            if (empty($properties)) {
                continue;
            }

            $toUpdate = false;
            foreach ($properties as $property => $propertyValues) {
                foreach ($propertyValues as $key => $value) {
                    if ($value['type'] !== 'literal') {
                        continue;
                    }
                    $currentLanguage = isset($value['@language']) ? $value['@language'] : '';
                    if ($currentLanguage === $language) {
                        continue;
                    }
                    $toUpdate = true;
                    $data[$property][$key]['@language'] = $language;
                }
            }
            if (!$toUpdate) {
                continue;
            }

            $api->update($resourceType, $resourceId, $data);
        }
    }

    protected function checkModuleNext()
    {
        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Next');
        if (!$module || $module->getState() !== \Omeka\Module\Manager::STATE_ACTIVE) {
            return false;
        }
        $version = $module->getIni('version');
        return version_compare($version, '3.1.2.9', '<');
    }
}
