<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the processes that update the values of each resource.
 */
class ValueProcessTest extends AbstractHttpControllerTestCase
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

    public function testExplodeProcessesAllResources(): void
    {
        $first = $this->createItem(['dcterms:subject' => [['@value' => "a\nb"]]]);
        $second = $this->createItem(['dcterms:subject' => [['@value' => "c\nd"]]]);

        $this->batch([$first->id(), $second->id()], [
            'explode' => ['properties' => ['dcterms:subject'], 'separator' => '\n'],
        ]);

        $this->assertSame(['a', 'b'], $this->strings($first->id(), 'dcterms:subject'));
        $this->assertSame(['c', 'd'], $this->strings($second->id(), 'dcterms:subject'));
    }

    public function testCopyMaxValues(): void
    {
        $item = $this->threeSubjects();

        $this->batch([$item->id()], ['copy' => $this->copyParams(['max_values' => 1])]);

        $this->assertSame(['x'], $this->strings($item->id(), 'dcterms:description'));
        $this->assertSame(['x', 'y', 'z'], $this->strings($item->id(), 'dcterms:subject'));
    }

    public function testDisplaceMaxValues(): void
    {
        $item = $this->threeSubjects();

        $this->batch([$item->id()], ['displace' => $this->copyParams(['max_values' => 2])]);

        $this->assertSame(['x', 'y'], $this->strings($item->id(), 'dcterms:description'));
        $this->assertSame(['z'], $this->strings($item->id(), 'dcterms:subject'));
    }

    public function testDisplaceToInvalidDataTypeKeepsValue(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Kept title']],
            'dcterms:subject' => [['@value' => 'Not a uri']],
        ]);

        $this->batch([$item->id()], ['displace' => $this->copyParams(['datatype' => 'uri'])]);

        $this->assertSame(['Not a uri'], $this->strings($item->id(), 'dcterms:subject'));
        $this->assertSame([], $this->strings($item->id(), 'dcterms:description'));
    }

    public function testRemoveMaxValues(): void
    {
        $item = $this->threeSubjects();

        $this->batch([$item->id()], ['remove' => $this->removeParams(['max_values' => 1])]);

        $this->assertSame(['y', 'z'], $this->strings($item->id(), 'dcterms:subject'));
    }

    public function testRemoveContainsKeepsLinkedResources(): void
    {
        $linked = $this->createItem(['dcterms:title' => [['@value' => 'Linked foo']]]);
        $item = $this->createItem([
            'dcterms:subject' => [
                ['@value' => 'with foo'],
                ['@value' => 'without'],
                ['type' => 'resource:item', 'value_resource_id' => $linked->id()],
            ],
        ]);

        $this->batch([$item->id()], ['remove' => $this->removeParams(['contains' => 'foo'])]);

        $values = $this->refreshItem($item->id())->value('dcterms:subject', ['all' => true]);
        $this->assertCount(2, $values);
        $this->assertSame('without', (string) $values[0]);
        $this->assertSame($linked->id(), $values[1]->valueResource()->id());
    }

    public function testConvertKeepsVisibilityAnnotationAndLanguage(): void
    {
        $item = $this->api()->create('items', [
            'dcterms:subject' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:subject'),
                '@value' => 'https://example.org/a',
                '@language' => 'fr',
                'is_public' => false,
                '@annotation' => [
                    'dcterms:description' => [[
                        'type' => 'literal',
                        'property_id' => $this->propertyId('dcterms:description'),
                        '@value' => 'Note',
                    ]],
                ],
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $this->batch([$item->id()], ['convert' => $this->convertParams('literal', 'uri')]);

        $value = $this->refreshItem($item->id())->value('dcterms:subject', ['all' => true])[0];
        $this->assertSame('uri', $value->type());
        $this->assertSame('https://example.org/a', $value->uri());
        $this->assertSame('fr', $value->lang());
        $this->assertFalse($value->isPublic());
        $this->assertNotNull($value->valueAnnotation());
        $this->assertSame('Note', (string) $value->valueAnnotation()->value('dcterms:description'));
    }

    public function testConvertUriToLiteralKeepsLanguage(): void
    {
        $item = $this->api()->create('items', [
            'dcterms:subject' => [[
                'type' => 'uri',
                'property_id' => $this->propertyId('dcterms:subject'),
                '@id' => 'https://example.org/a',
                'o:label' => 'Label',
                'o:lang' => 'fr',
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $this->batch([$item->id()], ['convert' => $this->convertParams('uri', 'literal', ['literal_value' => 'label'])]);

        $value = $this->refreshItem($item->id())->value('dcterms:subject', ['all' => true])[0];
        $this->assertSame('literal', $value->type());
        $this->assertSame('Label', (string) $value);
        $this->assertSame('fr', $value->lang());
    }

    public function testConvertContainsAppliesToAllDataTypes(): void
    {
        $matching = $this->createItem(['dcterms:title' => [['@value' => 'Title match']]]);
        $other = $this->createItem(['dcterms:title' => [['@value' => 'Title other']]]);
        $item = $this->createItem([
            'dcterms:subject' => [
                ['type' => 'resource:item', 'value_resource_id' => $matching->id()],
                ['type' => 'resource:item', 'value_resource_id' => $other->id()],
            ],
        ]);

        $this->batch([$item->id()], ['convert' => $this->convertParams('resource:item', 'literal', ['contains' => 'match'])]);

        $values = $this->refreshItem($item->id())->value('dcterms:subject', ['all' => true]);
        $this->assertSame('literal', $values[0]->type());
        $this->assertSame('Title match', (string) $values[0]);
        $this->assertSame('resource:item', $values[1]->type());
    }

    public function testMergeKeepsLanguageAndAnnotationOfLabel(): void
    {
        $subject = $this->propertyId('dcterms:subject');
        $item = $this->api()->create('items', [
            'dcterms:subject' => [
                [
                    'type' => 'literal',
                    'property_id' => $subject,
                    '@value' => 'Label',
                    '@language' => 'fr',
                    '@annotation' => [
                        'dcterms:description' => [[
                            'type' => 'literal',
                            'property_id' => $this->propertyId('dcterms:description'),
                            '@value' => 'Note',
                        ]],
                    ],
                ],
                ['type' => 'literal', 'property_id' => $subject, '@value' => 'https://example.org/a'],
            ],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $this->batch([$item->id()], ['merge' => ['properties' => ['dcterms:subject']]]);

        $values = $this->refreshItem($item->id())->value('dcterms:subject', ['all' => true]);
        $this->assertCount(1, $values);
        $this->assertSame('https://example.org/a', $values[0]->uri());
        $this->assertSame('Label', $values[0]->value());
        $this->assertSame('fr', $values[0]->lang());
        $this->assertNotNull($values[0]->valueAnnotation());
    }

    public function testMergeSkipsLinkedResources(): void
    {
        $linked = $this->createItem(['dcterms:title' => [['@value' => 'Linked']]]);
        $item = $this->createItem([
            'dcterms:subject' => [
                ['type' => 'resource:item', 'value_resource_id' => $linked->id()],
                ['@value' => 'https://example.org/a'],
            ],
        ]);

        $this->batch([$item->id()], ['merge' => ['properties' => ['dcterms:subject']]]);

        $values = $this->refreshItem($item->id())->value('dcterms:subject', ['all' => true]);
        $this->assertCount(2, $values);
        $this->assertSame($linked->id(), $values[0]->valueResource()->id());
    }

    public function testReplaceWithInvalidRegexAppliesOtherOptions(): void
    {
        $item = $this->createItem(['dcterms:subject' => [['@value' => 'Value']]]);

        $this->batch([$item->id()], ['replace' => [
            'value_part' => 'value',
            'mode' => 'regex',
            'from' => '/invalid(',
            'to' => 'x',
            'prepend' => 'Before ',
            'append' => '',
            'language' => '',
            'language_clear' => false,
            'properties' => ['dcterms:subject'],
        ]]);

        $this->assertSame(['Before Value'], $this->strings($item->id(), 'dcterms:subject'));
    }

    public function testReplaceUriLabelWithoutLabel(): void
    {
        $item = $this->createItem([
            'dcterms:subject' => [['type' => 'uri', '@id' => 'https://example.org/a']],
        ]);

        $this->batch([$item->id()], ['replace' => [
            'value_part' => 'uri_label',
            'mode' => 'raw',
            'from' => 'a',
            'to' => 'b',
            'prepend' => '',
            'append' => '',
            'language' => '',
            'language_clear' => false,
            'properties' => ['dcterms:subject'],
        ]]);

        $this->assertSame('https://example.org/a', $this->refreshItem($item->id())->value('dcterms:subject')->uri());
    }

    public function testRemoveAfterDisplaceOfSameProperty(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Kept']],
            'dcterms:subject' => [['@value' => 'x'], ['@value' => 'y']],
        ]);

        $this->batch([$item->id()], [
            'displace' => $this->copyParams(),
            'remove' => $this->removeParams(['max_values' => -1]),
        ]);

        $this->assertSame([], $this->strings($item->id(), 'dcterms:subject'));
        $this->assertSame(['x', 'y'], $this->strings($item->id(), 'dcterms:description'));
    }

    public function testCleanEmptyValuesRemovesRowsAndAnnotations(): void
    {
        $item = $this->api()->create('items', [
            'dcterms:title' => [['type' => 'literal', 'property_id' => $this->propertyId('dcterms:title'), '@value' => 'Kept']],
            'dcterms:subject' => [[
                'type' => 'literal',
                'property_id' => $this->propertyId('dcterms:subject'),
                '@value' => 'Emptied',
                '@annotation' => [
                    'dcterms:description' => [['type' => 'literal', 'property_id' => $this->propertyId('dcterms:description'), '@value' => 'Note']],
                ],
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];
        $connection = $this->getConnection();
        $subject = $this->propertyId('dcterms:subject');
        $annotationId = (int) $connection->fetchOne('SELECT `value_annotation_id` FROM `value` WHERE `resource_id` = ? AND `property_id` = ?', [$item->id(), $subject]);
        $connection->executeStatement('UPDATE `value` SET `value` = "" WHERE `resource_id` = ? AND `property_id` = ?', [$item->id(), $subject]);

        $this->getServiceLocator()->get('ControllerPluginManager')->get('cleanEmptyValues')([$item->id()]);

        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `value` WHERE `resource_id` = ? AND `property_id` = ?', [$item->id(), $subject]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `resource` WHERE `id` = ?', [$annotationId]));
    }

    public function testBatchEditAssetsAreNotLoadedForUsers(): void
    {
        $services = $this->getServiceLocator();
        $services->get('Application')->getMvcEvent()
            ->setRouteMatch(new \Laminas\Router\Http\RouteMatch(['controller' => 'Omeka\Controller\Admin\User']));
        $view = $services->get('ViewRenderer');
        $view->headScript()->exchangeArray([]);

        $module = new \BulkEdit\Module();
        $module->setServiceLocator($services);
        $module->viewBatchEditBefore(new \Laminas\EventManager\Event('view.batch_edit.before', $view));

        $this->assertStringNotContainsString('bulk-edit.js', (string) $view->headScript());
    }

    public function testReplaceRemoveWithAppendRemovesValue(): void
    {
        $item = $this->createItem([
            'dcterms:title' => [['@value' => 'Kept']],
            'dcterms:subject' => [['@value' => 'Removed']],
        ]);

        $this->batch([$item->id()], ['replace' => [
            'value_part' => 'value',
            'mode' => 'remove',
            'from' => '',
            'to' => '',
            'prepend' => '',
            'append' => ' suffix',
            'language' => '',
            'language_clear' => false,
            'properties' => ['dcterms:subject'],
        ]]);

        $this->assertSame([], $this->strings($item->id(), 'dcterms:subject'));
    }

    /**
     * @dataProvider formFieldsetsProvider
     */
    public function testFormProposesOnlyManagedProcesses(string $resourceType, bool $mediaRemove, bool $mediaHtml): void
    {
        $fieldset = $this->getServiceLocator()->get('FormElementManager')
            ->get(\BulkEdit\Form\BulkEditFieldset::class, ['resource_type' => $resourceType]);

        $this->assertSame($mediaRemove, $fieldset->has('media_remove'));
        $this->assertSame($mediaHtml, $fieldset->has('media_html'));
        $this->assertTrue($fieldset->has('thumbnails'));
    }

    public function formFieldsetsProvider(): array
    {
        return [
            'items' => ['items', true, true],
            'media' => ['media', false, true],
            'item sets' => ['item_sets', false, false],
            'digital objects' => ['digital_objects', false, false],
        ];
    }

    protected function batch(array $ids, array $bulkedit): void
    {
        $this->api()->batchUpdate('items', $ids, ['bulkedit' => $bulkedit], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);
    }

    protected function threeSubjects()
    {
        return $this->createItem([
            'dcterms:subject' => [['@value' => 'x'], ['@value' => 'y'], ['@value' => 'z']],
        ]);
    }

    protected function strings(int $itemId, string $term): array
    {
        $values = $this->refreshItem($itemId)->value($term, ['all' => true]);
        return array_map('strval', $values);
    }

    protected function propertyId(string $term): int
    {
        return $this->getServiceLocator()->get('Common\EasyMeta')->propertyId($term);
    }

    protected function copyParams(array $params = []): array
    {
        return $params + [
            'from' => ['dcterms:subject'],
            'to' => 'dcterms:description',
            'datatypes' => [],
            'languages' => [],
            'visibility' => '',
            'contains' => '',
            'match' => '',
            'datatype' => '',
        ];
    }

    protected function removeParams(array $params = []): array
    {
        return $params + [
            'properties' => ['dcterms:subject'],
            'datatypes' => [],
            'languages' => [],
            'visibility' => '',
            'equal' => '',
            'contains' => '',
            'match' => '',
        ];
    }

    protected function convertParams(string $from, string $to, array $params = []): array
    {
        return $params + [
            'from' => $from,
            'to' => $to,
            'properties' => ['dcterms:subject'],
            'literal_value' => 'label_or_uri',
            'literal_extract_html_text' => false,
            'literal_html_only_tagged_string' => false,
            'resource_value_preprocess' => '',
            'resource_properties' => [],
            'uri_extract_label' => false,
            'uri_label' => '',
            'uri_language' => '',
            'uri_base_site' => '',
            'contains' => '',
        ];
    }
}
