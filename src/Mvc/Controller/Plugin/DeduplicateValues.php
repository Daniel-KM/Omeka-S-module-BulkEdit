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

    /**
     * @param bool
     */
    protected $supportAnyValue;

    public function __construct(Connection $connection, LoggerInterface $logger, bool $supportAnyValue)
    {
        $this->connection = $connection;
        $this->logger = $logger;
        $this->supportAnyValue = $supportAnyValue;
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

        // The query modifies the sql mode, so it should be reset.
        $sqlMode = $this->connection->fetchOne('SELECT @@SESSION.sql_mode;');

        // For large base, a temporary table is prefered to speed process.
        // TODO Remove "Any_value", but it cannot be replaced by "Min".
        if ($this->supportAnyValue) {
            $prefix = 'ANY_VALUE(';
            $suffix = ')';
        } else {
            $prefix = $suffix = '';
        }

        if ($resourceIds) {
            // For specified values.
            $sqlWhere1 = 'WHERE `resource_id` IN (:resource_ids)';
            $sqlWhere2 = 'AND`resource_id` IN (:resource_ids)';
            $bind['resource_ids'] = $resourceIds;
            $types['resource_ids'] = \Doctrine\DBAL\Connection::PARAM_INT_ARRAY;
        } else {
            // For all values.
            $sqlWhere1 = '';
            $sqlWhere2 = '';
        }

        $sql = <<<SQL
            SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''));
            DROP TABLE IF EXISTS `value_temporary`;
            CREATE TEMPORARY TABLE `value_temporary` (`id` INT, PRIMARY KEY (`id`))
            AS
                SELECT $prefix`id`$suffix
                FROM `value`
                $sqlWhere1
                GROUP BY `resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`;
            DELETE `v`
            FROM `value` AS `v`
            LEFT JOIN `value_temporary` AS `value_temporary`
                ON `value_temporary`.`id` = `v`.`id`
            WHERE `value_temporary`.`id` IS NULL
                $sqlWhere2;
            DROP TABLE IF EXISTS `value_temporary`;
            SQL;

        $count = $this->connection->executeStatement($sql, $bind, $types);
        $this->connection->executeStatement("SET sql_mode = '$sqlMode';");

        return (int) $count;
    }
}
