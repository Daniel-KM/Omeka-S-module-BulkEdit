<?php declare(strict_types=1);

namespace BulkEdit\Service\Form\Element;

use BulkEdit\Form\Element\DataTypeSelect;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DataTypeSelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new DataTypeSelect(null, $options ?? []);
        return $element
            ->setDataTypeManager($services->get('Omeka\DataTypeManager'));
    }
}
