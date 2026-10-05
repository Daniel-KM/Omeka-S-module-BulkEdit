<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the conversion of data types with all options.
 */
class ConvertMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider convertProvider
     */
    public function testConvert(array $values, string $from, string $to, array $options, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => $values]);

        $this->batch([$item->id()], ['convert' => $this->params($from, $to, $options)]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
    }

    public function convertProvider(): array
    {
        $url = 'https://example.org/a';
        $uri = ['uri', $url, 'Label'];
        return [
            'literal to uri' => [[$url], 'literal', 'uri', [], ["uri:$url"]],
            'literal to uri with default label' => [[$url], 'literal', 'uri', ['uri_label' => 'Default'], ["uri:$url|Default"]],
            'literal to uri with extracted label' => [["$url My label"], 'literal', 'uri', ['uri_extract_label' => true], ["uri:$url|My label"]],
            'literal to uri extracts label or default' => [[$url], 'literal', 'uri', ['uri_extract_label' => true, 'uri_label' => 'Default'], ["uri:$url|Default"]],
            'literal to uri keeps language' => [[['literal', $url, 'fr']], 'literal', 'uri', [], ["uri:$url@fr"]],
            'literal to uri keeps visibility' => [[['literal', $url, null, false]], 'literal', 'uri', [], ["uri:$url!"]],
            'literal to uri contains' => [[$url, 'https://example.org/b'], 'literal', 'uri', ['contains' => '/a'], ["uri:$url", 'literal:https://example.org/b']],
            'uri to literal: label or uri' => [[$uri, ['uri', "$url/2"]], 'uri', 'literal', ['literal_value' => 'label_or_uri'], ['literal:Label', "literal:$url/2"]],
            'uri to literal: label' => [[$uri], 'uri', 'literal', ['literal_value' => 'label'], ['literal:Label']],
            'uri to literal: label is required' => [[['uri', $url]], 'uri', 'literal', ['literal_value' => 'label'], ["uri:$url"]],
            'uri to literal: uri' => [[$uri], 'uri', 'literal', ['literal_value' => 'uri'], ["literal:$url"]],
            'uri to literal: label and uri' => [[$uri], 'uri', 'literal', ['literal_value' => 'label_uri'], ["literal:Label ($url)"]],
            'uri to literal: uri and label' => [[$uri], 'uri', 'literal', ['literal_value' => 'uri_label'], ["literal:$url (Label)"]],
            'uri to literal: without label' => [[['uri', $url]], 'uri', 'literal', ['literal_value' => 'label_uri'], ["literal:$url"]],
            'uri to literal keeps language' => [[['uri', $url, 'Label', 'fr']], 'uri', 'literal', ['literal_value' => 'label'], ['literal:Label@fr']],
            'uri to literal keeps visibility' => [[['uri', $url, 'Label', null, false]], 'uri', 'literal', ['literal_value' => 'label'], ['literal:Label!']],
            'uri to literal contains' => [[$uri, ['uri', 'https://other.org/b', 'Other']], 'uri', 'literal', ['literal_value' => 'label', 'contains' => 'example'], ['literal:Label', 'uri:https://other.org/b|Other']],
            'link to literal' => [[['link', 'Linked title']], 'resource:item', 'literal', [], ['literal:Linked title']],
            'link to literal keeps visibility' => [[['link', 'Linked title', false]], 'resource:item', 'literal', [], ['literal:Linked title!']],
            'link to literal contains' => [[['link', 'Match'], ['link', 'Other']], 'resource:item', 'literal', ['contains' => 'Mat'], ['literal:Match', 'link:Other']],
            'link to uri without base is skipped' => [[['link', 'Linked']], 'resource:item', 'uri', [], ['link:Linked']],
            'link to item set is skipped' => [[['link', 'Linked']], 'resource:item', 'resource:itemset', [], ['link:Linked']],
            'other data type is untouched' => [[$uri, 'text'], 'literal', 'uri', ['contains' => 'nothing'], ['uri:https://example.org/a|Label', 'literal:text']],
            'same data types' => [['text'], 'literal', 'literal', [], ['literal:text']],
            'invalid data type' => [['text'], 'literal', 'unknown', [], ['literal:text']],
            'literal to resource requires identifiers' => [['12'], 'literal', 'resource:item', [], ['literal:12']],
        ];
    }

    public function testLinkToUriViaApi(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => [['link', 'Linked']]]);
        $linkedId = $this->reloaded($item->id())->value('dcterms:subject')->valueResource()->id();

        $this->batch([$item->id()], ['convert' => $this->params('resource:item', 'uri', ['uri_base_site' => 'api'])]);

        $value = $this->reloaded($item->id())->value('dcterms:subject');
        $this->assertSame('uri', $value->type());
        $this->assertStringEndsWith('api/items/' . $linkedId, $value->uri());
        $this->assertSame('Linked', $value->value());
    }

    public function testUriToLink(): void
    {
        $linked = $this->createResource(['dcterms:title' => ['Linked']]);
        $base = dirname($linked->apiUrl(), 3);
        $item = $this->createResource(['dcterms:title' => ['Kept'], 'dcterms:subject' => [['uri', $base . '/item/' . $linked->id()], ['uri', 'https://other.org/x']]]);

        $this->batch([$item->id()], ['convert' => $this->params('uri', 'resource:item')]);

        $this->assertSame(['link:Linked', 'uri:https://other.org/x'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testAllAndSeveralProperties(): void
    {
        $url = 'https://example.org/a';
        $item = $this->createResource(['dcterms:title' => [$url], 'dcterms:subject' => [$url], 'dcterms:description' => [$url]]);

        $this->batch([$item->id()], ['convert' => $this->params('literal', 'uri', ['properties' => ['dcterms:title', 'dcterms:subject']])]);
        $this->assertSame(["uri:$url"], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(["uri:$url"], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(["literal:$url"], $this->described($item->id(), 'dcterms:description'));

        $this->batch([$item->id()], ['convert' => $this->params('literal', 'uri', ['properties' => ['all']])]);
        $this->assertSame(["uri:$url"], $this->described($item->id(), 'dcterms:description'));
    }

    protected function params(string $from, string $to, array $options = []): array
    {
        return $options + [
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
