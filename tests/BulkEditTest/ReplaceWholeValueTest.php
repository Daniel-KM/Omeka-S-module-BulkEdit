<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the replacement of the whole value and the placeholders.
 *
 * The mode "whole" replaces the full value, uri or label by the string "to",
 * that may contain placeholders managed by the module Common, so a uri can be
 * rebuilt from the resource itself.
 *
 * @link https://github.com/Daniel-KM/Omeka-S-module-BulkEdit/issues/7
 */
class ReplaceWholeValueTest extends AbstractHttpControllerTestCase
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

    protected function createItemWithUri(string $uri, string $label = 'Handle')
    {
        return $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Item with uri'],
            ],
            'dcterms:identifier' => [
                ['type' => 'uri', '@id' => $uri, 'o:label' => $label],
            ],
        ]);
    }

    public function testReplaceWholeUriWithResourceId(): void
    {
        $item = $this->createItemWithUri('https://dspace.example.org/handle/123');

        $job = $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'uri',
            'mode' => 'whole',
            'to' => 'https://example.org/item/{resource_id}',
            'properties' => ['dcterms:identifier'],
        ]);

        $this->assertEquals(\Omeka\Entity\Job::STATUS_COMPLETED, $job->getStatus());

        $item = $this->refreshItem($item->id());
        $this->assertSame(
            'https://example.org/item/' . $item->id(),
            $this->getItemValueUri($item, 'dcterms:identifier')
        );
    }

    public function testReplaceWholeUriWithoutPlaceholder(): void
    {
        $item = $this->createItemWithUri('https://dspace.example.org/handle/456');

        $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'uri',
            'mode' => 'whole',
            'to' => 'https://example.org/static',
            'properties' => ['dcterms:identifier'],
        ]);

        $item = $this->refreshItem($item->id());
        $this->assertSame(
            'https://example.org/static',
            $this->getItemValueUri($item, 'dcterms:identifier')
        );
    }

    public function testReplaceWholeUriKeepsLabel(): void
    {
        $item = $this->createItemWithUri('https://dspace.example.org/handle/789', 'My label');

        $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'uri',
            'mode' => 'whole',
            'to' => 'https://example.org/item/{resource_id}',
            'properties' => ['dcterms:identifier'],
        ]);

        $item = $this->refreshItem($item->id());
        $values = $item->value('dcterms:identifier', ['all' => true]);
        $this->assertSame('My label', $values[0]->value());
    }

    public function testReplaceWholeValueWithResourceTitle(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'The title'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'Some old description'],
            ],
        ]);

        $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'value',
            'mode' => 'whole',
            'to' => 'Item: {resource_title}',
            'properties' => ['dcterms:description'],
        ]);

        $item = $this->refreshItem($item->id());
        $this->assertSame(
            'Item: The title',
            $this->getItemValue($item, 'dcterms:description')
        );
    }

    public function testPlaceholderInPrependAndAppend(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [
                ['type' => 'literal', '@value' => 'Prepend append'],
            ],
            'dcterms:description' => [
                ['type' => 'literal', '@value' => 'core'],
            ],
        ]);

        $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'value',
            'mode' => 'raw',
            'prepend' => '{resource_id}-',
            'append' => '-{resource_id}',
            'properties' => ['dcterms:description'],
        ]);

        $id = $item->id();
        $item = $this->refreshItem($id);
        $this->assertSame(
            $id . '-core-' . $id,
            $this->getItemValue($item, 'dcterms:description')
        );
    }

    public function testUnknownPlaceholderIsKept(): void
    {
        $item = $this->createItemWithUri('https://dspace.example.org/handle/321');

        $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'uri',
            'mode' => 'whole',
            'to' => 'https://example.org/item/{unknown_placeholder}x',
            'properties' => ['dcterms:identifier'],
        ]);

        $item = $this->refreshItem($item->id());
        $this->assertSame(
            'https://example.org/item/{unknown_placeholder}x',
            $this->getItemValueUri($item, 'dcterms:identifier')
        );
    }

    public function testWholeValueEmptyRemovesValue(): void
    {
        $item = $this->createItemWithUri('https://dspace.example.org/handle/654');

        $this->runBatchReplaceParams(['id' => [$item->id()]], [
            'value_part' => 'uri',
            'mode' => 'whole',
            'to' => '',
            'properties' => ['dcterms:identifier'],
        ]);

        $item = $this->refreshItem($item->id());
        $this->assertEmpty($item->value('dcterms:identifier', ['all' => true]));
    }
}
