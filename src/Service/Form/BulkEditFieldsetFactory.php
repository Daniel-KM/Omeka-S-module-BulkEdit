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
        /** @var \BulkEdit\View\Helper\MainDataType $mainDataType */
        $mainDataType = $services->get('ViewHelperManager')->get('mainDataType');
        $dataTypeManager = $services->get('Omeka\DataTypeManager');
        $datatypesMain = [];
        $datatypesLabels = [];
        foreach ($dataTypeManager->getRegisteredNames() as $datatype) {
            $datatypesMain[$datatype] = $mainDataType($datatype);
            $datatypesLabels[$datatype] = $dataTypeManager->get($datatype)->getLabel();
        }

        $connection = $services->get('Omeka\Connection');

        $result = $connection->executeQuery('SELECT DISTINCT(media_type) FROM media WHERE media_type IS NOT NULL AND media_type != "" ORDER BY media_type')->fetchFirstColumn();
        $mediaTypes = array_combine($result, $result);

        $result = $connection->executeQuery('SELECT DISTINCT(ingester) FROM media ORDER BY ingester')->fetchFirstColumn();
        $ingesters = array_combine($result, $result);

        $result = $connection->executeQuery('SELECT DISTINCT(renderer) FROM media ORDER BY renderer')->fetchFirstColumn();
        $renderers = array_combine($result, $result);

        $fieldset = new BulkEditFieldset(null, $options ?? []);
        return $fieldset
            ->setDataTypesMain($datatypesMain)
            ->setDataTypesLabels($datatypesLabels)
            ->setMediaTypes($mediaTypes)
            ->setIngesters($ingesters)
            ->setRenderers($renderers)
        ;
    }
}
