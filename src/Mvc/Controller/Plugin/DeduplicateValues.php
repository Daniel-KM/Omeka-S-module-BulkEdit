<?php declare(strict_types=1);

namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Adapted:
 * @see \EasyAdmin\Job\DbValueClean
 * @see \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues
 */
class DeduplicateValues extends AbstractPlugin
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * The logger is required, because there is no controller during job.
     *
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;
    }

    /**
     * Deduplicate specified or all resource values.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of deduplicated values.
     */
    public function __invoke(?array $resourceIds = null): int
    {
        $count = $this->deduplicateValuesViaSql($resourceIds);

        if ($count) {
            $this->logger->info(
                'Deduplicated {count} values.', // @translate
                ['count' => $count]
            );
        }

        return (int) $count;
    }

    protected function deduplicateValuesViaSql(?array $resourceIds = null): int
    {
        if ($resourceIds !== null) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        $bind = [];
        $types = [];

        if ($resourceIds) {
            // For specified values.
            $sqlWhere1 = 'WHERE `resource_id` IN (:resource_ids)';
            $sqlWhere2 = 'AND `resource_id` IN (:resource_ids)';
            $bind['resource_ids'] = $resourceIds;
            $types['resource_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        } else {
            // For all values.
            $sqlWhere1 = '';
            $sqlWhere2 = '';
        }

        // Use MIN(id) to get one ID per group of duplicates.
        // This is standard SQL that works with ONLY_FULL_GROUP_BY enabled,
        // avoiding the need to modify sql_mode which could affect other queries.
        // For deduplication, keeping the value with the lowest ID is reasonable.

        // Drop temporary table if it exists from a previous failed run.
        $this->connection->executeStatement('DROP TABLE IF EXISTS `value_temporary`');

        // Create temporary table with one ID per unique value combination.
        $sqlCreate = <<<SQL
            CREATE TEMPORARY TABLE `value_temporary` (`id` INT, PRIMARY KEY (`id`))
            AS
                SELECT MIN(`id`) AS `id`
                FROM `value`
                $sqlWhere1
                GROUP BY `resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`
            SQL;
        $this->connection->executeStatement($sqlCreate, $bind, $types);

        // Delete duplicates (values not in the temporary table).
        $sqlDelete = <<<SQL
            DELETE `v`
            FROM `value` AS `v`
            LEFT JOIN `value_temporary` AS `value_temporary`
                ON `value_temporary`.`id` = `v`.`id`
            WHERE `value_temporary`.`id` IS NULL
                $sqlWhere2
            SQL;
        $count = $this->connection->executeStatement($sqlDelete, $bind, $types);

        // Clean up temporary table.
        $this->connection->executeStatement('DROP TABLE IF EXISTS `value_temporary`');

        return (int) $count;
    }
}
