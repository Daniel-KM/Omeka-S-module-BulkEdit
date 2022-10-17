<?php declare(strict_types=1);

namespace BulkEdit\Service\Form;

use BulkEdit\Form\BulkEditFieldset;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory to get the BulkEditFieldset.
 */
class BulkEditFieldsetFactory implements FactoryInterface
{
    /**
     * Create and return the BulkEditFieldset.
     *
     * @return \BulkEdit\Form\BulkEditFieldset
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {

        // Append some infos about datatypes for js.
        $mainDataType = $services->get('ViewHelperManager')->get('mainDataType');
        $dataTypeManager = $services->get('Omeka\DataTypeManager');
        $datatypesMain = [];
        $datatypesLabels = [];
        foreach ($dataTypeManager->getRegisteredNames() as $datatype) {
            $datatypesMain[$datatype] = $mainDataType($datatype);
            $datatypesLabels[$datatype] = $dataTypeManager->get($datatype)->getLabel();
        }

        $fieldset = new BulkEditFieldset(null, $options);
        return $fieldset
            ->setDataTypesMain($datatypesMain)
            ->setDataTypesLabels($datatypesLabels);
    }
}
