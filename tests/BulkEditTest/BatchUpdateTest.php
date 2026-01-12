<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test batch update functionality with string replacement.
 *
 * These tests verify that ALL items are processed during batch edit,
 * including the first and last items in both small and large batches.
 *
 * The batch update job processes items in chunks of 100 (default).
 * Bug investigation: In some cases, the first or last item may be skipped.
 */
class BatchUpdateTest extends AbstractHttpControllerTestCase
{
    use BulkEditTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    /**
     * Test batch string replacement with a small batch (< 100 items).
     *
     * This test verifies that all items in a single chunk are processed,
     * particularly checking that the first and last items are not skipped.
     */
    public function testBatchReplaceSmallBatch(): void
    {
        $itemCount = 50;
        $searchValue = 'FIND_ME_SMALL';
        $replaceValue = 'REPLACED_SMALL';

        // Create test items.
        $items = $this->createItemsWithValue($itemCount, 'SmallBatch', $searchValue);
        $itemIds = array_map(fn($item) => $item->id(), $items);

        // Verify items were created with the search value.
        $firstItem = $this->refreshItem($itemIds[0]);
        $lastItem = $this->refreshItem($itemIds[$itemCount - 1]);
        $this->assertNotNull($firstItem, 'First item should exist');
        $this->assertNotNull($lastItem, 'Last item should exist');
        $this->assertStringContainsString(
            $searchValue,
            $this->getItemValue($firstItem, 'dcterms:description'),
            'First item should contain search value before replacement'
        );
        $this->assertStringContainsString(
            $searchValue,
            $this->getItemValue($lastItem, 'dcterms:description'),
            'Last item should contain search value before replacement'
        );

        // Run batch replacement.
        $job = $this->runBatchReplace(
            ['id' => $itemIds],
            $searchValue,
            $replaceValue,
            'raw',
            ['all']
        );

        $this->assertEquals(
            \Omeka\Entity\Job::STATUS_COMPLETED,
            $job->getStatus(),
            'Job should complete successfully'
        );

        // Verify ALL items were replaced, especially first and last.
        $this->assertAllItemsReplaced(
            $itemIds,
            'dcterms:description',
            $replaceValue,
            $searchValue
        );

        // Explicit check on first and last items.
        $firstItem = $this->refreshItem($itemIds[0]);
        $lastItem = $this->refreshItem($itemIds[$itemCount - 1]);

        $firstValue = $this->getItemValue($firstItem, 'dcterms:description');
        $lastValue = $this->getItemValue($lastItem, 'dcterms:description');

        $this->assertStringContainsString(
            $replaceValue,
            $firstValue,
            "First item (ID: {$itemIds[0]}) should have replacement value"
        );
        $this->assertStringNotContainsString(
            $searchValue,
            $firstValue,
            "First item (ID: {$itemIds[0]}) should NOT have original search value"
        );

        $this->assertStringContainsString(
            $replaceValue,
            $lastValue,
            "Last item (ID: {$itemIds[$itemCount - 1]}) should have replacement value"
        );
        $this->assertStringNotContainsString(
            $searchValue,
            $lastValue,
            "Last item (ID: {$itemIds[$itemCount - 1]}) should NOT have original search value"
        );
    }

    /**
     * Test batch string replacement with a large batch (> 250 items).
     *
     * This test verifies that items across multiple chunks are processed correctly.
     * With 260 items and chunk size of 100, this creates 3 chunks:
     * - Chunk 1: items 1-100
     * - Chunk 2: items 101-200
     * - Chunk 3: items 201-260
     *
     * Bug investigation: Check that items at chunk boundaries are not skipped.
     */
    public function testBatchReplaceLargeBatch(): void
    {
        $itemCount = 260;
        $searchValue = 'FIND_ME_LARGE';
        $replaceValue = 'REPLACED_LARGE';

        // Create test items.
        $items = $this->createItemsWithValue($itemCount, 'LargeBatch', $searchValue);
        $itemIds = array_map(fn($item) => $item->id(), $items);

        // Verify items were created.
        $this->assertCount($itemCount, $itemIds, "Should have created $itemCount items");

        // Verify first, last, and boundary items exist with search value.
        $boundaryIndexes = [
            0,      // First item
            99,     // Last item of chunk 1
            100,    // First item of chunk 2
            199,    // Last item of chunk 2
            200,    // First item of chunk 3
            259,    // Last item (index)
        ];

        foreach ($boundaryIndexes as $index) {
            $item = $this->refreshItem($itemIds[$index]);
            $this->assertNotNull($item, "Item at index $index should exist");
            $this->assertStringContainsString(
                $searchValue,
                $this->getItemValue($item, 'dcterms:description'),
                "Item at index $index should contain search value before replacement"
            );
        }

        // Run batch replacement.
        $job = $this->runBatchReplace(
            ['id' => $itemIds],
            $searchValue,
            $replaceValue,
            'raw',
            ['all']
        );

        $this->assertEquals(
            \Omeka\Entity\Job::STATUS_COMPLETED,
            $job->getStatus(),
            'Job should complete successfully'
        );

        // Verify ALL items were replaced.
        $this->assertAllItemsReplaced(
            $itemIds,
            'dcterms:description',
            $replaceValue,
            $searchValue
        );

        // Explicit check on boundary items (chunk boundaries are critical).
        foreach ($boundaryIndexes as $index) {
            $item = $this->refreshItem($itemIds[$index]);
            $value = $this->getItemValue($item, 'dcterms:description');

            $this->assertStringContainsString(
                $replaceValue,
                $value,
                "Item at index $index (ID: {$itemIds[$index]}) should have replacement value"
            );
            $this->assertStringNotContainsString(
                $searchValue,
                $value,
                "Item at index $index (ID: {$itemIds[$index]}) should NOT have original search value"
            );
        }
    }

