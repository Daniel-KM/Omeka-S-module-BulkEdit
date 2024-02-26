<?php declare(strict_types=1);

namespace BulkEdit\Service\Stdlib;

use BulkEdit\Stdlib\BulkEdit;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class BulkEditFactory implements FactoryInterface
{
    /**
     * Create the BulkEdit service.
     *
     * @return BulkEdit
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new BulkEdit(
            $services
        );
    }
}
