<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the action "modify", that groups the processes updating existing values
 * with common properties and filters.
 */
class ModifyMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    public function testReplaceWithCommonFilters(): void
    {
        $item = $this->createResource(['dcterms:subject' => [
            ['literal', 'old fr', 'fr'],
            ['literal', 'old en', 'en'],
            ['uri', 'https://example.org/old', 'old'],
            'other',
        ]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'languages' => ['fr'],
            'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
        ]]);

        $this->assertSame(
            ['literal:new fr@fr', 'literal:old en@en', 'uri:https://example.org/old|old', 'literal:other'],
            $this->described($item->id(), 'dcterms:subject')
        );
    }

    public function testReplaceWithDataTypeAndContainsFilters(): void
    {
        $item = $this->createResource(['dcterms:subject' => [
            'alpha old',
            'beta old',
            ['uri', 'https://example.org/alpha', 'alpha old'],
        ]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['all'],
            'datatypes' => ['literal'],
            'contains' => 'alpha',
            'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
        ]]);

        $this->assertSame(
            ['literal:alpha new', 'literal:beta old', 'uri:https://example.org/alpha|alpha old'],
            $this->described($item->id(), 'dcterms:subject')
        );
    }

    public function testLanguageWithoutReplacement(): void
    {
        $item = $this->createResource([
            'dcterms:subject' => ['first', ['literal', 'second', 'en']],
            'dcterms:title' => ['Kept'],
        ]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'language' => ['enabled' => '1', 'action' => 'set', 'language' => 'fr'],
        ]]);

        $this->assertSame(['literal:first@fr', 'literal:second@fr'], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
    }

    /**
     * Unlike the replacement, the language is set whatever the part of value.
     */
    public function testLanguageOnAllFilteredValues(): void
    {
        $url = 'https://example.org/a';
        $item = $this->createResource(['dcterms:subject' => [
            'text',
            ['uri', $url, 'Label'],
            ['link', 'Linked'],
            ['literal', 'english', 'en'],
        ]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'contains' => 'e',
            'language' => ['enabled' => '1', 'action' => 'set', 'language' => 'fr'],
        ]]);

        $this->assertSame(
            ['literal:text@fr', "uri:$url|Label@fr", 'link:Linked', 'literal:english@fr'],
            $this->described($item->id(), 'dcterms:subject')
        );

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'datatypes' => ['uri'],
            'language' => ['enabled' => '1', 'action' => 'remove'],
        ]]);

        $this->assertSame(
            ['literal:text@fr', "uri:$url|Label", 'link:Linked', 'literal:english@fr'],
            $this->described($item->id(), 'dcterms:subject')
        );
    }

    public function testPrependWithoutMode(): void
    {
        $item = $this->createResource(['dcterms:subject' => ['value']]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'replace' => ['enabled' => '1', 'value_part' => 'value', 'prepend' => 'new '],
        ]]);

        $this->assertSame(['literal:new value'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testLanguageCodeChangeWithFilters(): void
    {
        $item = $this->createResource(['dcterms:subject' => [
            ['literal', 'one', 'fr'],
            ['literal', 'two', 'fr'],
            'three',
            ['uri', 'https://example.org/a', 'Label', 'fr'],
        ]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'contains' => 'o',
            'language' => ['enabled' => '1', 'action' => 'change', 'code_from' => 'fr', 'code_to' => 'fra'],
        ]]);
        $this->assertSame(
            ['literal:one@fra', 'literal:two@fra', 'literal:three', 'uri:https://example.org/a|Label@fra'],
            $this->described($item->id(), 'dcterms:subject')
        );

        // An empty code targets the values without language.
        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'language' => ['enabled' => '1', 'action' => 'change', 'code_from' => '', 'code_to' => 'en'],
        ]]);
        $this->assertSame(
            ['literal:one@fra', 'literal:two@fra', 'literal:three@en', 'uri:https://example.org/a|Label@fra'],
            $this->described($item->id(), 'dcterms:subject')
        );

        // An empty new code removes the language.
        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'datatypes' => ['literal'],
            'language' => ['enabled' => '1', 'action' => 'change', 'code_from' => 'fra', 'code_to' => ''],
        ]]);
        $this->assertSame(
            ['literal:one', 'literal:two', 'literal:three@en', 'uri:https://example.org/a|Label@fra'],
            $this->described($item->id(), 'dcterms:subject')
        );
    }

    /**
     * @dataProvider textActionsProvider
     */
    public function testTextActions(array $text, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['Old /path/File.TXT']]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'replace' => ['enabled' => '1', 'value_part' => 'value'] + $text,
        ]]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
    }

    public function textActionsProvider(): array
    {
        return [
            'search exact' => [['action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'], ['literal:Old /path/File.TXT']],
            'search case insensitive' => [['action' => 'search', 'match' => 'raw_i', 'from' => 'old', 'to' => 'New'], ['literal:New /path/File.TXT']],
            'search regex' => [['action' => 'search', 'match' => 'regex', 'from' => '~^Old ~', 'to' => ''], ['literal:/path/File.TXT']],
            'whole value' => [['action' => 'whole', 'to' => 'Whole', 'from' => 'ignored'], ['literal:Whole']],
            'file name with extension' => [['action' => 'filename', 'extension' => 'keep'], ['literal:File.TXT']],
            'file name without extension' => [['action' => 'filename', 'extension' => 'remove'], ['literal:File']],
            'remove' => [['action' => 'remove'], []],
            'no action nor added text' => [['action' => '', 'from' => 'Old', 'to' => 'New'], ['literal:Old /path/File.TXT']],
        ];
    }

    public function testDisabledOperationsAreSkipped(): void
    {
        $item = $this->createResource(['dcterms:subject' => [['literal', 'old', 'en']]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'replace' => ['enabled' => '', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
            'language' => ['action' => 'set', 'language' => 'fr'],
            'properties_visibility' => ['enabled' => '0', 'visibility' => '0'],
        ]]);

        $this->assertSame(['literal:old@en'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testExplodeAllPropertiesWithLanguageFilter(): void
    {
        $item = $this->createResource([
            'dcterms:subject' => [['literal', 'a; b', 'fr'], ['literal', 'c; d', 'en']],
            'dcterms:title' => [['literal', 'e; f', 'fr']],
        ]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['all'],
            'languages' => ['fr'],
            'explode' => ['enabled' => '1', 'separator' => ';'],
        ]]);

        $this->assertSame(['literal:a@fr', 'literal:b@fr', 'literal:c; d@en'], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:e@fr', 'literal:f@fr'], $this->described($item->id(), 'dcterms:title'));
    }

    public function testConvertWithLanguageFilter(): void
    {
        $url = 'https://example.org/a';
        $item = $this->createResource(['dcterms:subject' => [['literal', $url, 'fr'], ['literal', $url, 'en']]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'languages' => ['en'],
            'convert' => [
                'enabled' => '1',
                'from' => 'literal',
                'to' => 'uri',
                'literal_value' => '',
                'literal_extract_html_text' => '0',
                'literal_html_only_tagged_string' => '0',
                'resource_value_preprocess' => '',
                'resource_properties' => [],
                'uri_extract_label' => '0',
                'uri_label' => '',
                'uri_language' => '',
                'uri_base_site' => '',
                'contains' => '',
            ],
        ]]);

        $this->assertSame(["literal:$url@fr", "uri:$url@en"], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testVisibilityAndSortWithCommonProperties(): void
    {
        $item = $this->createResource([
            'dcterms:subject' => [['literal', 'en', 'en'], ['literal', 'fr', 'fr']],
            'dcterms:title' => ['Kept'],
        ]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'contains' => 'fr',
            'order_values' => ['enabled' => '1', 'languages' => ['fr']],
            'properties_visibility' => ['enabled' => '1', 'visibility' => '0'],
        ]]);

        // Only the filtered value is sorted, so nothing moves.
        $this->assertSame(['literal:en@en', 'literal:fr@fr!'], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
    }

    public function testSortOnlyFilteredValues(): void
    {
        $item = $this->createResource(['dcterms:subject' => [
            ['literal', 'a en', 'en'],
            ['uri', 'https://example.org/a'],
            ['literal', 'b fr', 'fr'],
            ['literal', 'c en', 'en'],
            ['literal', 'd fr', 'fr'],
        ]]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'datatypes' => ['literal'],
            'order_values' => ['enabled' => '1', 'languages' => ['fr']],
        ]]);

        $this->assertSame(
            ['literal:b fr@fr', 'uri:https://example.org/a', 'literal:d fr@fr', 'literal:a en@en', 'literal:c en@en'],
            $this->described($item->id(), 'dcterms:subject')
        );
    }

    /**
     * @dataProvider regexAndVisibilityFiltersProvider
     */
    public function testRegexAndVisibilityFilters(array $filters, array $operation, array $expected): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['Kept'],
            'dcterms:subject' => [
                ['literal', 'a1; a2', 'en', true],
                ['literal', 'b1; b2', 'en', false],
            ],
        ]);

        $this->batch([$item->id()], ['modify' => ['properties' => ['dcterms:subject']] + $filters + $operation]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
    }

    public function regexAndVisibilityFiltersProvider(): array
    {
        $replace = ['replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => '1', 'to' => '9']];
        return [
            'replace regex' => [['match' => '~^b~'], $replace, ['literal:a1; a2@en', 'literal:b9; b2@en!']],
            'replace private' => [['is_public' => '0'], $replace, ['literal:a1; a2@en', 'literal:b9; b2@en!']],
            'replace public' => [['is_public' => '1'], $replace, ['literal:a9; a2@en', 'literal:b1; b2@en!']],
            'language regex' => [['match' => '~^a~'], ['language' => ['enabled' => '1', 'action' => 'set', 'language' => 'fr']], ['literal:a1; a2@fr', 'literal:b1; b2@en!']],
            'explode private' => [['is_public' => '0'], ['explode' => ['enabled' => '1', 'separator' => ';']], ['literal:a1; a2@en', 'literal:b1@en!', 'literal:b2@en!']],
            'explode regex with max values' => [['match' => '~^a~'], ['explode' => ['enabled' => '1', 'separator' => ';', 'max_values' => '1']], ['literal:a1; a2@en', 'literal:b1; b2@en!']],
            'visibility regex' => [['match' => '~^a~'], ['properties_visibility' => ['enabled' => '1', 'visibility' => '0']], ['literal:a1; a2@en!', 'literal:b1; b2@en!']],
            'invalid regex matches nothing' => [['match' => '~['], $replace, ['literal:a1; a2@en', 'literal:b1; b2@en!']],
        ];
    }

    public function testPreviewDoesNotSave(): void
    {
        $unchanged = $this->createResource(['dcterms:subject' => ['other']]);
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['old', ['literal', 'old fr', 'fr']]]);

        $preview = $this->getServiceLocator()->get('ModuleManager')->getModule('BulkEdit')
            ->previewBulkEdit('items', [$unchanged->id(), $item->id()], ['modify' => [
                'properties' => ['dcterms:subject'],
                'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
            ]]);

        $this->assertSame(2, $preview['checked']);
        $this->assertCount(1, $preview['resources']);
        $this->assertSame($item->id(), $preview['resources'][0]['id']);
        $property = $preview['resources'][0]['properties'][0];
        $this->assertSame('dcterms:subject', $property['term']);
        $this->assertSame(['old', 'old fr'], array_column($property['before'], 'value'));
        $this->assertSame(['new', 'new fr'], array_column($property['after'], 'value'));
        $this->assertSame(['', 'fr'], array_column($property['after'], 'language'));
        $this->assertSame(['literal', 'literal'], array_column($property['after'], 'type'));
        $this->assertNotEmpty($property['after'][0]['type_label']);
        $this->assertSame([true, true], array_column($property['after'], 'is_public'));
        $this->assertSame(['literal:old', 'literal:old fr@fr'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testPreviewIsPaginated(): void
    {
        $ids = [];
        foreach (range(1, 5) as $index) {
            $ids[] = $this->createResource(['dcterms:subject' => [$index % 2 ? 'old' : 'other']])->id();
        }
        $module = $this->getServiceLocator()->get('ModuleManager')->getModule('BulkEdit');
        $bulkedit = ['modify' => [
            'properties' => ['dcterms:subject'],
            'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
        ]];

        // The resources 1, 3 and 5 are updated.
        $first = $module->previewBulkEdit('items', $ids, $bulkedit, 2);
        $this->assertSame([$ids[0], $ids[2]], array_column($first['resources'], 'id'));
        $this->assertSame(3, $first['next']);
        $this->assertSame(5, $first['total']);

        $second = $module->previewBulkEdit('items', $ids, $bulkedit, 2, $first['next']);
        $this->assertSame([$ids[4]], array_column($second['resources'], 'id'));
        $this->assertNull($second['next']);

        // The number of checked resources is limited by page.
        $limited = $module->previewBulkEdit('items', $ids, $bulkedit, 10, 0, 2);
        $this->assertSame([$ids[0]], array_column($limited['resources'], 'id'));
        $this->assertSame(2, $limited['next']);
    }

    /**
     * The preview runs all the actions on values in the real order.
     */
    public function testPreviewCombinesActions(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['old']]);

        $preview = $this->getServiceLocator()->get('ModuleManager')->getModule('BulkEdit')
            ->previewBulkEdit('items', [$item->id()], [
                'create_values' => ['values' => [
                    'dcterms:subject' => [['type' => 'literal', '@value' => 'created']],
                ]],
                'modify' => [
                    'properties' => ['dcterms:subject'],
                    'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
                ],
                'remove' => [
                    'properties' => ['dcterms:subject'],
                    'datatypes' => [],
                    'languages' => [],
                    'visibility' => '',
                    'equal' => 'new',
                    'contains' => '',
                    'match' => '',
                    'keep_max_values' => '',
                    'max_values' => '',
                ],
            ]);

        $property = $preview['resources'][0]['properties'][0];
        $this->assertSame(['old'], array_column($property['before'], 'value'));
        $this->assertSame(['created'], array_column($property['after'], 'value'));
        $this->assertSame(['literal:old'], $this->described($item->id(), 'dcterms:subject'));
    }

    /**
     * The core keeps the values of a partial update without any value passed.
     *
     * @dataProvider lastValuesProvider
     */
    public function testRemoveLastValues(array $bulkedit): void
    {
        $item = $this->createResource(['dcterms:subject' => ['old', 'other old']]);

        $this->batch([$item->id()], $bulkedit);

        $this->assertSame([], $this->described($item->id(), 'dcterms:subject'));
    }

    public function lastValuesProvider(): array
    {
        return [
            'modify' => [['modify' => [
                'properties' => ['dcterms:subject'],
                'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'remove'],
            ]]],
            'replace' => [['replace' => [
                'value_part' => 'value',
                'mode' => 'remove',
                'from' => '',
                'to' => '',
                'language' => '',
                'language_clear' => '0',
                'properties' => ['dcterms:subject'],
            ]]],
            'remove' => [['remove' => [
                'properties' => ['dcterms:subject'],
                'datatypes' => [],
                'languages' => [],
                'visibility' => '',
                'equal' => '',
                'contains' => '',
                'match' => '',
                'keep_max_values' => '',
                'max_values' => '',
            ]]],
        ];
    }

    public function testRemoveLastValuesKeepsOtherProperties(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => ['old']]);
        $other = $this->createResource(['dcterms:title' => ['Other kept']]);

        $this->batch([$item->id(), $other->id()], ['modify' => [
            'properties' => ['dcterms:subject'],
            'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'remove'],
        ]]);

        $this->assertSame([], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(['literal:Other kept'], $this->described($other->id(), 'dcterms:title'));
    }

    /**
     * The filters "contains" and "regex" check the visible strings of values.
     *
     * @dataProvider visibleStringsProvider
     */
    public function testContainsAndRegexCheckVisibleStrings(string $action, array $filters, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => [
            'Hugo text',
            ['uri', 'https://example.org/123', 'Hugo label'],
            ['link', 'Hugo linked'],
            'Zola',
        ]]);

        $actions = [
            'visibility' => ['modify' => ['properties' => ['dcterms:subject'], 'properties_visibility' => ['enabled' => '1', 'visibility' => '0']] + $filters],
            'remove' => ['remove' => [
                'properties' => ['dcterms:subject'],
                'datatypes' => [],
                'languages' => [],
                'visibility' => '',
                'contains' => $filters['contains'] ?? '',
                'contains_exact' => $filters['contains_exact'] ?? '',
                'match' => $filters['match'] ?? '',
                'search_in' => $filters['search_in'] ?? [],
                'keep_max_values' => '',
                'max_values' => '',
            ]],
            'copy' => ['copy' => [
                'from' => ['dcterms:subject'],
                'to' => 'dcterms:description',
                'datatypes' => [],
                'languages' => [],
                'visibility' => '',
                'contains' => $filters['contains'] ?? '',
                'contains_exact' => $filters['contains_exact'] ?? '',
                'match' => $filters['match'] ?? '',
                'search_in' => $filters['search_in'] ?? [],
                'datatype' => '',
                'max_values' => '',
            ]],
        ];
        $this->batch([$item->id()], $actions[$action]);

        $term = $action === 'copy' ? 'dcterms:description' : 'dcterms:subject';
        $this->assertSame($expected, $this->described($item->id(), $term));
    }

    public function visibleStringsProvider(): array
    {
        $uri = 'uri:https://example.org/123|Hugo label';
        $all = ['text', 'uri', 'label', 'title'];
        return [
            // By default, the linked resources are not checked.
            'visibility contains label' => ['visibility', ['contains' => 'Hugo'], ['literal:Hugo text!', "$uri!", 'link:Hugo linked', 'literal:Zola']],
            'visibility contains title' => ['visibility', ['contains' => 'Hugo', 'search_in' => $all], ['literal:Hugo text!', "$uri!", 'link:Hugo linked!', 'literal:Zola']],
            'visibility contains uri' => ['visibility', ['contains' => '/123'], ['literal:Hugo text', "$uri!", 'link:Hugo linked', 'literal:Zola']],
            'visibility contains uri only in labels' => ['visibility', ['contains' => '/123', 'search_in' => ['label']], ['literal:Hugo text', $uri, 'link:Hugo linked', 'literal:Zola']],
            'visibility regex label' => ['visibility', ['match' => '~label$~'], ['literal:Hugo text', "$uri!", 'link:Hugo linked', 'literal:Zola']],
            'remove contains title not checked' => ['remove', ['contains' => 'linked'], ['literal:Hugo text', $uri, 'link:Hugo linked', 'literal:Zola']],
            'remove contains title' => ['remove', ['contains' => 'linked', 'search_in' => ['title']], ['literal:Hugo text', $uri, 'literal:Zola']],
            'remove regex' => ['remove', ['match' => '~^Hugo~'], ['link:Hugo linked', 'literal:Zola']],
            'remove regex text only' => ['remove', ['match' => '~^Hugo~', 'search_in' => ['text']], [$uri, 'link:Hugo linked', 'literal:Zola']],
            'copy contains label' => ['copy', ['contains' => 'label'], [$uri]],
            'visibility exact' => ['visibility', ['contains' => 'Hugo', 'contains_exact' => '1'], ['literal:Hugo text', $uri, 'link:Hugo linked', 'literal:Zola']],
            'visibility exact text' => ['visibility', ['contains' => 'Zola', 'contains_exact' => '1'], ['literal:Hugo text', $uri, 'link:Hugo linked', 'literal:Zola!']],
            'visibility exact label' => ['visibility', ['contains' => 'Hugo label', 'contains_exact' => '1'], ['literal:Hugo text', "$uri!", 'link:Hugo linked', 'literal:Zola']],
            'remove exact title' => ['remove', ['contains' => 'Hugo linked', 'contains_exact' => '1', 'search_in' => ['title']], ['literal:Hugo text', $uri, 'literal:Zola']],
            'copy exact uri' => ['copy', ['contains' => 'https://example.org/123', 'contains_exact' => '1'], [$uri]],
        ];
    }

    /**
     * The exact string replaces the filter "equal" of the removal of values.
     */
    public function testRemoveExactLinkedResourceId(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => [['link', 'First'], ['link', 'Second']]]);
        $linkedId = $this->reloaded($item->id())->value('dcterms:subject', ['all' => true])[1]->valueResource()->id();

        $remove = [
            'properties' => ['dcterms:subject'],
            'datatypes' => [],
            'languages' => [],
            'visibility' => '',
            'contains' => (string) $linkedId,
            'contains_exact' => '1',
            'match' => '',
            'keep_max_values' => '',
            'max_values' => '',
        ];

        // The linked resources are not checked by default.
        $this->batch([$item->id()], ['remove' => $remove + ['search_in' => []]]);
        $this->assertSame(['link:First', 'link:Second'], $this->described($item->id(), 'dcterms:subject'));

        $this->batch([$item->id()], ['remove' => $remove + ['search_in' => ['title']]]);
        $this->assertSame(['link:First'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testProcessSetDirectlyIsKept(): void
    {
        $item = $this->createResource(['dcterms:subject' => ['old'], 'dcterms:title' => ['old']]);

        $this->batch([$item->id()], [
            'replace' => [
                'value_part' => 'value',
                'mode' => 'raw',
                'from' => 'old',
                'to' => 'direct',
                'language' => '',
                'language_clear' => '0',
                'properties' => ['dcterms:title'],
            ],
            'modify' => [
                'properties' => ['dcterms:subject'],
                'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'modify'],
            ],
        ]);

        $this->assertSame(['literal:direct'], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(['literal:old'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testWithoutPropertiesNothingIsModified(): void
    {
        $item = $this->createResource(['dcterms:subject' => ['old']]);

        $this->batch([$item->id()], ['modify' => [
            'properties' => [],
            'replace' => ['enabled' => '1', 'value_part' => 'value', 'action' => 'search', 'match' => 'raw', 'from' => 'old', 'to' => 'new'],
        ]]);

        $this->assertSame(['literal:old'], $this->described($item->id(), 'dcterms:subject'));
    }
}
