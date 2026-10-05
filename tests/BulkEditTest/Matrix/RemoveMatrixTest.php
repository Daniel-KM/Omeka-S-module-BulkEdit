<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the removal of values with all filters.
 */
class RemoveMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    const ALPHA = 'literal:alpha@en';
    const BETA = 'literal:beta@fr!';
    const GAMMA = 'uri:https://example.org/gamma|Gamma label@fr';
    const DELTA = 'link:Delta';

    /**
     * @dataProvider removeProvider
     */
    public function testRemove(array $options, array $kept): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['Kept'],
            'dcterms:subject' => [
                ['literal', 'alpha', 'en', true],
                ['literal', 'beta', 'fr', false],
                ['uri', 'https://example.org/gamma', 'Gamma label', 'fr', true],
                ['link', 'Delta'],
            ],
        ]);

        $this->batch([$item->id()], ['remove' => $this->params($options)]);

        $this->assertSame($kept, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
    }

    public function removeProvider(): array
    {
        $all = [self::ALPHA, self::BETA, self::GAMMA, self::DELTA];
        return [
            'no filter' => [[], []],
            'data type' => [['datatypes' => ['literal']], [self::GAMMA, self::DELTA]],
            'data types' => [['datatypes' => ['uri', 'resource:item']], [self::ALPHA, self::BETA]],
            'language' => [['languages' => ['en']], [self::BETA, self::GAMMA, self::DELTA]],
            'language of uri' => [['languages' => ['fr']], [self::ALPHA, self::DELTA]],
            'public' => [['visibility' => '1'], [self::BETA]],
            'private' => [['visibility' => '0'], [self::ALPHA, self::GAMMA, self::DELTA]],
            'equal literal' => [['equal' => 'alpha'], [self::BETA, self::GAMMA, self::DELTA]],
            'equal uri' => [['equal' => 'https://example.org/gamma'], [self::ALPHA, self::BETA, self::DELTA]],
            'equal nothing' => [['equal' => 'zzz'], $all],
            'contains literal' => [['contains' => 'alph'], [self::BETA, self::GAMMA, self::DELTA]],
            'contains uri' => [['contains' => 'example.org'], [self::ALPHA, self::BETA, self::DELTA]],
            'contains keeps links' => [['contains' => 'Delta'], $all],
            'match' => [['match' => '/^[ab]/'], [self::GAMMA, self::DELTA]],
            'match uri' => [['match' => '~gamma$~'], [self::ALPHA, self::BETA, self::DELTA]],
            'max first' => [['max_values' => 1], [self::BETA, self::GAMMA, self::DELTA]],
            'max first two' => [['max_values' => 2], [self::GAMMA, self::DELTA]],
            'max last' => [['max_values' => -1], [self::ALPHA, self::BETA, self::GAMMA]],
            'keep first' => [['keep_max_values' => 1], [self::ALPHA]],
            'keep first two' => [['keep_max_values' => 2], [self::ALPHA, self::BETA]],
            'keep last' => [['keep_max_values' => -1], [self::DELTA]],
            'keep more than values' => [['keep_max_values' => 10], $all],
            'max and keep are incompatible' => [['max_values' => 1, 'keep_max_values' => 1], $all],
            'data type and language' => [['datatypes' => ['literal'], 'languages' => ['fr']], [self::ALPHA, self::GAMMA, self::DELTA]],
            'language and visibility' => [['languages' => ['fr'], 'visibility' => '1'], [self::ALPHA, self::BETA, self::DELTA]],
            'data type and contains' => [['datatypes' => ['literal'], 'contains' => 'gamma'], $all],
            'filter and max' => [['datatypes' => ['literal', 'uri'], 'max_values' => 1], [self::BETA, self::GAMMA, self::DELTA]],
            'filter and keep' => [['datatypes' => ['literal', 'uri'], 'keep_max_values' => 1], [self::ALPHA, self::DELTA]],
        ];
    }

    public function testEqualLinkedResource(): void
    {
        $linked = $this->createItem(['dcterms:title' => [['@value' => 'Linked']]]);
        $item = $this->createResource(['dcterms:title' => ['Kept']]);
        $this->api()->update('items', $item->id(), $this->resourceData(['dcterms:title' => ['Kept']]) + [
            'dcterms:subject' => [
                ['type' => 'resource:item', 'property_id' => $this->propertyId('dcterms:subject'), 'value_resource_id' => $linked->id()],
                $this->valueData('dcterms:subject', 'text'),
            ],
        ]);

        $this->batch([$item->id()], ['remove' => $this->params(['equal' => (string) $linked->id()])]);

        $this->assertSame(['literal:text'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testAllProperties(): void
    {
        $item = $this->createResource(['dcterms:title' => ['x', 'y'], 'dcterms:subject' => ['x', 'z']]);

        $this->batch([$item->id()], ['remove' => $this->params(['properties' => ['all'], 'equal' => 'x'])]);

        $this->assertSame(['literal:y'], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(['literal:z'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testSeveralProperties(): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['Kept'],
            'dcterms:subject' => ['x'],
            'dcterms:description' => ['x'],
            'dcterms:creator' => ['x'],
        ]);

        $this->batch([$item->id()], ['remove' => $this->params(['properties' => ['dcterms:subject', 'dcterms:description']])]);

        $this->assertSame([], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame([], $this->described($item->id(), 'dcterms:description'));
        $this->assertSame(['literal:x'], $this->described($item->id(), 'dcterms:creator'));
    }

    protected function params(array $options = []): array
    {
        return $options + [
            'properties' => ['dcterms:subject'],
            'datatypes' => [],
            'languages' => [],
            'visibility' => '',
            'equal' => '',
            'contains' => '',
            'match' => '',
            'keep_max_values' => '',
            'max_values' => '',
        ];
    }
}
