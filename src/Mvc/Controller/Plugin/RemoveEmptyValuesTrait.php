<?php declare(strict_types=1);

namespace BulkEdit\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;

trait RemoveEmptyValuesTrait
{
    /**
     * Remove empty values, even with a language, and their annotations.
     *
     * @return int Number of removed values.
     */
    protected function removeEmptyValues(Connection $connection, ?array $resourceIds): int
    {
        $bind = [];
        $types = [];
        $where = <<<'SQL'
            WHERE `value_resource_id` IS NULL
                AND `value` IS NULL
                AND `uri` IS NULL
            SQL;
        if ($resourceIds !== null) {
            $where .= "\n" . <<<'SQL'
                AND `resource_id` IN (:resource_ids)
            SQL;
            $bind['resource_ids'] = $resourceIds;
            $types['resource_ids'] = Connection::PARAM_INT_ARRAY;
        }

        // The annotations of the removed values are removed too, else they
        // remain orphan.
        $valueAnnotationIds = $connection->executeQuery(
            "SELECT `value_annotation_id` FROM `value` $where AND `value_annotation_id` IS NOT NULL",
            $bind,
            $types
        )->fetchFirstColumn();

        $count = (int) $connection->executeStatement("DELETE FROM `value` $where", $bind, $types);

        if ($valueAnnotationIds) {
            $ids = array_map('intval', $valueAnnotationIds);
            $connection->executeStatement(
                'DELETE FROM `value` WHERE `resource_id` IN (:ids)',
                ['ids' => $ids],
                ['ids' => Connection::PARAM_INT_ARRAY]
            );
            $connection->executeStatement(
                'DELETE FROM `resource` WHERE `id` IN (:ids) AND `resource_type` = :type',
                ['ids' => $ids, 'type' => \Omeka\Entity\ValueAnnotation::class],
                ['ids' => Connection::PARAM_INT_ARRAY]
            );
        }

        return $count;
    }
}
