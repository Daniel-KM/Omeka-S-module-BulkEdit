<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the copy and the displacement of values with all filters.
 */
class CopyDisplaceMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    const ALPHA = 'literal:alpha@en';
    const BETA = 'literal:beta@fr!';
    const GAMMA = 'uri:https://example.org/gamma|Gamma label@fr';
    const DELTA = 'link:Delta';

    /**
     * @dataProvider filtersProvider
     */
    public function testCopy(array $options, array $selected): void
    {
        $item = $this->createResource($this->sample());

        $this->batch([$item->id()], ['copy' => $this->params($options)]);

        $this->assertSame([self::ALPHA, self::BETA, self::GAMMA, self::DELTA], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame($selected, $this->described($item->id(), 'dcterms:description'));
    }

    /**
     * @dataProvider filtersProvider
     */
    public function testDisplace(array $options, array $selected): void
    {
        $item = $this->createResource($this->sample());

        $this->batch([$item->id()], ['displace' => $this->params($options)]);

        $remaining = array_values(array_diff([self::ALPHA, self::BETA, self::GAMMA, self::DELTA], $selected));
        $this->assertSame($remaining, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame($selected, $this->described($item->id(), 'dcterms:description'));
    }

    public function filtersProvider(): array
    {
        return [
            'no filter' => [[], [self::ALPHA, self::BETA, self::GAMMA, self::DELTA]],
            'data type literal' => [['datatypes' => ['literal']], [self::ALPHA, self::BETA]],
            'data type uri' => [['datatypes' => ['uri']], [self::GAMMA]],
            'data type resource' => [['datatypes' => ['resource:item']], [self::DELTA]],
            'data types' => [['datatypes' => ['uri', 'resource:item']], [self::GAMMA, self::DELTA]],
            'language fr' => [['languages' => ['fr']], [self::BETA, self::GAMMA]],
            'language en' => [['languages' => ['en']], [self::ALPHA]],
            'languages' => [['languages' => ['en', 'fr']], [self::ALPHA, self::BETA, self::GAMMA]],
            'public' => [['visibility' => '1'], [self::ALPHA, self::GAMMA, self::DELTA]],
            'private' => [['visibility' => '0'], [self::BETA]],
            'contains literal' => [['contains' => 'alph'], [self::ALPHA]],
            'contains uri' => [['contains' => 'example.org'], [self::GAMMA]],
            'contains nothing' => [['contains' => 'zzz'], []],
            'match' => [['match' => '/^[ab]/'], [self::ALPHA, self::BETA]],
            'match uri' => [['match' => '~gamma$~'], [self::GAMMA]],
            'max 1' => [['max_values' => 1], [self::ALPHA]],
            'max 2' => [['max_values' => 2], [self::ALPHA, self::BETA]],
            'max more than values' => [['max_values' => 10], [self::ALPHA, self::BETA, self::GAMMA, self::DELTA]],
            'language and private' => [['languages' => ['fr'], 'visibility' => '0'], [self::BETA]],
            'language and public' => [['languages' => ['fr'], 'visibility' => '1'], [self::GAMMA]],
            'data type and language' => [['datatypes' => ['literal'], 'languages' => ['fr']], [self::BETA]],
            'data type, language and contains' => [['datatypes' => ['literal'], 'languages' => ['fr'], 'contains' => 'zzz'], []],
            'language and max' => [['languages' => ['fr'], 'max_values' => 1], [self::BETA]],
            'filters and max' => [['datatypes' => ['literal', 'uri'], 'visibility' => '1', 'max_values' => 1], [self::ALPHA]],
        ];
    }

    public function testCopyAppendsToExistingValues(): void
    {
        $item = $this->createResource(['dcterms:subject' => ['new'], 'dcterms:description' => ['existing']]);

        $this->batch([$item->id()], ['copy' => $this->params()]);

        $this->assertSame(['literal:existing', 'literal:new'], $this->described($item->id(), 'dcterms:description'));
    }

    public function testSeveralSourceProperties(): void
    {
        $item = $this->createResource(['dcterms:title' => ['a'], 'dcterms:subject' => ['b']]);

        $this->batch([$item->id()], ['displace' => $this->params(['from' => ['dcterms:title', 'dcterms:subject']])]);

        $this->assertSame([], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame([], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:a', 'literal:b'], $this->described($item->id(), 'dcterms:description'));
    }

    public function testMaxValuesIsByProperty(): void
    {
        $item = $this->createResource(['dcterms:title' => ['a', 'b'], 'dcterms:subject' => ['c', 'd']]);

        $this->batch([$item->id()], ['copy' => $this->params(['from' => ['dcterms:title', 'dcterms:subject'], 'max_values' => 1])]);

        $this->assertSame(['literal:a', 'literal:c'], $this->described($item->id(), 'dcterms:description'));
    }

    public function testAllPropertiesExceptDestination(): void
    {
        $item = $this->createResource(['dcterms:title' => ['a'], 'dcterms:subject' => ['b'], 'dcterms:description' => ['c']]);

        $this->batch([$item->id()], ['copy' => $this->params(['from' => ['all']])]);

        $this->assertSame(['literal:c', 'literal:a', 'literal:b'], $this->described($item->id(), 'dcterms:description'));
    }

    public function testSourceEqualToDestinationIsIgnored(): void
    {
        $item = $this->createResource(['dcterms:description' => ['a']]);

        $this->batch([$item->id()], ['copy' => $this->params(['from' => ['dcterms:description']])]);

        $this->assertSame(['literal:a'], $this->described($item->id(), 'dcterms:description'));
    }

    /**
     * @dataProvider dataTypesProvider
     */
    public function testDestinationDataType(string $operation, $value, string $dataType, array $source, array $destination): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => [$value]]);

        $this->batch([$item->id()], [$operation => $this->params(['datatype' => $dataType])]);

        $this->assertSame($source, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame($destination, $this->described($item->id(), 'dcterms:description'));
    }

    public function dataTypesProvider(): array
    {
        $uri = ['uri', 'https://example.org/a', 'Label'];
        return [
            'copy literal to literal' => ['copy', 'a', 'literal', ['literal:a'], ['literal:a']],
            'copy literal to uri is skipped' => ['copy', 'a', 'uri', ['literal:a'], []],
            'displace literal to uri is skipped' => ['displace', 'a', 'uri', ['literal:a'], []],
            'displace uri to literal is skipped' => ['displace', $uri, 'literal', ['uri:https://example.org/a|Label'], []],
            'displace uri to uri' => ['displace', $uri, 'uri', [], ['uri:https://example.org/a|Label']],
            'displace link to uri is skipped' => ['displace', ['link', 'Linked'], 'uri', ['link:Linked'], []],
        ];
    }

    protected function sample(): array
    {
        return [
            'dcterms:title' => ['Kept'],
            'dcterms:subject' => [
                ['literal', 'alpha', 'en', true],
                ['literal', 'beta', 'fr', false],
                ['uri', 'https://example.org/gamma', 'Gamma label', 'fr', true],
                ['link', 'Delta'],
            ],
        ];
    }

    protected function params(array $options = []): array
    {
        return $options + [
            'from' => ['dcterms:subject'],
            'to' => 'dcterms:description',
            'datatypes' => [],
            'languages' => [],
            'visibility' => '',
            'contains' => '',
            'match' => '',
            'datatype' => '',
            'max_values' => '',
        ];
    }
}