    /**
     * Test batch replacement simulating the admin browse "select all" scenario.
     *
     * This test more closely simulates the reported bug scenario where:
     * 1. User searches items in admin browse
     * 2. User selects all results
     * 3. User clicks "Edit all" and chooses string replacement
     *
     * The query is passed without explicit IDs, relying on search parameters.
     */
    public function testBatchReplaceSelectAllScenario(): void
    {
        $itemCount = 110; // Just over one chunk to test boundary
        $searchValue = 'SELECT_ALL_TEST';
        $replaceValue = 'SELECTED_REPLACED';
        $titlePrefix = 'SelectAllItem';

        // Create test items with a unique title prefix for searching.
        $items = $this->createItemsWithValue($itemCount, $titlePrefix, $searchValue);
        $itemIds = array_map(fn($item) => $item->id(), $items);

        // Build a query that simulates "select all" from browse.
        // In real usage, this would be based on search filters.
        $query = [
            'id' => $itemIds,
        ];

        // Run batch replacement.
        $job = $this->runBatchReplace(
            $query,
            $searchValue,
            $replaceValue,
            'raw',
            ['all']
        );

        $this->assertEquals(
            \Omeka\Entity\Job::STATUS_COMPLETED,
            $job->getStatus(),
            'Job should complete successfully'
        );

        // Verify first and last items specifically (reported bug area).
        $firstItem = $this->refreshItem($itemIds[0]);
        $lastItem = $this->refreshItem($itemIds[$itemCount - 1]);

        $this->assertNotNull($firstItem, 'First item should still exist');
        $this->assertNotNull($lastItem, 'Last item should still exist');

        $firstValue = $this->getItemValue($firstItem, 'dcterms:description');
        $lastValue = $this->getItemValue($lastItem, 'dcterms:description');

        // These assertions should catch the reported bug.
        $this->assertStringNotContainsString(
            $searchValue,
            $firstValue,
            "POTENTIAL BUG: First item was NOT processed! Value: $firstValue"
        );
        $this->assertStringNotContainsString(
            $searchValue,
            $lastValue,
            "POTENTIAL BUG: Last item was NOT processed! Value: $lastValue"
        );

        // Verify all items.
        $this->assertAllItemsReplaced(
            $itemIds,
            'dcterms:description',
            $replaceValue,
            $searchValue
        );
    }

    /**
     * Test edge case: batch size exactly at chunk boundary.
     *
     * Test with exactly 100 items (one full chunk) and 200 items (two full chunks).
     */
    public function testBatchReplaceExactChunkBoundary(): void
    {
        // Test with exactly 100 items (one full chunk).
        $searchValue = 'EXACT_100';
        $replaceValue = 'REPLACED_100';

        $items = $this->createItemsWithValue(100, 'Exact100', $searchValue);
        $itemIds = array_map(fn($item) => $item->id(), $items);

        $job = $this->runBatchReplace(
            ['id' => $itemIds],
            $searchValue,
            $replaceValue,
            'raw',
            ['all']
        );

        $this->assertEquals(
            \Omeka\Entity\Job::STATUS_COMPLETED,
            $job->getStatus(),
            'Job should complete successfully for exactly 100 items'
        );

        // Check boundary items.
        $firstItem = $this->refreshItem($itemIds[0]);
        $lastItem = $this->refreshItem($itemIds[99]);

        $this->assertStringNotContainsString(
            $searchValue,
            $this->getItemValue($firstItem, 'dcterms:description'),
            'First item of exact 100 batch should be processed'
        );
        $this->assertStringNotContainsString(
            $searchValue,
            $this->getItemValue($lastItem, 'dcterms:description'),
            'Last item (100th) of exact batch should be processed'
        );
    }

    /**
     * Test batch replacement with regex mode.
     *
     * Verify that regex replacements also process all items correctly.
     */
    public function testBatchReplaceRegexMode(): void
    {
        $itemCount = 55;
        // Regex pattern must include delimiters for preg_replace.
        $searchPattern = '/REGEX_\\d+_TEST/';
        $replaceValue = 'REGEX_REPLACED';

        // Create items with a pattern that matches the regex.
        $items = [];
        for ($i = 1; $i <= $itemCount; $i++) {
            $items[] = $this->createItem([
                'dcterms:title' => [
                    ['type' => 'literal', '@value' => "RegexItem $i"],
                ],
                'dcterms:description' => [
                    ['type' => 'literal', '@value' => "Description REGEX_{$i}_TEST here"],
                ],
            ]);
        }
        $itemIds = array_map(fn($item) => $item->id(), $items);

        // Run batch replacement with regex mode.
        $job = $this->runBatchReplace(
            ['id' => $itemIds],
            $searchPattern,
            $replaceValue,
            'regex',
            ['all']
        );

        $this->assertEquals(
            \Omeka\Entity\Job::STATUS_COMPLETED,
            $job->getStatus(),
            'Regex job should complete successfully'
        );

        // Verify first and last items.
        $firstItem = $this->refreshItem($itemIds[0]);
        $lastItem = $this->refreshItem($itemIds[$itemCount - 1]);

        $this->assertStringContainsString(
            $replaceValue,
            $this->getItemValue($firstItem, 'dcterms:description'),
            'First item should have regex replacement'
        );
        $this->assertStringContainsString(
            $replaceValue,
            $this->getItemValue($lastItem, 'dcterms:description'),
            'Last item should have regex replacement'
        );
    }
}
