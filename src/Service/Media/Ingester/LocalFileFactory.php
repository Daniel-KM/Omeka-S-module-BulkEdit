<?php declare(strict_types=1);

namespace BulkEdit\Service\Media\Ingester;

use BulkEdit\Media\Ingester\LocalFile;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class LocalFileFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new LocalFile($services->get('Omeka\File\TempFileFactory'));
    }
}
