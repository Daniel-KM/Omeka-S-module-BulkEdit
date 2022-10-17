<?php declare(strict_types=1);

namespace BulkEdit\Service\ViewHelper;

use BulkEdit\View\Helper\MainDataType;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

/**
 * @see \AdvancedResourceTemplate\\View\Helper\MainDataType
 * @see \BulkEdit\View\Helper\MainDataType
 * @see \BulkExport\View\Helper\MainDataType
 * @see \BulkImport\View\Helper\MainDataType
 * Used in Contribute.
 */
class MainDataTypeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new MainDataType(
            $services->get('ViewHelperManager')->get('customVocabBaseType')
        );
    }
}
