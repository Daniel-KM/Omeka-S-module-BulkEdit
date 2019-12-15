<?php
namespace BulkEdit;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use BulkEdit\Form\BulkEditFieldset;
use Generic\AbstractModule;
use Omeka\Api\Adapter\AbstractResourceEntityAdapter;
use Omeka\Api\Adapter\ItemAdapter;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;

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
        if ($this->checkOldModuleNext()) {
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
        $assetUrl = $view->plugin('assetUrl');
        $view->headLink()
            ->appendStylesheet($assetUrl('css/bulk-edit.css', 'BulkEdit'));
        $view->headScript()
            ->appendFile($assetUrl('js/bulk-edit.js', 'BulkEdit'));
    }

    /**
     * Process action on batch update (all or partial).
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
        $ids = (array) $request->getIds();
        if (empty($ids)) {
            return;
        }

        $services = $this->getServiceLocator();
        $plugins = $services->get('ControllerPluginManager');
        $adapter = $event->getTarget();

        // Other process (media html, cleaning) are managed differently.
        $processes = [
            'replace' => false,
            'order_values' => false,
            'properties_visibility' => false,
            'displace' => false,
            'explode' => false,
            'merge' => false,
            'convert' => false,
        ];

        $bulkedit = $request->getValue('bulkedit');

        $params = isset($bulkedit['replace']) ? $bulkedit['replace'] : [];
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

        $params = isset($bulkedit['order_values']) ? $bulkedit['order_values'] : [];
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

        $params = isset($bulkedit['properties_visibility']) ? $bulkedit['properties_visibility'] : [];
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

        $params = isset($bulkedit['displace']) ? $bulkedit['displace'] : [];
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

        $params = isset($bulkedit['explode']) ? $bulkedit['explode'] : [];
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

        $params = isset($bulkedit['merge']) ? $bulkedit['merge'] : [];
        if (!empty($params['properties'])) {
            $processes['merge'] = [
                'properties' => $params['properties'],
            ];
        }

        $params = isset($bulkedit['convert']) ? $bulkedit['convert'] : [];
        if (!empty($params['from']) && !empty($params['to']) && !empty($params['properties'])) {
            $from = $params['from'];
            $to = $params['to'];
            if ($from !== $to) {
                $processes['convert'] = [
                    'from' => $from,
                    'to' => $to,
                    'properties' => $params['properties'],
                    'uri_label' => $params['uri_label'],
                ];
            }
        }

        $this->updateValues($adapter, $ids, $processes);

        $params = isset($bulkedit['media_html']) ? $bulkedit['media_html'] : [];
        $from = isset($params['from']) ? $params['from'] : null;
        $to = isset($params['to']) ? $params['to'] : null;
        $remove = isset($params['remove']) && (bool) $params['remove'];
        $prepend = isset($params['prepend']) ? ltrim($params['prepend']) : '';
        $append = isset($params['prepend']) ? rtrim($params['append']) : '';
        if (mb_strlen($from)
            || mb_strlen($to)
            || $remove
            || mb_strlen($prepend)
            || mb_strlen($append)
        ) {
            // This process is specific, because not for current resources.
            // $hasProcess = true;
            $this->updateMediaHtmlForResources($adapter, $ids, [
                'from' => $from,
                'to' => $to,
                'mode' => $params['mode'],
                'remove' => $remove,
                'prepend' => $prepend,
                'append' => $append,
            ]);
        }

        if ($this->checkOldModuleNext()) {
            return;
        }

        $params = isset($bulkedit['cleaning']) ? $bulkedit['cleaning'] : [];
        if (!empty($params['trim_values'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\TrimValues $trimValues */
            $trimValues = $plugins->get('trimValues');
            $ids = (array) $request->getIds();
            $trimValues($ids);
        }
        if (!empty($params['deduplicate_values'])) {
            /** @var \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues $deduplicateValues */
            $deduplicateValues = $plugins->get('deduplicateValues');
            $ids = (array) $request->getIds();
            $deduplicateValues($ids);
        }
    }

    public function formAddElementsResourceBatchUpdateForm(Event $event)
    {
        /** @var \Omeka\Form\ResourceBatchUpdateForm $form */
        $form = $event->getTarget();
        $options = [
            'listDataTypesForSelect' => $this->listDataTypesForSelect(),
            'hasOldModuleNext' => $this->checkOldModuleNext(),
        ];
        $fieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(BulkEditFieldset::class, $options);
        $form->add($fieldset);
    }

    public function formAddInputFiltersResourceBatchUpdateForm(Event $event)
    {
        /** @var \Zend\InputFilter\InputFilterInterface $inputFilter */
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter = $inputFilter->get('bulkedit');
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

    protected function updateValues(
        AbstractResourceEntityAdapter$adapter,
        array $resourceIds,
        array $processes
    ) {
        $processes = array_filter($processes);
        if (!$processes) {
            return;
        }

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
            $toUpdate = false;

            foreach ($processes as $process => $params) {
                switch ($process) {
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
                }
            }

            if ($toUpdate) {
                $api->update($resourceType, $resourceId, $data, [], ['responseContent' => 'resource']);
            }
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
    ) {
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
                    $currentLanguage = isset($value['@language']) ? $value['@language'] : '';
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
    ) {
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
    ) {
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
    ) {
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
    ) {
        static $settings;
        if (is_null($settings)) {
            $properties = $params['properties'];
            $separator = $params['separator'];
            $contains = $params['contains'];

            if (empty($properties) || !strlen($separator)) {
                return;
            }

            $checkContains = (bool) mb_strlen($contains);

            $settings = $params;
            $settings['checkContains'] = $checkContains;
        } else {
            extract($settings);
        }

        if (empty($properties) || !strlen($separator)) {
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
                $vs = array_filter(array_map('trim', explode($separator, $value['@value'])), function ($v) {
                    return (bool) strlen($v);
                });
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
    ) {
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
                if ($key %2 === 1) {
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
                if (($pair[0]['type'] === 'uri' && strlen($pair[0]['@id']) && isset($pair[0]['o:label']) && strlen($pair[0]['o:label']))
                    || ($pair[1]['type'] === 'uri' && strlen($pair[1]['@id']) && isset($pair[1]['o:label']) && strlen($pair[1]['o:label']))
                ) {
                    continue 2;
                }

                $mainValueA = $pair[0]['type'] === 'uri' ? $pair[0]['@id'] : $pair[0]['@value'];
                $mainValueB = $pair[1]['type'] === 'uri' ? $pair[1]['@id'] : $pair[1]['@value'];

                // There should be one and only one url unless they are the same.
                $isUrlA = strpos($mainValueA, 'http://') === 0 || strpos($mainValueA, 'https://') === 0;
                $isUrlB = strpos($mainValueB, 'http://') === 0 || strpos($mainValueB, 'https://') === 0;
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
                $isUrlA = strpos($mainValueA, 'http://') === 0 || strpos($mainValueA, 'https://') === 0;
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
    ) {
        static $settings;
        if (is_null($settings)) {
            $fromDatatype = $params['from'];
            $toDatatype = $params['to'];
            $properties = $params['properties'];
            $uriLabel = strlen($params['uri_label']) ? $params['uri_label'] : null;

            $settings = $params;
            $settings['fromDatatype'] = $fromDatatype;
            $settings['toDatatype'] = $toDatatype;
            $settings['properties'] = $properties;
            $settings['uriLabel'] = $uriLabel;
        } else {
            extract($settings);
        }

        if (($fromDatatype === $toDatatype)
            || ($fromDatatype && !$toDatatype)
            || (!$fromDatatype && $toDatatype)
        ) {
            return;
        }

        // Note: this is the original values.
        $properties = array_intersect($properties, array_keys($resource->values()));
        if (empty($properties)) {
            return;
        }

        $toUpdate = true;

        foreach ($properties as $property) {
            foreach ($data[$property] as $key => $value) {
                if ($value['type'] !== $fromDatatype) {
                    continue;
                }
                switch ($toDatatype) {
                    case 'literal':
                        // From uri.
                        $value = ['type' => 'literal', '@language' => null, '@value' => $value['@id'], '@id' => null, 'o:label' => null] + $value;
                        break;
                    case 'uri':
                        // From text.
                        $value = ['type' => 'uri', '@language' => null, '@value' => null, '@id' => $value['@value'], 'o:label' => $uriLabel] + $value;
                        break;
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
    ) {
        $api = $this->getServiceLocator()->get('ControllerPluginManager')->get('api');
        $apiOptions = ['initialize' => true, 'finalize' => true, 'responseContent' => 'resource'];

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
                $api->update('media', $media->id(), $data, [], $apiOptions);
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
        $options = array_merge($options, $optgroupOptions);

        return $options;
    }

    /**
     * Check if the module Next is enabled and smaller than 3.1.2.9.
     *
     * @return bool
     */
    protected function checkOldModuleNext()
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
