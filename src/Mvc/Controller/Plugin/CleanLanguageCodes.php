<?php declare(strict_types=1);

namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\ORM\EntityManager;
use Laminas\Log\LoggerInterface;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class CleanLanguageCodes extends AbstractPlugin
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
     * Clean and modify from a language code to another one as a whole.
     *
     * @param array|null $resourceIds Passing an empty array means that there is
     * no ids to process. To process all values, pass a null or no argument.
     * @param string|null $from
     * @param string|null $to
     * @param array|null $properties
     * @return int Number of trimmed values.
     */
    public function __invoke(array $resourceIds = null, ?string $from = '', ?string $to = '', ?array $properties = [])
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

        $quotedFrom = empty($from)
            ? '"" OR `v`.`lang` IS NULL'
            : $connection->quote($from);
        $quotedTo = empty($to)
            ? 'NULL'
            : $connection->quote($to);

        $sql = <<<SQL
UPDATE `value` AS `v`
SET
    `v`.`lang` = $quotedTo
WHERE
    `v`.`lang` = $quotedFrom
SQL;

        $idsString = is_null($resourceIds) ? '' : implode(',', $resourceIds);
        if ($idsString) {
            $sql .= "\n" . <<<SQL
AND `v`.`resource_id` IN ($idsString)
SQL;
        }

        if ($properties && !in_array('all', $properties)) {
            $propertyIds = $this->checkPropertyIds($properties);
            if ($propertyIds) {
                $propertyIds = implode(',', $propertyIds);
                $sql .= "\n" . <<<SQL
AND `v`.`property_id` IN ($propertyIds)
SQL;
            } else {
                $sql .= "\n" . <<<'SQL'
AND 1 = 1
SQL;
            }
        }

        $processed = $connection->executeStatement($sql);
        if ($processed) {
            $this->logger->info(sprintf('Updated language from "%s" to "%s" of %d values.', $from, $to, $processed));
        }
        return $processed;
    }

    protected function checkPropertyIds(array $properties)
    {
        return is_numeric(reset($properties))
            ? array_filter(array_map('intval', $properties))
            : array_intersect_key($this->getPropertyIds(), array_flip($properties));
    }

    /**
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    public function getPropertyIds(): array
    {
        $connection = $this->entityManager->getConnection();
        $qb = $connection->createQueryBuilder();
        $qb
            ->select(
                'CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.id AS id',
                // Required with only_full_group_by.
                'vocabulary.id'
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        return array_map('intval', $connection->executeQuery($qb)->fetchAllKeyValue());
    }
}
