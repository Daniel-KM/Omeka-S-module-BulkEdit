<?php
namespace BulkEdit;

require_once file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
    ? dirname(__DIR__) . '/Generic/AbstractModule.php'
    : __DIR__ . '/Generic/AbstractModule.php';

use Generic\AbstractModule;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Adapter\ItemAdapter;
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
            if (mb_strpos($term, ':') === false || !is_array($values) || empty($values)) {
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

        // Deduplicating.
        foreach ($data as $term => &$values) {
            // Process properties only.
            if (mb_strpos($term, ':') === false || !is_array($values) || empty($values)) {
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

        //  TODO Factorize to avoid multiple update of resources.

        $propertiesValues = $request->getValue('properties_values', []);
        if (!empty($propertiesValues['properties'])) {
            $from = $propertiesValues['from'];
            $to = $propertiesValues['to'];
            $remove = (bool) $propertiesValues['remove'];
            $prepend = ltrim($propertiesValues['prepend']);
            $append = rtrim($propertiesValues['append']);
            $language = trim($propertiesValues['language']);
            $languageClear = (bool) ($propertiesValues['language_clear']);
            if (mb_strlen($from)
                || mb_strlen($to)
                || $remove
                || mb_strlen($prepend)
                || mb_strlen($append)
                || mb_strlen($language)
                || $languageClear
            ) {
                $adapter = $event->getTarget();
                $ids = (array) $request->getIds();
                $this->updateValuesForResources($adapter, $ids, $propertiesValues['properties'], [
                    'from' => $from,
                    'to' => $to,
                    'replace_mode' => $propertiesValues['replace_mode'],
                    'remove' => $remove,
                    'prepend' => $prepend,
                    'append' => $append,
                    'language' => $language,
                    'language_clear' => $languageClear,
                ]);
            }
        }

        $propertiesVisibility = $request->getValue('properties_visibility', []);
        if (isset($propertiesVisibility['visibility'])
            && $propertiesVisibility['visibility'] !== ''
            && !empty($propertiesVisibility['properties'])
        ) {
            $visibility = (int) (bool) $propertiesVisibility['visibility'];
            $adapter = $event->getTarget();
            $ids = (array) $request->getIds();
            $this->applyVisibilityForResourcesValues($adapter, $ids, $propertiesVisibility['properties'], $visibility);
        }

        $mediaHtml = $request->getValue('media_html', []);
        $from = $mediaHtml['from'];
        $to = $mediaHtml['to'];
        $remove = (bool) $mediaHtml['remove'];
        $prepend = ltrim($mediaHtml['prepend']);
        $append = rtrim($mediaHtml['append']);
        if (mb_strlen($from)
            || mb_strlen($to)
            || $remove
            || mb_strlen($prepend)
            || mb_strlen($append)
        ) {
            $adapter = $event->getTarget();
            $ids = (array) $request->getIds();
            $this->updateMediaHtmlForResources($adapter, $ids, [
                'from' => $from,
                'to' => $to,
                'replace_mode' => $propertiesValues['replace_mode'],
                'remove' => $remove,
                'prepend' => $prepend,
                'append' => $append,
            ]);
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
            'name' => 'properties_values',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Values', // @translate
            ],
            'attributes' => [
                'id' => 'properties_values',
                'class' => 'field-container',
            ],
        ]);
        $fieldset = $form->get('properties_values');
        $fieldset->add([
            'name' => 'from',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'String to replace…', // @translate
            ],
            'attributes' => [
                'id' => 'propval_from',
            ],
        ]);
        $fieldset->add([
            'name' => 'to',
            'type' => Element\Text::class,
            'options' => [
                'label' => '… by…', // @translate
            ],
            'attributes' => [
                'id' => 'propval_to',
            ],
        ]);
        $fieldset->add([
            'name' => 'replace_mode',
            'type' => Element\Radio::class,
            'options' => [
                'label' => '… using replacement mode', // @translate
                'value_options' => [
                    'raw' => 'Simple', // @translate
                    'regex' => 'Regex (with delimiters)', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'propval_replace_mode',
                'value' => 'raw',
            ],
        ]);
        $fieldset->add([
            'name' => 'remove',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Remove string', // @translate
            ],
            'attributes' => [
                'id' => 'propval_remove',
            ],
        ]);
        $fieldset->add([
            'name' => 'prepend',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'String to prepend', // @translate
            ],
            'attributes' => [
                'id' => 'propval_prepend',
            ],
        ]);
        $fieldset->add([
            'name' => 'append',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'String to append', // @translate
            ],
            'attributes' => [
                'id' => 'propval_append',
            ],
        ]);
        $fieldset->add([
            'name' => 'language',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Set a language…', // @translate
            ],
            'attributes' => [
                'id' => 'propval_language',
                'class' => 'value-language active',
            ],
        ]);
        $fieldset->add([
            'name' => 'language_clear',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => '… or remove it…', // @translate
            ],
            'attributes' => [
                'id' => 'propval_language_clear',
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
                'id' => 'propval_properties',
                'class' => 'chosen-select',
                'multiple' => true,
                'data-placeholder' => 'Select properties', // @translate
            ],
        ]);

        $form->add([
            'name' => 'properties_visibility',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Visibility', // @translate
            ],
            'attributes' => [
                'id' => 'properties_visibility',
                'class' => 'field-container',
            ],
        ]);
        $fieldset = $form->get('properties_visibility');
        $fieldset->add([
            'name' => 'visibility',
            'type' => Element\Radio::class,
            'options' => [
                'label' => 'Set visibility…', // @translate
                'value_options' => [
                    '1' => 'Public', // @translate
                    '0' => 'Not public', // @translate
                    '' => '[No change]', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'propvis_visibility',
                'value' => '',
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
                'id' => 'propvis_properties',
                'class' => 'chosen-select',
                'multiple' => true,
                'data-placeholder' => 'Select properties', // @translate
            ],
        ]);

        $form->add([
            'name' => 'media_html',
            'type' => Fieldset::class,
            'options' => [
                'label' => 'Media HTML', // @translate
            ],
            'attributes' => [
                'id' => 'media_html',
                'class' => 'field-container',
            ],
        ]);
        $fieldset = $form->get('media_html');
        $fieldset->add([
            'name' => 'from',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'String to replace…', // @translate
            ],
            'attributes' => [
                'id' => 'mediahtml_from',
            ],
        ]);
        $fieldset->add([
            'name' => 'to',
            'type' => Element\Text::class,
            'options' => [
                'label' => '… by…', // @translate
            ],
            'attributes' => [
                'id' => 'mediahtml_to',
            ],
        ]);
        $fieldset->add([
            'name' => 'replace_mode',
            'type' => Element\Radio::class,
            'options' => [
                'label' => '… using replacement mode', // @translate
                'value_options' => [
                    'raw' => 'Simple', // @translate
                    'regex' => 'Regex (with delimiters)', // @translate
                ],
            ],
            'attributes' => [
                'id' => 'mediahtml_replace_mode',
                'value' => 'raw',
            ],
        ]);
        $fieldset->add([
            'name' => 'remove',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Remove string', // @translate
            ],
            'attributes' => [
                'id' => 'mediahtml_remove',
            ],
        ]);
        $fieldset->add([
            'name' => 'prepend',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'String to prepend', // @translate
            ],
            'attributes' => [
                'id' => 'mediahtml_prepend',
            ],
        ]);
        $fieldset->add([
            'name' => 'append',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'String to append', // @translate
            ],
            'attributes' => [
                'id' => 'mediahtml_append',
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
        $inputFilter->get('properties_values')->add([
            'name' => 'replace_mode',
            'required' => false,
        ]);
        $inputFilter->get('properties_values')->add([
            'name' => 'properties',
            'required' => false,
        ]);
        $inputFilter->get('properties_visibility')->add([
            'name' => 'visibility',
            'required' => false,
        ]);
        $inputFilter->get('properties_visibility')->add([
            'name' => 'properties',
            'required' => false,
        ]);
    }

    /**
     * Update values for resources.
     *
     * @param AbstractResourceEntityAdapter $adapter
     * @param array $resourceIds
     * @param array $properties
     * @param array $params
     */
    protected function updateValuesForResources(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $properties,
        array $params
    ) {
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');
        $resourceType = $adapter->getResourceName();

        $from = $params['from'];
        $to = $params['to'];
        $replaceMode = $params['replace_mode'] === 'regex' ? 'regex' : 'raw';
        $remove = $params['remove'];
        $prepend = $params['prepend'];
        $append = $params['append'];
        $languageClear = $params['language_clear'];
        $language = $languageClear ? '' : $params['language'];

        // Check the validity of the regex.
        // TODO Add the check of the validity of the regex in the form.
        if ($replaceMode === 'regex' && mb_strlen($from)) {
            $isValidRegex = @preg_match($from, null) !== false;
            if (!$isValidRegex) {
                $from = '';
            }
        }

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

            if ($remove) {
                foreach ($properties as $property => $propertyValues) {
                    foreach ($propertyValues as $key => $value) {
                        if ($value['type'] !== 'literal') {
                            continue;
                        }
                        $toUpdate = true;
                        $data[$property][$key]['@value'] = '';
                    }
                }
            } elseif (mb_strlen($from)) {
                foreach ($properties as $property => $propertyValues) {
                    foreach ($propertyValues as $key => $value) {
                        if ($value['type'] !== 'literal') {
                            continue;
                        }
                        $newValue = $replaceMode === 'regex'
                            ? preg_replace($from, $to, $data[$property][$key]['@value'])
                            : str_replace($from, $to, $data[$property][$key]['@value']);
                        if ($value['@value'] === $newValue) {
                            continue;
                        }
                        $toUpdate = true;
                        $data[$property][$key]['@value'] = $newValue;
                    }
                }
            }

            if (mb_strlen($prepend) || mb_strlen($append)) {
                foreach ($properties as $property => $propertyValues) {
                    foreach ($propertyValues as $key => $value) {
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
            }

            if (!$toUpdate) {
                continue;
            }

            // Force trimming values and check if a value is empty to remove it.
            foreach ($properties as $property => $propertyValues) {
                foreach ($propertyValues as $key => $value) {
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

            $api->update($resourceType, $resourceId, $data);
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
    ) {
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');

        $from = $params['from'];
        $to = $params['to'];
        $replaceMode = $params['replace_mode'] === 'regex' ? 'regex' : 'raw';
        $remove = $params['remove'];
        $prepend = $params['prepend'];
        $append = $params['append'];

        // Check the validity of the regex.
        // TODO Add the check of the validity of the regex in the form.
        if ($replaceMode === 'regex' && mb_strlen($from)) {
            $isValidRegex = @preg_match($from, null) !== false;
            if (!$isValidRegex) {
                $from = '';
            }
        }

        foreach ($resourceIds as $resourceId) {
            $resource = $adapter->findEntity(['id' => $resourceId]);
            if (!$resource) {
                continue;
            }

            /** @var \Omeka\Api\Representation\ItemRepresentation $resource */
            $resource = $adapter->getRepresentation($resource);

            /** @var \Omeka\Api\Representation\MediaRepresentation[] $medias */
            $medias = $resource->media();
            foreach ($medias as $media) {
                if ($media->renderer() !== 'html') {
                    continue;
                }

                $html = $media->mediaData()['html'];
                $currentHtml = $html;

                if ($remove) {
                    $html = '';
                } elseif (mb_strlen($from)) {
                    $html = $replaceMode === 'regex'
                        ? preg_replace($from, $to, $html)
                        : str_replace($from, $to, $html);
                }

                $html = $prepend . $html . $append;

                // Force trimming values and check if a value is empty to remove it.
                // Html is automatically purified.
                $html = trim($html);

                if ($currentHtml === $html) {
                    continue;
                }

                // TODO Clean the update of the html value of the media.
                $data = json_decode(json_encode($media), true);
                // $data['data']['html'] = $html;
                // $data['o-cnt:chars'] = $html;
                $data['o:media']['__index__']['html'] = $html;
                $api->update('media', $media->id(), $data);
            }
        }
    }

    /**
     * Set visibility to the specified properties of the specified resources.
     *
     * @param AbstractResourceEntityAdapter $adapter
     * @param array $resourceIds
     * @param array $properties
     * @param int $visibility
     */
    protected function applyVisibilityForResourcesValues(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $properties,
        $visibility
    ) {
        $visibility = (int) (bool) $visibility;
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
                    $currentVisibility = isset($value['is_public']) ? (int) $value['is_public'] : 1;
                    if ($currentVisibility === $visibility) {
                        continue;
                    }
                    $toUpdate = true;
                    $data[$property][$key]['is_public'] = $visibility;
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
