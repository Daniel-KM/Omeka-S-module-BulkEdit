<?php declare(strict_types=1);
namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

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
        // The entity manager may be used directly, but it is simpler with sql.
        $connection = $this->entityManager->getConnection();

        $sql = <<<'SQL'
UPDATE `value` AS `v`
SET `v`.`lang` = NULL
WHERE `v`.`lang` = ''
SQL;

        $idsString = is_null($resourceIds) ? '' : implode(',', $resourceIds);
        if ($idsString) {
            $sql .= "\n" . <<<SQL
AND `v`.`resource_id` IN ($idsString)
SQL;
        }

        $processed = $connection->executeStatement($sql);
        if ($processed) {
            $this->logger->info(sprintf('Updated empty language of %d values.', $processed));
        }
        return $processed;
    }
}
