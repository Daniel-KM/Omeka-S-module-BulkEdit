<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Job\BatchUpdate;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the processes run after the batch update (direct sql or entities).
 */
class PostProcessTest extends AbstractHttpControllerTestCase
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
     * The batch update of selected resources is synchronous.
     */
    public function testPostProcessRunsOnBatchUpdate(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'Post sync']]]);

        $this->api()->batchUpdate('items', [$item->id()], $this->cleanLanguageCodesData(), [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);

        $this->assertSame(['fr'], $this->languages($item->id(), 'dcterms:title'));
    }

    /**
     * The batch update of all resources is done via a job.
     */
    public function testPostProcessRunsOnBatchUpdateJob(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'Post job']]]);

        $this->runJob(BatchUpdate::class, [
            'resource' => 'items',
            'query' => ['id' => [$item->id()]],
            'data' => $this->cleanLanguageCodesData(),
            'data_remove' => [],
            'data_append' => [],
        ]);

        $this->assertSame(['fr'], $this->languages($item->id(), 'dcterms:title'));
    }

    public function testCleanLanguageCodesIsLimitedToResourcesAndProperties(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Target']],
            'dcterms:subject' => [['@value' => 'Other property']],
        ]);
        $other = $this->createItem(['dcterms:title' => [['@value' => 'Other resource']]]);
        $this->getConnection()->executeStatement(
            'UPDATE `value` SET `lang` = "" WHERE `resource_id` IN (?, ?)',
            [$item->id(), $other->id()]
        );

        $count = $this->plugin('cleanLanguageCodes')([$item->id()], '', 'fr', ['dcterms:title']);

        $this->assertSame(1, $count);
        $this->assertSame(['fr'], $this->languages($item->id(), 'dcterms:title'));
        $this->assertSame([''], $this->languages($item->id(), 'dcterms:subject'));
        $this->assertSame([''], $this->languages($other->id(), 'dcterms:title'));
    }

    public function testCleanLanguageCodesWithUnknownPropertyUpdatesNothing(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'Unknown property']]]);

        $count = $this->plugin('cleanLanguageCodes')([$item->id()], '', 'fr', ['foo:bar']);

        $this->assertSame(0, $count);
        $this->assertSame([null], $this->languages($item->id(), 'dcterms:title'));
    }

    public function testSpecifyDataTypeUsesLinkedResource(): void
    {
        $itemSet = $this->api()->create('item_sets', [])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];
        $linked = $this->createItem(['dcterms:title' => [['@value' => 'Linked item']]]);
        $item = $this->createItem([
            'dcterms:isPartOf' => [['type' => 'resource:itemset', 'value_resource_id' => $itemSet->id()]],
            'dcterms:relation' => [['type' => 'resource:item', 'value_resource_id' => $linked->id()]],
        ]);
        $connection = $this->getConnection();
        $connection->executeStatement('UPDATE `value` SET `type` = "resource" WHERE `resource_id` = ?', [$item->id()]);

        $this->plugin('specifyDataTypeResources')([$item->id()]);

        $types = $connection->fetchAllKeyValue(
            'SELECT `value_resource_id`, `type` FROM `value` WHERE `resource_id` = ?',
            [$item->id()]
        );
        $this->assertSame('resource:itemset', $types[$itemSet->id()]);
        $this->assertSame('resource:item', $types[$linked->id()]);
    }

    public function testTrimUpdatesTitleAndFulltext(): void
    {
        $item = $this->createItem(['dcterms:title' => [['@value' => 'Spaced title']]]);
        $connection = $this->getConnection();
        $connection->executeStatement('UPDATE `value` SET `value` = "  Spaced title  " WHERE `resource_id` = ?', [$item->id()]);
        $connection->executeStatement('UPDATE `resource` SET `title` = "  Spaced title  " WHERE `id` = ?', [$item->id()]);
        $connection->executeStatement('UPDATE `fulltext_search` SET `title` = "old" WHERE `id` = ? AND `resource` = "items"', [$item->id()]);

        $this->api()->batchUpdate('items', [$item->id()], ['bulkedit' => ['cleaning' => ['trim_values' => '1']]], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);

        $this->assertSame('Spaced title', $connection->fetchOne('SELECT `title` FROM `resource` WHERE `id` = ?', [$item->id()]));
        $this->assertSame('Spaced title', $connection->fetchOne('SELECT `title` FROM `fulltext_search` WHERE `id` = ? AND `resource` = "items"', [$item->id()]));
    }

    public function testMediaHtmlUpdatesFulltextOfMedia(): void
    {
        $item = $this->api()->create('items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Ancient word</p>']],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];
        $mediaId = $item->media()[0]->id();

        $this->api()->batchUpdate('items', [$item->id()], [
            'bulkedit' => ['media_html' => ['mode' => 'raw', 'from' => 'Ancient', 'to' => 'Modern']],
        ], ['continueOnError' => true, 'collectionAction' => 'replace']);

        $text = $this->getConnection()->fetchOne('SELECT `text` FROM `fulltext_search` WHERE `id` = ? AND `resource` = "media"', [$mediaId]);
        $this->assertStringContainsString('Modern', (string) $text);
        $this->assertStringNotContainsString('Ancient', (string) $text);
    }

    public function testExplodeItemByMedia(): void
    {
        $title = 'Explode ' . uniqid();
        $item = $this->api()->create('items', [
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => $title]],
            'o:media' => [
                ['o:ingester' => 'html', 'html' => '<p>First</p>'],
                ['o:ingester' => 'html', 'html' => '<p>Second</p>'],
            ],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $this->api()->batchUpdate('items', [$item->id()], ['bulkedit' => ['explode_item' => ['mode' => 'append']]], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);

        $this->getEntityManager()->clear();
        $this->loginAdmin();
        $itemIds = $this->api()->search('items', ['property' => [['property' => 'dcterms:title', 'type' => 'eq', 'text' => $title]]], ['returnScalar' => 'id'])->getContent();
        foreach ($itemIds as $itemId) {
            if ((int) $itemId !== $item->id()) {
                $this->createdResources[] = ['type' => 'items', 'id' => (int) $itemId];
            }
        }
        $this->assertCount(2, $itemIds, 'The item should be exploded into one item by media.');
        foreach ($itemIds as $itemId) {
            $this->assertCount(1, $this->api()->read('items', $itemId)->getContent()->media());
        }
    }

    public function testExplodeItemByMediaChangesNothingOnFailure(): void
    {
        $title = 'Explode failure ' . uniqid();
        $item = $this->api()->create('items', [
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => $title]],
            'o:media' => [
                ['o:ingester' => 'html', 'html' => '<p>First</p>'],
                ['o:ingester' => 'html', 'html' => '<p>Second</p>'],
                ['o:ingester' => 'html', 'html' => '<p>Third</p>'],
            ],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        // The creation of the second new item fails.
        $creations = 0;
        $this->getServiceLocator()->get('SharedEventManager')->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.create.pre',
            function () use (&$creations): void {
                if (++$creations === 2) {
                    throw new \RuntimeException('Failure.');
                }
            }
        );

        $this->api()->batchUpdate('items', [$item->id()], ['bulkedit' => ['explode_item' => ['mode' => 'append']]], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);

        $this->assertSame(2, $creations);
        $itemIds = $this->api()->search('items', ['property' => [['property' => 'dcterms:title', 'type' => 'eq', 'text' => $title]]], ['returnScalar' => 'id'])->getContent();
        $this->assertCount(1, $itemIds, 'The new items should be removed.');
        $this->assertCount(3, $this->refreshItem($item->id())->media());
    }

    protected function cleanLanguageCodesData(): array
    {
        return [
            'bulkedit' => [
                'cleaning' => [
                    'clean_language_codes' => '1',
                    'clean_language_codes_from' => '',
                    'clean_language_codes_to' => 'fr',
                    'clean_language_codes_properties' => ['dcterms:title'],
                ],
            ],
        ];
    }

    protected function languages(int $resourceId, string $term): array
    {
        $propertyId = $this->getServiceLocator()->get('Common\EasyMeta')->propertyId($term);
        return $this->getConnection()->fetchFirstColumn(
            'SELECT `lang` FROM `value` WHERE `resource_id` = ? AND `property_id` = ? ORDER BY `id`',
            [$resourceId, $propertyId]
        );
    }

    protected function plugin(string $name)
    {
        return $this->getServiceLocator()->get('ControllerPluginManager')->get($name);
    }
}
