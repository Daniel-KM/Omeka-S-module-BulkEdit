<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the explode and the merge of values with all options.
 */
class ExplodeMergeMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider explodeProvider
     */
    public function testExplode(array $values, array $options, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => $values]);

        $this->batch([$item->id()], ['explode' => $options + [
            'properties' => ['dcterms:subject'],
            'separator' => ',',
            'contains' => '',
            'match' => '',
            'max_values' => '',
        ]]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
    }

    public function explodeProvider(): array
    {
        return [
            'comma' => [['a,b,c'], [], ['literal:a', 'literal:b', 'literal:c']],
            'parts are trimmed' => [['a , b ,c'], [], ['literal:a', 'literal:b', 'literal:c']],
            'empty parts are removed' => [['a,,b,'], [], ['literal:a', 'literal:b']],
            'only empty parts removes the value' => [[' , ', 'kept'], [], ['literal:kept']],
            'no separator' => [['abc'], [], ['literal:abc']],
            'semicolon' => [['a;b'], ['separator' => ';'], ['literal:a', 'literal:b']],
            'space separator' => [['a b'], ['separator' => ' '], ['literal:a', 'literal:b']],
            'long separator' => [['a--b-c'], ['separator' => '--'], ['literal:a', 'literal:b-c']],
            'escaped new line' => [["a\nb"], ['separator' => '\n'], ['literal:a', 'literal:b']],
            'escaped tab' => [["a\tb"], ['separator' => '\t'], ['literal:a', 'literal:b']],
            'contains' => [['a,x', 'b,c'], ['contains' => 'x'], ['literal:a', 'literal:x', 'literal:b,c']],
            'contains nothing' => [['a,b'], ['contains' => 'zzz'], ['literal:a,b']],
            'match' => [['a,1', 'b,c'], ['match' => '/\d/'], ['literal:a', 'literal:1', 'literal:b,c']],
            'contains and match' => [['a,1', 'x,2', 'x,c'], ['contains' => 'x', 'match' => '/\d/'], ['literal:a,1', 'literal:x', 'literal:2', 'literal:x,c']],
            'max 1' => [['a,b,c'], ['max_values' => 1], ['literal:a,b,c']],
            'max 2' => [['a,b,c'], ['max_values' => 2], ['literal:a', 'literal:b,c']],
            'max more than parts' => [['a,b'], ['max_values' => 5], ['literal:a', 'literal:b']],
            'max from the end' => [['a,b,c'], ['max_values' => -2], ['literal:a,b', 'literal:c']],
            'max from the end more than parts' => [['a,b'], ['max_values' => -5], ['literal:a', 'literal:b']],
            'language is kept' => [[['literal', 'a,b', 'fr']], [], ['literal:a@fr', 'literal:b@fr']],
            'visibility is kept' => [[['literal', 'a,b', null, false]], [], ['literal:a!', 'literal:b!']],
            'uri is not exploded' => [[['uri', 'https://example.org/a,b']], [], ['uri:https://example.org/a,b']],
            'order is kept' => [['z', 'a,b', 'y'], [], ['literal:z', 'literal:a', 'literal:b', 'literal:y']],
            'filter and max' => [['x,b,c', 'd,e'], ['contains' => 'x', 'max_values' => 2], ['literal:x', 'literal:b,c', 'literal:d,e']],
        ];
    }

    public function testExplodeSeveralProperties(): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['a,b'],
            'dcterms:subject' => ['c,d'],
            'dcterms:description' => ['e,f'],
        ]);

        $this->batch([$item->id()], ['explode' => [
            'properties' => ['dcterms:title', 'dcterms:subject'],
            'separator' => ',',
            'contains' => '',
            'match' => '',
            'max_values' => '',
        ]]);

        $this->assertSame(['literal:a', 'literal:b'], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(['literal:c', 'literal:d'], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:e,f'], $this->described($item->id(), 'dcterms:description'));
    }

    /**
     * @dataProvider mergeProvider
     */
    public function testMerge(array $values, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => $values]);

        $this->batch([$item->id()], ['merge' => ['properties' => ['dcterms:subject']]]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
    }

    public function mergeProvider(): array
    {
        $url = 'https://example.org/a';
        return [
            'label then url' => [['Label', $url], ["uri:$url|Label"]],
            'url then label' => [[$url, 'Label'], ["uri:$url|Label"]],
            'two pairs' => [['One', "$url/1", "$url/2", 'Two'], ["uri:$url/1|One", "uri:$url/2|Two"]],
            'uri without label and literal' => [[['uri', $url], 'Label'], ["uri:$url|Label"]],
            'language of the label' => [[['literal', 'Label', 'fr'], $url], ["uri:$url|Label@fr"]],
            'visibility of the first' => [[['literal', 'Label', null, false], $url], ["uri:$url|Label!"]],
            'odd number is skipped' => [['Label', $url, 'Other'], ['literal:Label', "literal:$url", 'literal:Other']],
            'no url is skipped' => [['a', 'b'], ['literal:a', 'literal:b']],
            'two different urls are skipped' => [[$url, "$url/2"], ["literal:$url", "literal:$url/2"]],
            'one invalid pair skips the property' => [['Label', $url, 'a', 'b'], ['literal:Label', "literal:$url", 'literal:a', 'literal:b']],
            'uri with label is skipped' => [[['uri', $url, 'Label'], 'Other'], ["uri:$url|Label", 'literal:Other']],
            'two uris are skipped' => [[['uri', $url], ['uri', "$url/2"]], ["uri:$url", "uri:$url/2"]],
            'link is skipped' => [[['link', 'Linked'], $url], ['link:Linked', "literal:$url"]],
        ];
    }
}
