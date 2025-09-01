<?php declare(strict_types=1);

namespace BulkEdit\Service\ControllerPlugin;

use BulkEdit\Mvc\Controller\Plugin\DeduplicateValues;
use Doctrine\DBAL\Connection;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class DeduplicateValuesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        return new DeduplicateValues(
            $connection,
            $services->get('Omeka\Logger'),
            $this->supportAnyValue($services)
        );
    }

    protected function supportAnyValue(Connection $connection): bool
    {
        // To do a request is the simpler way to check if the flag ONLY_FULL_GROUP_BY
        // is set in any databases, systems and versions and that it can be
        // bypassed by Any_value().
        $sql = 'SELECT ANY_VALUE(id) FROM user LIMIT 1;';
        try {
            $connection->executeQuery($sql)->fetchOne();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
