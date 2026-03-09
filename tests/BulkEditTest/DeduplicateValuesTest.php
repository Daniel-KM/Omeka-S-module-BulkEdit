<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test deduplication of values with value annotations and annotation
 * body/target parts.
 *
 * Covers all cross-field cases:
 * - Same value in annotation + body → conserved
 * - Same value in annotation + target → conserved
 * - Same value in body + target → conserved
 * - Same value in body1 + body2 → conserved
 * - True duplicate in same body → deduplicated
 * - Values with different value_annotation_id → conserved
 * - Values without annotation_value table → standard dedup
 */
class DeduplicateValuesTest extends AbstractHttpControllerTestCase
{
    use BulkEditTestTrait;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $conn;

    /**
     * @var bool Whether annotation_value table was created by test.
     */
    protected $createdAnnotationTable = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        $this->conn = $this->getConnection();
    }

    public function tearDown(): void
    {
        $this->dropAnnotationTable();
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Create annotation_value table for testing.
     */
    protected function createAnnotationTable(): void
    {
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `annotation_value` (
                `id` INT NOT NULL,
                `annotation_id` INT NOT NULL,
                `field` VARCHAR(190) NOT NULL,
                `ordinal` SMALLINT NOT NULL DEFAULT 1,
                PRIMARY KEY (`id`),
                INDEX `idx_annotation_value_parts`
                    (`annotation_id`, `field`, `ordinal`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            SQL
        );
        $this->createdAnnotationTable = true;
    }

    /**
     * Drop annotation_value table if we created it.
     */
    protected function dropAnnotationTable(): void
    {
        if ($this->createdAnnotationTable) {
            $this->conn->executeStatement(
                'DROP TABLE IF EXISTS `annotation_value`'
            );
            $this->createdAnnotationTable = false;
        }
    }

    /**
     * Insert a raw value row and return its ID.
     */
    protected function insertValue(
        int $resourceId,
        int $propertyId,
        string $value,
        string $type = 'literal',
        ?int $valueAnnotationId = null
    ): int {
        $this->conn->executeStatement(
            'INSERT INTO `value`'
            . ' (`resource_id`, `property_id`, `type`,'
            . ' `value`, `is_public`, `value_annotation_id`)'
            . ' VALUES (?, ?, ?, ?, 1, ?)',
            [
                $resourceId,
                $propertyId,
                $type,
                $value,
                $valueAnnotationId,
            ]
        );
        return (int) $this->conn->lastInsertId();
    }

    /**
     * Create a value_annotation resource and return its ID.
     */
    protected function createValueAnnotation(): int
    {
        $this->conn->executeStatement(
            'INSERT INTO `resource`'
            . ' (`owner_id`, `resource_class_id`,'
            . ' `resource_template_id`, `thumbnail_id`,'
            . ' `title`, `is_public`, `created`, `modified`,'
            . ' `resource_type`)'
            . " VALUES (1, NULL, NULL, NULL, NULL, 1,"
            . " NOW(), NOW(),"
            . " 'Omeka\\\\Entity\\\\ValueAnnotation')"
        );
        $id = (int) $this->conn->lastInsertId();
        $this->conn->executeStatement(
            'INSERT INTO `value_annotation` (`id`) VALUES (?)',
            [$id]
        );
        return $id;
    }

    /**
     * Insert an annotation_value row.
     */
    protected function insertAnnotationValue(
        int $valueId,
        int $annotationId,
        string $field,
        int $ordinal = 1
    ): void {
        $this->conn->executeStatement(
            'INSERT INTO `annotation_value`'
            . ' (`id`, `annotation_id`, `field`, `ordinal`)'
            . ' VALUES (?, ?, ?, ?)',
            [$valueId, $annotationId, $field, $ordinal]
        );
    }

    /**
     * Check if a value row exists.
     */
    protected function valueExists(int $valueId): bool
    {
        return (bool) $this->conn->executeQuery(
            'SELECT 1 FROM `value` WHERE `id` = ?',
            [$valueId]
        )->fetchOne();
    }

    /**
     * Get the deduplicateValues plugin.
     *
     * @return \BulkEdit\Mvc\Controller\Plugin\DeduplicateValues
     */
    protected function deduplicateValues()
    {
        return $this->getServiceLocator()
            ->get('ControllerPluginManager')
            ->get('deduplicateValues');
    }

    /**
     * Standard deduplication: true duplicates are removed.
     */
    public function testDeduplicateTrueDuplicates(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test dedup'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        // Insert two identical values.
        $v1 = $this->insertValue(
            $resourceId, $propId, 'duplicate'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'duplicate'
        );

        $this->assertTrue($this->valueExists($v1));
        $this->assertTrue($this->valueExists($v2));

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(1, $count);
        // One kept (MIN id), one removed.
        $this->assertTrue($this->valueExists($v1));
        $this->assertFalse($this->valueExists($v2));
    }

    /**
     * Values with different value_annotation_id are not duplicates.
     */
    public function testValueAnnotationPreventsDedupe(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test VA'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        // Same value, but different value_annotation_id.
        $vaId = $this->createValueAnnotation();
        $v1 = $this->insertValue(
            $resourceId, $propId, 'annotated', 'literal', null
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'annotated', 'literal', $vaId
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(0, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertTrue($this->valueExists($v2));
    }

    /**
     * Without annotation_value table, standard dedup works.
     */
    public function testDeduplicateWithoutAnnotateModule(): void
    {
        // Ensure no annotation_value table.
        $has = (bool) $this->conn->executeQuery(
            "SHOW TABLES LIKE 'annotation_value'"
        )->fetchOne();
        $this->assertFalse(
            $has,
            'annotation_value should not exist in test db'
        );

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test no annotate'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $resourceId, $propId, 'dup'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'dup'
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(1, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertFalse($this->valueExists($v2));
    }

    /**
     * Same value in annotation field + body field → conserved.
     */
    public function testAnnotationAndBodySameValueConserved(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test ann+body'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $resourceId, $propId, 'same'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'same'
        );

        // v1 is annotation-level, v2 is body.
        $this->insertAnnotationValue(
            $v1, $resourceId, 'annotation', 0
        );
        $this->insertAnnotationValue(
            $v2, $resourceId, 'body', 1
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(0, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertTrue($this->valueExists($v2));
    }

    /**
     * Same value in annotation field + target field → conserved.
     */
    public function testAnnotationAndTargetSameValueConserved(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test ann+target'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $resourceId, $propId, 'same'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'same'
        );

        $this->insertAnnotationValue(
            $v1, $resourceId, 'annotation', 0
        );
        $this->insertAnnotationValue(
            $v2, $resourceId, 'target', 1
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(0, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertTrue($this->valueExists($v2));
    }

    /**
     * Same value in body + target → conserved.
     */
    public function testBodyAndTargetSameValueConserved(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test body+target'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $resourceId, $propId, 'same'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'same'
        );

        $this->insertAnnotationValue(
            $v1, $resourceId, 'body', 1
        );
        $this->insertAnnotationValue(
            $v2, $resourceId, 'target', 1
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(0, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertTrue($this->valueExists($v2));
    }

    /**
     * Same value in body ordinal 1 + body ordinal 2 → conserved.
     */
    public function testTwoBodiesSameValueConserved(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test body1+body2'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $resourceId, $propId, 'same'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'same'
        );

        $this->insertAnnotationValue(
            $v1, $resourceId, 'body', 1
        );
        $this->insertAnnotationValue(
            $v2, $resourceId, 'body', 2
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(0, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertTrue($this->valueExists($v2));
    }

    /**
     * True duplicate within same body (same field + ordinal)
     * → deduplicated.
     */
    public function testSameBodyTrueDuplicateDeduplicated(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test same body dup'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $resourceId, $propId, 'same'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'same'
        );

        // Same field AND same ordinal → true duplicate.
        $this->insertAnnotationValue(
            $v1, $resourceId, 'body', 1
        );
        $this->insertAnnotationValue(
            $v2, $resourceId, 'body', 1
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(1, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertFalse($this->valueExists($v2));
    }

    /**
     * Non-annotation values (items) are not affected by the
     * annotation_value table presence.
     */
    public function testNonAnnotationValuesWithAnnotateTable(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test normal item'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        // Two identical values on a normal item (no annotation_value
        // rows) → true duplicate, should be deduplicated.
        $v1 = $this->insertValue(
            $resourceId, $propId, 'dup'
        );
        $v2 = $this->insertValue(
            $resourceId, $propId, 'dup'
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        $this->assertEquals(1, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertFalse($this->valueExists($v2));
    }

    /**
     * Mixed scenario: annotation values conserved, normal duplicates
     * removed, all in one pass.
     */
    public function testMixedAnnotationAndNormalDedup(): void
    {
        $this->createAnnotationTable();

        $item = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Test mixed'],
            ],
        ]);
        $resourceId = $item->id();
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propSubject = $easyMeta->propertyId('dcterms:subject');
        $propDesc = $easyMeta->propertyId('dcterms:description');

        // Annotation values: body+target same value → conserved.
        $va1 = $this->insertValue(
            $resourceId, $propSubject, 'cross-field'
        );
        $va2 = $this->insertValue(
            $resourceId, $propSubject, 'cross-field'
        );
        $this->insertAnnotationValue(
            $va1, $resourceId, 'body', 1
        );
        $this->insertAnnotationValue(
            $va2, $resourceId, 'target', 1
        );

        // Normal values: true duplicate → deduplicated.
        $vn1 = $this->insertValue(
            $resourceId, $propDesc, 'normal-dup'
        );
        $vn2 = $this->insertValue(
            $resourceId, $propDesc, 'normal-dup'
        );

        $count = ($this->deduplicateValues())([$resourceId]);

        // Only the normal duplicate removed.
        $this->assertEquals(1, $count);
        // Annotation values conserved.
        $this->assertTrue($this->valueExists($va1));
        $this->assertTrue($this->valueExists($va2));
        // Normal duplicate: first kept, second removed.
        $this->assertTrue($this->valueExists($vn1));
        $this->assertFalse($this->valueExists($vn2));
    }

    /**
     * Dedup all resources (null argument) works correctly.
     */
    public function testDeduplicateAllResources(): void
    {
        $item1 = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Item 1'],
            ],
        ]);
        $item2 = $this->createItem([
            'dcterms:title' => [
                ['@value' => 'Item 2'],
            ],
        ]);
        $easyMeta = $this->getServiceLocator()
            ->get('Common\EasyMeta');
        $propId = $easyMeta->propertyId('dcterms:subject');

        $v1 = $this->insertValue(
            $item1->id(), $propId, 'dup1'
        );
        $v2 = $this->insertValue(
            $item1->id(), $propId, 'dup1'
        );
        $v3 = $this->insertValue(
            $item2->id(), $propId, 'dup2'
        );
        $v4 = $this->insertValue(
            $item2->id(), $propId, 'dup2'
        );

        // Pass null to dedup all.
        $count = ($this->deduplicateValues())(null);

        $this->assertGreaterThanOrEqual(2, $count);
        $this->assertTrue($this->valueExists($v1));
        $this->assertFalse($this->valueExists($v2));
        $this->assertTrue($this->valueExists($v3));
        $this->assertFalse($this->valueExists($v4));
    }
}
