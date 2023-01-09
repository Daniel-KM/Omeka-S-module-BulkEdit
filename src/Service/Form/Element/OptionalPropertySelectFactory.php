<?php declare(strict_types=1);

namespace BulkEdit\Service\Form\Element;

use BulkEdit\Form\Element\OptionalPropertySelect;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OptionalPropertySelectFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $element = new OptionalPropertySelect(null, $options ?? []);
        $element->setApiManager($services->get('Omeka\ApiManager'));
        $element->setEventManager($services->get('EventManager'));
        $element->setTranslator($services->get('MvcTranslator'));
        return $element;
    }
}
