<?php declare(strict_types=1);
namespace BulkEdit\Service\ControllerPlugin;

use BulkEdit\Mvc\Controller\Plugin\TrimValues;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TrimValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new TrimValues(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Logger')
        );
    }
}
