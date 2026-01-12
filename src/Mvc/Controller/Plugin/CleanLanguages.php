<?php declare(strict_types=1);

namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Adapted:
 * @see \EasyAdmin\Job\DbValueClean
 * @see \BulkEdit\Mvc\Controller\Plugin\CleanLanguages
 */
class CleanLanguages extends AbstractPlugin
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
     * Set "null" when language is empty.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @return int Number of cleaned values.
     */
    public function __invoke(?array $resourceIds = null): int
    {
        if ($resourceIds !== null) {
            $resourceIds = array_filter(array_map('intval', $resourceIds));
            if (!count($resourceIds)) {
                return 0;
            }
        }

        // Use a direct query: during a post action, data are already flushed.
        // The entity manager may be used directly, but it is simpler with sql.
        $connection = $this->entityManager->getConnection();

        $bind = [];
        $types = [];

        $sql = <<<'SQL'
            UPDATE `value` AS `v`
            SET `v`.`lang` = NULL
            WHERE `v`.`lang` = ''
            SQL;

        if ($resourceIds !== null) {
            $sql .= "\n" . <<<'SQL'
                AND `v`.`resource_id` IN (:resource_ids)
                SQL;
            $bind['resource_ids'] = $resourceIds;
            $types['resource_ids'] = Connection::PARAM_INT_ARRAY;
        }

        $count = $connection->executeStatement($sql, $bind, $types);
        if ($count) {
            $this->logger->info(
                'Updated empty language of {count} values.', // @translate
                ['count' => $count]
            );
        }

        return (int) $count;
    }
}
