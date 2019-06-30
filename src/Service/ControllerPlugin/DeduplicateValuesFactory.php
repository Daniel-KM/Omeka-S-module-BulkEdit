<?php
namespace BulkEdit\Service\ControllerPlugin;

use BulkEdit\Mvc\Controller\Plugin\DeduplicateValues;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

class DeduplicateValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedNamed, array $options = null)
    {
        return new DeduplicateValues(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Logger')
        );
    }
}
