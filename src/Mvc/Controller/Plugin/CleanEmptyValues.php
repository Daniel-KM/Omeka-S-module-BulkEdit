<?php declare(strict_types=1);

namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class CleanEmptyValues extends AbstractPlugin
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
     * Set "null" when values, uri or linked resource is empty.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of cleaned values.
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
        // The entity manager may be used directly, but it is simpler with sql.
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->entityManager->getConnection();

        $sql = <<<'SQL'
UPDATE `value` AS `v`
SET
    `v`.`value` = IF(`v`.`value` IS NULL OR `v`.`value` = "", NULL, `v`.`value`),
    `v`.`uri` = IF(`v`.`uri` IS NULL OR `v`.`uri` = "", NULL, `v`.`uri`)
SQL;

        $idsString = is_null($resourceIds) ? '' : implode(',', $resourceIds);
        if ($idsString) {
            $sql .= "\n" . <<<SQL
WHERE `v`.`resource_id` IN ($idsString)
SQL;
        }

        $count = $connection->executeStatement($sql);
        if ($count) {
            $this->logger->info(
                'Updated empty values and uris of {count} values.', // @translate
                ['count' => $count]
            );
        }
        return $count;
    }
}
