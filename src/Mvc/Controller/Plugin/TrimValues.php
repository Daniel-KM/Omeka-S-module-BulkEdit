<?php declare(strict_types=1);
namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class TrimValues extends AbstractPlugin
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
     * @param EntityManager $entityManager
     * @param LoggerInterface $logger
     */
    public function __construct(EntityManager $entityManager, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    /**
     * Trim specified or all resource values and remove values that are empty.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of trimmed values.
     */
    public function __invoke(array $resourceIds = null)
    {
        if (!is_null($resourceIds)) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        // Use a direct query: during a post action, data are already flushed.
        // The entity manager can not be used directly, because it doesn't
        // manage regex.
        $connection = $this->entityManager->getConnection();

        $idsString = is_null($resourceIds) ? '' : implode(',', $resourceIds);

        // Sql "trim" is for space " " only, not end of line, new line or tab.
        // So use regexp_replace, but it's available only with mysql ≥ 8.0.4 and
        // mariadb ≥ 10.0.5 and Omeka requires only 5.5.3.
        $db = $this->databaseVersion();

        if (($db['db'] === 'mariadb' && version_compare($db['version'], '10.0.5', '>='))
            || ($db['db'] === 'mysql' && version_compare($db['version'], '8.0.4', '>='))
        ) {
            // The pattern is a full unicode one.
            $query = <<<'SQL'
UPDATE `value` AS `v`
SET
`v`.`value` = NULLIF(REGEXP_REPLACE(`v`.`value`, "^[\\s\\h\\v[:blank:][:space:]]+|[\\s\\h\\v[:blank:][:space:]]+$", ""), ""),
`v`.`lang` = NULLIF(REGEXP_REPLACE(`v`.`lang`, "^[\\s\\h\\v[:blank:][:space:]]+|[\\s\\h\\v[:blank:][:space:]]+$", ""), ""),
`v`.`uri` = NULLIF(REGEXP_REPLACE(`v`.`uri`, "^[\\s\\h\\v[:blank:][:space:]]+|[\\s\\h\\v[:blank:][:space:]]+$", ""), "")
SQL;
        } else {
            // The pattern uses a simple trim.
            $query = <<<'SQL'
UPDATE `value` AS `v`
SET
`v`.`value` = NULLIF(TRIM(TRIM("\t" FROM TRIM("\n" FROM TRIM("\r" FROM TRIM("\n" FROM `v`.`value`))))), ""),
`v`.`lang` = NULLIF(TRIM(TRIM("\t" FROM TRIM("\n" FROM TRIM("\r" FROM TRIM("\n" FROM `v`.`lang`))))), ""),
`v`.`uri` = NULLIF(TRIM(TRIM("\t" FROM TRIM("\n" FROM TRIM("\r" FROM TRIM("\n" FROM `v`.`uri`))))), "")
SQL;
        }

        if ($idsString) {
            $query .= "\n" . <<<SQL
WHERE `v`.`resource_id` IN ($idsString)
SQL;
        }

        $processed = $connection->exec($query);
        if ($processed) {
            $this->logger->info(sprintf('Trimmed %d values.', $processed));
        }

        // Remove empty values, even if there is a language.
        $query = <<<'SQL'
DELETE FROM `value`
WHERE `value_resource_id` IS NULL
AND `value` IS NULL
AND `uri` IS NULL
SQL;
        if ($idsString) {
            $query .= "\n" . <<<SQL
AND `resource_id` IN ($idsString)
SQL;
        }

        $deleted = $connection->exec($query);
        if ($deleted) {
            $this->logger->info(sprintf('Removed %d empty string values after trimming.', $deleted));
        }
        return $processed;
    }

    /**
     * Get  the version of the database.
     *
     * @return array with keys "db" and "version".
     */
    protected function databaseVersion()
    {
        $result = [
            'db' => '',
            'version' => '',
        ];

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->entityManager->getConnection();

        $sql = 'SHOW VARIABLES LIKE "version";';
        $stmt = $connection->query($sql);
        $version = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        $version = reset($version);

        $isMySql = stripos($version, 'mysql') !== false;
        if ($isMySql) {
            $result['db'] = 'mysql';
            $result['version'] = $version;
            return $result;
        }

        $isMariaDb = stripos($version, 'mariadb') !== false;
        if ($isMariaDb) {
            $result['db'] = 'mariadb';
            $result['version'] = $version;
            return $result;
        }

        $sql = 'SHOW VARIABLES LIKE "innodb_version";';
        $stmt = $connection->query($sql);
        $version = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        $version = reset($version);
        $isInnoDb = !empty($version);
        if ($isInnoDb) {
            $result['db'] = 'innodb';
            $result['version'] = $version;
            return $result;
        }

        return $result;
    }
}
