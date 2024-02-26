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
        /**  @var \Common\Stdlib\EasyMeta $easyMeta */
        $easyMeta = $services->get('EasyMeta');

        // Append some infos about datatypes for js.
        // TODO Use Common 3.4.55.
        $dataTypesMain = [];
        foreach ($easyMeta->dataTypeNames() as $dataType) {
            $dataTypesMain[$dataType] = $easyMeta->dataTypeMain($dataType);
        }

        $connection = $services->get('Omeka\Connection');

        $result = $connection->executeQuery('SELECT DISTINCT(ingester) FROM media ORDER BY ingester ASC')->fetchFirstColumn();
        $ingesters = array_combine($result, $result);

        $result = $connection->executeQuery('SELECT DISTINCT(renderer) FROM media ORDER BY renderer ASC')->fetchFirstColumn();
        $renderers = array_combine($result, $result);

        $fieldset = new BulkEditFieldset(null, $options ?? []);
        return $fieldset
            // TODO Fix translation of fieldset legend in core.
            ->setTranslator($services->get('MvcTranslator'))
            ->setDataTypesMain($dataTypesMain)
            ->setDataTypesLabels($easyMeta->dataTypeLabels())
            ->setIngesters($ingesters)
            ->setRenderers($renderers)
        ;
    }
}
