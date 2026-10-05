<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Job\BatchUpdate;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the cleaning, the combinations of processes and the resource types.
 */
class CleaningMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider cleaningProvider
     */
    public function testCleaning(array $cleaning, array $rows, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept']]);
        $connection = $this->getConnection();
        $subject = $this->propertyId('dcterms:subject');
        foreach ($rows as $row) {
            $connection->executeStatement(
                'INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value`, `uri`, `lang`, `is_public`) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$item->id(), $subject, $row['type'] ?? 'literal', $row['value'] ?? null, $row['uri'] ?? null, $row['lang'] ?? null, $row['is_public'] ?? 1]
            );
        }

        $this->batch([$item->id()], ['cleaning' => $cleaning]);

        $actual = $connection->fetchAllAssociative(
            'SELECT `value`, `uri`, `lang` FROM `value` WHERE `resource_id` = ? AND `property_id` = ? ORDER BY `id`',
            [$item->id(), $subject]
        );
        $this->assertSame($expected, $actual);
    }

    public function cleaningProvider(): array
    {
        $row = fn ($value, $uri = null, $lang = null) => ['value' => $value, 'uri' => $uri, 'lang' => $lang];
        $codes = fn ($from, $to, $properties = ['dcterms:subject']) => [
            'clean_language_codes' => '1',
            'clean_language_codes_from' => $from,
            'clean_language_codes_to' => $to,
            'clean_language_codes_properties' => $properties,
        ];
        return [
            'trim value' => [['trim_values' => '1'], [['value' => '  a  ']], [$row('a')]],
            'trim new lines and tabs' => [['trim_values' => '1'], [['value' => "\n\ta\t\n"]], [$row('a')]],
            'trim uri and language' => [['trim_values' => '1'], [['uri' => ' https://example.org/a ', 'type' => 'uri', 'lang' => ' fr ']], [$row(null, 'https://example.org/a', 'fr')]],
            'trim removes empty value' => [['trim_values' => '1'], [['value' => '   '], ['value' => 'b']], [$row('b')]],
            'trim removes empty value with language' => [['trim_values' => '1'], [['value' => '  ', 'lang' => 'fr']], []],
            'trim keeps inner spaces' => [['trim_values' => '1'], [['value' => 'a  b']], [$row('a  b')]],
            'empty string to null, row removed' => [['clean_empty_values' => '1'], [['value' => ''], ['value' => 'b']], [$row('b')]],
            'empty uri to null with value kept' => [['clean_empty_values' => '1'], [['value' => 'a', 'uri' => '', 'type' => 'uri']], [$row('a')]],
            'empty languages' => [['clean_languages' => '1'], [['value' => 'a', 'lang' => ''], ['value' => 'b', 'lang' => 'fr']], [$row('a'), $row('b', null, 'fr')]],
            'codes from empty' => [$codes('', 'fr'), [['value' => 'a'], ['value' => 'b', 'lang' => ''], ['value' => 'c', 'lang' => 'en']], [$row('a', null, 'fr'), $row('b', null, 'fr'), $row('c', null, 'en')]],
            'codes from a code' => [$codes('en', 'fr'), [['value' => 'a', 'lang' => 'en'], ['value' => 'b']], [$row('a', null, 'fr'), $row('b')]],
            'codes to empty' => [$codes('en', ''), [['value' => 'a', 'lang' => 'en'], ['value' => 'b', 'lang' => 'fr']], [$row('a'), $row('b', null, 'fr')]],
            'codes all properties' => [$codes('en', 'fr', ['all']), [['value' => 'a', 'lang' => 'en']], [$row('a', null, 'fr')]],
            'codes other property' => [$codes('en', 'fr', ['dcterms:title']), [['value' => 'a', 'lang' => 'en']], [$row('a', null, 'en')]],
            'codes unknown property' => [$codes('en', 'fr', ['foo:bar']), [['value' => 'a', 'lang' => 'en']], [$row('a', null, 'en')]],
            'codes without property' => [$codes('en', 'fr', []), [['value' => 'a', 'lang' => 'en']], [$row('a', null, 'en')]],
            'deduplicate' => [['deduplicate_values' => '1'], [['value' => 'a'], ['value' => 'a'], ['value' => 'b']], [$row('a'), $row('b')]],
            'deduplicate keeps languages' => [['deduplicate_values' => '1'], [['value' => 'a', 'lang' => 'fr'], ['value' => 'a', 'lang' => 'en']], [$row('a', null, 'fr'), $row('a', null, 'en')]],
            'deduplicate keeps visibility' => [['deduplicate_values' => '1'], [['value' => 'a', 'is_public' => 0], ['value' => 'a']], [$row('a'), $row('a')]],
            'trim then deduplicate' => [['trim_values' => '1', 'deduplicate_values' => '1'], [['value' => ' a'], ['value' => 'a ']], [$row('a')]],
            'all cleanings' => [
                ['trim_values' => '1', 'clean_empty_values' => '1', 'clean_languages' => '1', 'deduplicate_values' => '1'] + $codes('en', 'fr'),
                [['value' => ' a ', 'lang' => 'en'], ['value' => 'a', 'lang' => 'fr'], ['value' => '  '], ['value' => 'b', 'lang' => '']],
                [$row('a', null, 'fr'), $row('b')],
            ],
            'nothing selected' => [['trim_values' => '0'], [['value' => ' a ']], [$row(' a ')]],
        ];
    }

    public function testSpecifyDataTypes(): void
    {
        $itemSet = $this->createResource([], 'item_sets');
        $linked = $this->createResource(['dcterms:title' => ['Linked']]);
        $media = $this->createResource(['dcterms:title' => ['Kept']], 'items', ['o:media' => [['o:ingester' => 'html', 'html' => '<p>a</p>']]])->media()[0];
        $item = $this->createResource(['dcterms:title' => ['Kept']]);
        $connection = $this->getConnection();
        $subject = $this->propertyId('dcterms:subject');
        foreach ([$itemSet->id(), $linked->id(), $media->id()] as $id) {
            $connection->executeStatement(
                'INSERT INTO `value` (`resource_id`, `property_id`, `type`, `value_resource_id`, `is_public`) VALUES (?, ?, "resource", ?, 1)',
                [$item->id(), $subject, $id]
            );
        }

        $this->batch([$item->id()], ['cleaning' => ['specify_datatypes' => '1']]);

        $types = $connection->fetchFirstColumn('SELECT `type` FROM `value` WHERE `resource_id` = ? AND `property_id` = ? ORDER BY `id`', [$item->id(), $subject]);
        $this->assertSame(['resource:itemset', 'resource:item', 'resource:media'], $types);
    }

    /**
     * @dataProvider combinationsProvider
     */
    public function testCombinations(array $bulkedit, array $expectedSubject, array $expectedDescription): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['Kept'],
            'dcterms:subject' => ['a,b', ['literal', 'c', 'en']],
        ]);

        $this->batch([$item->id()], $bulkedit);

        $this->assertSame($expectedSubject, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame($expectedDescription, $this->described($item->id(), 'dcterms:description'));
    }

    public function combinationsProvider(): array
    {
        $copy = ['from' => ['dcterms:subject'], 'to' => 'dcterms:description', 'datatypes' => [], 'languages' => [], 'visibility' => '', 'contains' => '', 'match' => '', 'datatype' => '', 'max_values' => ''];
        $replace = ['value_part' => 'value', 'mode' => 'raw', 'from' => 'a', 'to' => 'z', 'prepend' => '', 'append' => '', 'language' => '', 'language_clear' => false, 'properties' => ['dcterms:subject']];
        $explode = ['properties' => ['dcterms:subject'], 'separator' => ',', 'contains' => '', 'match' => '', 'max_values' => ''];
        $remove = ['properties' => ['dcterms:subject'], 'datatypes' => [], 'languages' => [], 'visibility' => '', 'equal' => 'b', 'contains' => '', 'match' => '', 'keep_max_values' => '', 'max_values' => ''];
        $create = ['properties' => 'dcterms:subject', 'datatype' => 'literal', 'value' => 'new', 'uri_label' => '', 'value_resource_id' => null, 'language' => '', 'visibility' => '1'];
        return [
            'replace then copy' => [['replace' => $replace, 'copy' => $copy], ['literal:z,b', 'literal:c@en'], ['literal:z,b', 'literal:c@en']],
            'copy then explode' => [['copy' => $copy, 'explode' => $explode], ['literal:a', 'literal:b', 'literal:c@en'], ['literal:a,b', 'literal:c@en']],
            'displace then explode has nothing' => [['displace' => $copy, 'explode' => $explode], [], ['literal:a,b', 'literal:c@en']],
            'explode then remove' => [['explode' => $explode, 'remove' => $remove], ['literal:a', 'literal:c@en'], []],
            'create then replace' => [['create_values' => $create, 'replace' => ['from' => 'new', 'to' => 'newer'] + $replace], ['literal:a,b', 'literal:c@en', 'literal:newer'], []],
            'create then remove all' => [['create_values' => $create, 'remove' => ['equal' => ''] + $remove], [], []],
            'displace then remove' => [['displace' => $copy, 'remove' => $remove], [], ['literal:a,b', 'literal:c@en']],
            'replace, explode, copy and remove' => [
                ['replace' => $replace, 'copy' => $copy, 'explode' => $explode, 'remove' => $remove],
                ['literal:z', 'literal:c@en'],
                ['literal:z,b', 'literal:c@en'],
            ],
        ];
    }

    /**
     * @dataProvider resourceTypesProvider
     */
    public function testValueProcessesOnResourceTypes(string $resourceName, array $data): void
    {
        $resource = $this->createResource(['dcterms:title' => ['Old title'], 'dcterms:subject' => ['a,b']], $resourceName, $data);
        $id = $resource->id();

        $this->batch([$id], [
            'replace' => ['value_part' => 'value', 'mode' => 'raw', 'from' => 'Old', 'to' => 'New', 'prepend' => '', 'append' => '', 'language' => '', 'language_clear' => false, 'properties' => ['dcterms:title']],
            'explode' => ['properties' => ['dcterms:subject'], 'separator' => ',', 'contains' => '', 'match' => '', 'max_values' => ''],
        ], $resourceName);

        $this->assertSame(['literal:New title'], $this->described($id, 'dcterms:title', $resourceName));
        $this->assertSame(['literal:a', 'literal:b'], $this->described($id, 'dcterms:subject', $resourceName));
    }

    public function resourceTypesProvider(): array
    {
        return [
            'items' => ['items', []],
            'item sets' => ['item_sets', []],
        ];
    }

    public function testValueProcessesOnMedia(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept']], 'items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>a</p>', 'dcterms:title' => [$this->valueData('dcterms:title', 'Old title')]]],
        ]);
        $mediaId = $item->media()[0]->id();

        $this->batch([$mediaId], ['replace' => ['value_part' => 'value', 'mode' => 'raw', 'from' => 'Old', 'to' => 'New', 'prepend' => '', 'append' => '', 'language' => '', 'language_clear' => false, 'properties' => ['dcterms:title']]], 'media');

        $this->assertSame(['literal:New title'], $this->described($mediaId, 'dcterms:title', 'media'));
    }

    public function testJobProcessesAllResources(): void
    {
        $first = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['a,b', ' c']]);
        $second = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['d,e']]);

        $this->runJob(BatchUpdate::class, [
            'resource' => 'items',
            'query' => ['id' => [$first->id(), $second->id()]],
            'data' => ['bulkedit' => [
                'explode' => ['properties' => ['dcterms:subject'], 'separator' => ',', 'contains' => '', 'match' => '', 'max_values' => ''],
                'cleaning' => ['trim_values' => '1'],
            ]],
            'data_remove' => [],
            'data_append' => [],
        ]);

        $this->assertSame(['literal:a', 'literal:b', 'literal:c'], $this->described($first->id(), 'dcterms:subject'));
        $this->assertSame(['literal:d', 'literal:e'], $this->described($second->id(), 'dcterms:subject'));
    }

    /**
     * The number of values changes, then a direct process flushes entities.
     */
    public function testValueProcessThenDirectProcesses(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['a,b']], 'items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Old</p>']],
        ]);
        $mediaId = $item->media()[0]->id();

        $this->batch([$item->id()], [
            'explode' => ['properties' => ['dcterms:subject'], 'separator' => ',', 'contains' => '', 'match' => '', 'max_values' => ''],
            'media_html' => ['mode' => 'raw', 'from' => 'Old', 'to' => 'New', 'prepend' => '', 'append' => ''],
            'cleaning' => ['clean_languages' => '1'],
        ]);

        $this->assertSame(['literal:a', 'literal:b'], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame('<p>New</p>', $this->reloaded($mediaId, 'media')->mediaData()['html']);
    }

    public function testSettingsAreNotKeptBetweenBatches(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['a']]);

        foreach (['b', 'c'] as $to) {
            $from = $to === 'b' ? 'a' : 'b';
            $this->batch([$item->id()], ['replace' => ['value_part' => 'value', 'mode' => 'raw', 'from' => $from, 'to' => $to, 'prepend' => '', 'append' => '', 'language' => '', 'language_clear' => false, 'properties' => ['dcterms:subject']]]);
        }

        $this->assertSame(['literal:c'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testBatchWithoutProcessKeepsValues(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['a', ['literal', 'b', 'fr', false]]]);

        $this->batch([$item->id()], []);

        $this->assertSame(['literal:a', 'literal:b@fr!'], $this->described($item->id(), 'dcterms:subject'));
    }
}
