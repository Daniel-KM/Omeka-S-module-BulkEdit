<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the replacement of values with all modes, parts and options.
 */
class ReplaceMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider replaceProvider
     */
    public function testReplace(array $values, array $options, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept title'], 'dcterms:subject' => $values]);

        $this->batch([$item->id()], ['replace' => $options + [
            'value_part' => 'value',
            'mode' => 'raw',
            'from' => '',
            'to' => '',
            'prepend' => '',
            'append' => '',
            'language' => '',
            'language_clear' => false,
            'properties' => ['dcterms:subject'],
        ]]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept title'], $this->described($item->id(), 'dcterms:title'));
    }

    public function replaceProvider(): array
    {
        $uri = ['uri', 'https://example.org/old', 'Old label'];
        return [
            'raw' => [['Hello World'], ['from' => 'World', 'to' => 'There'], ['literal:Hello There']],
            'raw without match' => [['Hello World'], ['from' => 'Nothing', 'to' => 'There'], ['literal:Hello World']],
            'raw is case sensitive' => [['Hello World'], ['from' => 'world', 'to' => 'There'], ['literal:Hello World']],
            'raw on several values' => [['a x', 'b', 'c x'], ['from' => 'x', 'to' => 'y'], ['literal:a y', 'literal:b', 'literal:c y']],
            'raw_i' => [['Hello World'], ['mode' => 'raw_i', 'from' => 'world', 'to' => 'There'], ['literal:Hello There']],
            'regex' => [['Hello World'], ['mode' => 'regex', 'from' => '/W\w+/', 'to' => 'X'], ['literal:Hello X']],
            'regex with references' => [['Hello World'], ['mode' => 'regex', 'from' => '/(\w+) (\w+)/', 'to' => '$2 $1'], ['literal:World Hello']],
            'regex empties the value' => [['Hello', 'Kept 1'], ['mode' => 'regex', 'from' => '/^Hello$/', 'to' => ''], ['literal:Kept 1']],
            'regex invalid' => [['Hello'], ['mode' => 'regex', 'from' => '/invalid(', 'to' => 'X'], ['literal:Hello']],
            'regex invalid with options' => [['Hello'], ['mode' => 'regex', 'from' => '/invalid(', 'to' => 'X', 'append' => '!', 'language' => 'fr'], ['literal:Hello!@fr']],
            'html' => [['a & b'], ['mode' => 'html', 'from' => '&', 'to' => 'and'], ['literal:a and b']],
            'html with entity' => [['a &amp; b'], ['mode' => 'html', 'from' => '&', 'to' => 'and'], ['literal:a and b']],
            'unicode' => [['cafÃ©'], ['mode' => 'unicode'], ['literal:café']],
            'unicode already valid' => [['café'], ['mode' => 'unicode'], ['literal:café']],
            'basename' => [['dir/sub/file.jpg'], ['mode' => 'basename'], ['literal:file.jpg']],
            'filename' => [['dir/sub/file.jpg'], ['mode' => 'filename'], ['literal:file']],
            'whole' => [['a', 'b'], ['mode' => 'whole', 'to' => 'New'], ['literal:New', 'literal:New']],
            'whole empty' => [['a', ['uri', 'https://example.org/u']], ['mode' => 'whole', 'to' => ''], ['uri:https://example.org/u']],
            'remove' => [['a', 'b'], ['mode' => 'remove'], []],
            'remove with append' => [['a'], ['mode' => 'remove', 'append' => ' x', 'prepend' => 'y '], []],
            'prepend' => [['a'], ['prepend' => 'Pre '], ['literal:Pre a']],
            'append' => [['a'], ['append' => ' post'], ['literal:a post']],
            'prepend and append' => [['a'], ['prepend' => '<', 'append' => '>'], ['literal:<a>']],
            'replace and affix' => [['a b'], ['from' => 'b', 'to' => 'c', 'prepend' => '[', 'append' => ']'], ['literal:[a c]']],
            'language set' => [['a'], ['language' => 'fr'], ['literal:a@fr']],
            'language replaced' => [[['literal', 'a', 'en']], ['language' => 'fr'], ['literal:a@fr']],
            'language cleared' => [[['literal', 'a', 'en']], ['language_clear' => true], ['literal:a']],
            'language clear wins over language' => [[['literal', 'a', 'en']], ['language' => 'fr', 'language_clear' => true], ['literal:a']],
            'replace, affix and language' => [['a b'], ['from' => 'a', 'to' => 'z', 'append' => '.', 'language' => 'fr'], ['literal:z b.@fr']],
            'language is kept' => [[['literal', 'a', 'en']], ['from' => 'a', 'to' => 'b'], ['literal:b@en']],
            'visibility is kept' => [[['literal', 'a', null, false]], ['from' => 'a', 'to' => 'b'], ['literal:b!']],
            'part value skips uri' => [[$uri, 'old'], ['from' => 'old', 'to' => 'new'], ['uri:https://example.org/old|Old label', 'literal:new']],
            'part value skips link' => [[['link', 'Old title'], 'old'], ['from' => 'old', 'to' => 'new'], ['link:Old title', 'literal:new']],
            'part literal' => [[$uri, 'old'], ['value_part' => 'literal', 'from' => 'old', 'to' => 'new'], ['uri:https://example.org/old|Old label', 'literal:new']],
            'part uri' => [[$uri, 'old'], ['value_part' => 'uri', 'from' => 'old', 'to' => 'new'], ['uri:https://example.org/new|Old label', 'literal:old']],
            'part uri with language' => [[$uri], ['value_part' => 'uri', 'from' => 'old', 'to' => 'new', 'language' => 'fr'], ['uri:https://example.org/new|Old label@fr']],
            'part uri remove' => [[$uri, 'old'], ['value_part' => 'uri', 'mode' => 'remove'], ['literal:old']],
            'part uri label' => [[$uri, 'old'], ['value_part' => 'uri_label', 'from' => 'Old', 'to' => 'New'], ['uri:https://example.org/old|New label', 'literal:old']],
            'part uri label regex' => [[$uri], ['value_part' => 'uri_label', 'mode' => 'regex', 'from' => '/^Old/', 'to' => 'Any'], ['uri:https://example.org/old|Any label']],
            'part uri label without label' => [[['uri', 'https://example.org/old']], ['value_part' => 'uri_label', 'from' => 'a', 'to' => 'b'], ['uri:https://example.org/old']],
            'part uri label remove keeps uri' => [[$uri], ['value_part' => 'uri_label', 'mode' => 'remove'], ['uri:https://example.org/old']],
            'part invalid' => [['a'], ['value_part' => 'other', 'from' => 'a', 'to' => 'b'], ['literal:a']],
            'mode invalid' => [['a'], ['mode' => 'other', 'from' => 'a', 'to' => 'b'], ['literal:a']],
        ];
    }

    public function testAllProperties(): void
    {
        $item = $this->createResource(['dcterms:title' => ['x title'], 'dcterms:subject' => ['x subject']]);

        $this->batch([$item->id()], ['replace' => [
            'value_part' => 'value', 'mode' => 'raw', 'from' => 'x', 'to' => 'y', 'prepend' => '', 'append' => '',
            'language' => '', 'language_clear' => false, 'properties' => ['all'],
        ]]);

        $this->assertSame(['literal:y title'], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(['literal:y subject'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testSeveralPropertiesLeaveOthers(): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['x title'],
            'dcterms:subject' => ['x subject'],
            'dcterms:description' => ['x description'],
        ]);

        $this->batch([$item->id()], ['replace' => [
            'value_part' => 'value', 'mode' => 'raw', 'from' => 'x', 'to' => 'y', 'prepend' => '', 'append' => '',
            'language' => '', 'language_clear' => false, 'properties' => ['dcterms:title', 'dcterms:subject'],
        ]]);

        $this->assertSame(['literal:y title'], $this->described($item->id(), 'dcterms:title'));
        $this->assertSame(['literal:y subject'], $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:x description'], $this->described($item->id(), 'dcterms:description'));
    }
}
