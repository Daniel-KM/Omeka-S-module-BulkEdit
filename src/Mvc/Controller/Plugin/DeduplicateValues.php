<?php declare(strict_types=1);

namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class DeduplicateValues extends AbstractPlugin
{
    /**
     * @var EntityManager
     */
    protected $entityManager;

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

    /**
     * @param EntityManager $entityManager
     * @param LoggerInterface $logger
     * @param bool $supportAnyValue
     */
    public function __construct(EntityManager $entityManager, LoggerInterface $logger, bool $supportAnyValue)
    {
        $this->entityManager = $entityManager;
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
    public function __invoke(array $resourceIds = null)
    {
        if (!is_null($resourceIds)) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        // For large base, a temporary table is prefered to speed process.
        $connection = $this->entityManager->getConnection();

        // The query modifies the sql mode, so it should be reset.
        $sqlMode = $connection->fetchColumn('SELECT @@SESSION.sql_mode;');

        $query = is_null($resourceIds)
            ? $this->prepareQuery()
            : $this->prepareQueryForResourceIds($resourceIds);

        $processed = $connection->exec($query);

        $connection->exec("SET sql_mode = '$sqlMode';");

        if ($processed) {
            $this->logger->info(sprintf('Deduplicated %d values.', $processed));
        }
        return $processed;
    }

    protected function prepareQuery()
    {
        if ($this->supportAnyValue) {
            $prefix = 'ANY_VALUE(';
            $suffix = ')';
        } else {
            $prefix = $suffix = '';
        }
        return <<<SQL
SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''));
DROP TABLE IF EXISTS `value_temporary`;
CREATE TEMPORARY TABLE `value_temporary` (`id` INT, PRIMARY KEY (`id`))
AS
    SELECT $prefix`id`$suffix
    FROM `value`
    GROUP BY `resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`;
DELETE `v` FROM `value` AS `v`
LEFT JOIN `value_temporary` AS `value_temporary`
    ON `value_temporary`.`id` = `v`.`id`
WHERE `value_temporary`.`id` IS NULL;
DROP TABLE IF EXISTS `value_temporary`;
SQL;
    }

    protected function prepareQueryForResourceIds(array $resourceIds)
    {
        if ($this->supportAnyValue) {
            $prefix = 'ANY_VALUE(';
            $suffix = ')';
        } else {
            $prefix = $suffix = '';
        }
        $idsString = implode(',', $resourceIds);
        return <<<SQL
SET sql_mode=(SELECT REPLACE(@@sql_mode, 'ONLY_FULL_GROUP_BY', ''));
DROP TABLE IF EXISTS `value_temporary`;
CREATE TEMPORARY TABLE `value_temporary` (`id` INT, PRIMARY KEY (`id`))
AS
    SELECT $prefix`id`$suffix
    FROM `value`
    WHERE `resource_id` IN ($idsString)
    GROUP BY `resource_id`, `property_id`, `value_resource_id`, `type`, `lang`, `value`, `uri`, `is_public`;
DELETE `v` FROM `value` AS `v`
    LEFT JOIN `value_temporary` AS `value_temporary`
    ON `value_temporary`.`id` = `v`.`id`
WHERE `resource_id` IN ($idsString)
    AND `value_temporary`.`id` IS NULL;
DROP TABLE IF EXISTS `value_temporary`;
SQL;
    }
}
