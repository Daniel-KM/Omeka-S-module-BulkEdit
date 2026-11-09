<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the common filters of the process to fill values via Value Suggest.
 *
 * The labels are removed, so there is no remote request.
 */
class FillValuesFiltersTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider filtersProvider
     */
    public function testRemoveLabelsWithFilters(array $filters, array $expected): void
    {
        if (!$this->getServiceLocator()->get('Omeka\DataTypeManager')->has('valuesuggest:idref:person')) {
            $this->markTestSkipped('Requires module Value Suggest.');
        }

        $propertyId = $this->propertyId('dcterms:subject');
        $value = fn (string $id, string $label, ?string $language, bool $isPublic) => [
            'type' => 'valuesuggest:idref:person',
            'property_id' => $propertyId,
            '@id' => 'https://www.idref.fr/' . $id,
            'o:label' => $label,
            'o:lang' => $language,
            '@language' => $language,
            'is_public' => $isPublic,
        ];
        $item = $this->createResource(['dcterms:title' => ['Kept']], 'items', [
            'dcterms:subject' => [
                $value('1', 'Hugo', 'fr', true),
                $value('2', 'Zola', 'en', false),
            ],
        ]);

        $this->batch([$item->id()], ['fill_values' => $filters + [
            'mode' => 'label_remove',
            'properties' => ['dcterms:subject'],
            'datatypes' => ['valuesuggest:idref:person'],
            'datatype' => '',
            'language' => '',
            'update_language' => 'keep',
            'featured_subject' => '0',
        ]]);

        $labels = array_map(fn ($value) => $value->value(), $this->reloaded($item->id())->value('dcterms:subject', ['all' => true]));
        $this->assertSame($expected, $labels);
    }

    public function filtersProvider(): array
    {
        return [
            'no filter' => [[], [null, null]],
            'language' => [['languages' => ['fr']], [null, 'Zola']],
            'visibility' => [['is_public' => '0'], ['Hugo', null]],
            'contains' => [['contains' => '/2'], ['Hugo', null]],
            'regex' => [['match' => '~/1$~'], [null, 'Zola']],
        ];
    }
}
