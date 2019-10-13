<?php
namespace BulkEdit\Service\Form;

use BulkEdit\Form\BulkEditFieldset;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

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
        return new BulkEditFieldset(null, $options);
    }
}
