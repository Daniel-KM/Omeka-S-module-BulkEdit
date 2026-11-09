<?php declare(strict_types=1);

namespace BulkEdit\Service\Controller\Admin;

use BulkEdit\Controller\Admin\IndexController;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController(
            $services->get('ModuleManager')->getModule('BulkEdit')
        );
    }
}
