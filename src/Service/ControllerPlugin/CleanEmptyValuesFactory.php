<?php declare(strict_types=1);

namespace BulkEdit\Service\ControllerPlugin;

use BulkEdit\Mvc\Controller\Plugin\CleanEmptyValues;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CleanEmptyValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new CleanEmptyValues(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Logger')
        );
    }
}
