<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the creation, the order and the visibility of values, the owner and the
 * thumbnails.
 */
class ResourceDataMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider createValuesProvider
     */
    public function testCreateValues(array $values, array $options, array $expected): void
    {
        $item = $this->createResource(['dcterms:title' => ['Kept']] + ($values ? ['dcterms:subject' => $values] : []));

        $this->batch([$item->id()], ['create_values' => $options + [
            'properties' => 'dcterms:subject',
            'datatype' => 'literal',
            'value' => '',
            'uri_label' => '',
            'value_resource_id' => null,
            'language' => '',
            'visibility' => '1',
        ]]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
    }

    public function createValuesProvider(): array
    {
        $url = 'https://example.org/a';
        return [
            'literal' => [[], ['value' => 'New'], ['literal:New']],
            'literal appended' => [['Old'], ['value' => 'New'], ['literal:Old', 'literal:New']],
            'literal language' => [[], ['value' => 'New', 'language' => 'fr'], ['literal:New@fr']],
            'literal private' => [[], ['value' => 'New', 'visibility' => '0'], ['literal:New!']],
            'literal language and private' => [[], ['value' => 'New', 'language' => 'fr', 'visibility' => '0'], ['literal:New@fr!']],
            'value is trimmed' => [[], ['value' => '  New  '], ['literal:New']],
            'empty value' => [[], ['value' => '   '], []],
            'uri' => [[], ['datatype' => 'uri', 'value' => $url], ["uri:$url"]],
            'uri with label' => [[], ['datatype' => 'uri', 'value' => $url, 'uri_label' => 'Label'], ["uri:$url|Label"]],
            'uri private' => [[], ['datatype' => 'uri', 'value' => $url, 'visibility' => '0'], ["uri:$url!"]],
            'uri language' => [[], ['datatype' => 'uri', 'value' => $url, 'language' => 'fr'], ["uri:$url@fr"]],
            'uri label and language' => [[], ['datatype' => 'uri', 'value' => $url, 'uri_label' => 'Label', 'language' => 'fr'], ["uri:$url|Label@fr"]],
            'uri language and private' => [[], ['datatype' => 'uri', 'value' => $url, 'language' => 'fr', 'visibility' => '0'], ["uri:$url@fr!"]],
            'link without resource' => [[], ['datatype' => 'resource:item'], []],
            'unknown property' => [[], ['properties' => 'foo:bar', 'value' => 'New'], []],
        ];
    }

    public function testCreateLink(): void
    {
        $linked = $this->createResource(['dcterms:title' => ['Linked']]);
        $item = $this->createResource(['dcterms:title' => ['Kept']]);

        $this->batch([$item->id()], ['create_values' => [
            'properties' => 'dcterms:subject',
            'datatype' => 'resource:item',
            'value' => '',
            'uri_label' => '',
            'value_resource_id' => $linked->id(),
            'language' => '',
            'visibility' => '1',
        ]]);

        $this->assertSame(['link:Linked'], $this->described($item->id(), 'dcterms:subject'));
    }

    /**
     * @dataProvider orderValuesProvider
     */
    public function testOrderValues(array $languages, array $properties, array $expectedSubject, array $expectedTitle): void
    {
        $item = $this->createResource([
            'dcterms:title' => [['literal', 't en', 'en'], ['literal', 't fr', 'fr']],
            'dcterms:subject' => [
                'none',
                ['literal', 'en', 'en'],
                ['uri', 'https://example.org/a'],
                ['literal', 'fr', 'fr'],
                ['literal', 'es', 'es'],
            ],
        ]);

        $this->batch([$item->id()], ['order_values' => ['languages' => $languages, 'properties' => $properties]]);

        $this->assertSame($expectedSubject, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame($expectedTitle, $this->described($item->id(), 'dcterms:title'));
    }

    public function orderValuesProvider(): array
    {
        $title = ['literal:t en@en', 'literal:t fr@fr'];
        $titleFr = ['literal:t fr@fr', 'literal:t en@en'];
        $uri = 'uri:https://example.org/a';
        return [
            'one language' => [['fr'], ['dcterms:subject'], ['literal:fr@fr', 'literal:none', $uri, 'literal:en@en', 'literal:es@es'], $title],
            'two languages' => [['fr', 'en'], ['dcterms:subject'], ['literal:fr@fr', 'literal:en@en', 'literal:none', $uri, 'literal:es@es'], $title],
            'all properties' => [['fr'], ['all'], ['literal:fr@fr', 'literal:none', $uri, 'literal:en@en', 'literal:es@es'], $titleFr],
        ];
    }

    /**
     * @dataProvider visibilityProvider
     */
    public function testPropertiesVisibility(array $options, array $expected): void
    {
        $item = $this->createResource([
            'dcterms:title' => ['Kept'],
            'dcterms:subject' => [
                ['literal', 'alpha', 'en', true],
                ['literal', 'beta', 'fr', false],
                ['uri', 'https://example.org/gamma', 'Gamma', 'fr', true],
            ],
        ]);

        $this->batch([$item->id()], ['properties_visibility' => $options + [
            'visibility' => '0',
            'properties' => ['dcterms:subject'],
            'datatypes' => [],
            'languages' => [],
            'contains' => '',
        ]]);

        $this->assertSame($expected, $this->described($item->id(), 'dcterms:subject'));
        $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
    }

    public function visibilityProvider(): array
    {
        $alpha = 'literal:alpha@en';
        $alphaP = 'literal:alpha@en!';
        $beta = 'literal:beta@fr';
        $betaP = 'literal:beta@fr!';
        $gamma = 'uri:https://example.org/gamma|Gamma@fr';
        $gammaP = 'uri:https://example.org/gamma|Gamma@fr!';
        return [
            'private' => [[], [$alphaP, $betaP, $gammaP]],
            'public' => [['visibility' => '1'], [$alpha, $beta, $gamma]],
            'data type' => [['datatypes' => ['uri']], [$alpha, $betaP, $gammaP]],
            'language' => [['languages' => ['en']], [$alphaP, $betaP, $gamma]],
            'language of uri' => [['languages' => ['fr']], [$alpha, $betaP, $gammaP]],
            'contains' => [['contains' => 'alph'], [$alphaP, $betaP, $gamma]],
            'contains uri' => [['contains' => 'example'], [$alpha, $betaP, $gammaP]],
            'public and language' => [['visibility' => '1', 'languages' => ['fr']], [$alpha, $beta, $gamma]],
            'data type and contains' => [['datatypes' => ['literal'], 'contains' => 'zzz'], [$alpha, $betaP, $gamma]],
        ];
    }

    public function testOwner(): void
    {
        $email = 'bulkedit-matrix-' . uniqid() . '@example.com';
        $userId = $this->api()->create('users', ['o:email' => $email, 'o:name' => 'Owner', 'o:role' => 'editor', 'o:is_active' => true])->getContent()->id();
        $item = $this->createResource(['dcterms:title' => ['Kept']]);
        $ownerId = $this->reloaded($item->id())->owner()->id();

        try {
            $this->batch([$item->id()], ['fill_data' => ['owner' => (string) $userId]]);
            $this->assertSame($userId, $this->reloaded($item->id())->owner()->id());

            $this->batch([$item->id()], ['fill_data' => ['owner' => (string) $ownerId]]);
            $this->assertSame($ownerId, $this->reloaded($item->id())->owner()->id());

            $this->batch([$item->id()], ['fill_data' => ['owner' => '0']]);
            $this->assertNull($this->reloaded($item->id())->owner());

            $this->batch([$item->id()], ['fill_data' => ['owner' => '']]);
            $this->assertNull($this->reloaded($item->id())->owner());

            $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
        } finally {
            $this->api()->delete('users', $userId);
        }
    }

    /**
     * @dataProvider thumbnailProvider
     */
    public function testThumbnails(string $mode, ?string $current, bool $primary, string $asset, ?string $expected): void
    {
        $assets = ['a' => $this->createAsset('a'), 'b' => $this->createAsset('b')];
        $extra = $current ? ['o:thumbnail' => ['o:id' => $assets[$current]]] : [];
        if ($primary) {
            $extra['o:media'] = [['o:ingester' => 'html', 'html' => '<p>Media</p>']];
        }
        $item = $this->createResource(['dcterms:title' => ['Kept']], 'items', $extra);
        if ($primary) {
            $mediaId = $item->media()[0]->id();
            $this->getConnection()->executeStatement('UPDATE `item` SET `primary_media_id` = ? WHERE `id` = ?', [$mediaId, $item->id()]);
            $this->getEntityManager()->clear();
            $this->loginAdmin();
        }

        try {
            $this->batch([$item->id()], ['thumbnails' => ['mode' => $mode, 'asset' => $asset === '' ? '' : (string) $assets[$asset]]]);

            $thumbnail = $this->reloaded($item->id())->thumbnail();
            $this->assertSame($expected ? $assets[$expected] : null, $thumbnail ? $thumbnail->id() : null);
            $this->assertSame(['literal:Kept'], $this->described($item->id(), 'dcterms:title'));
        } finally {
            $this->cleanupResources();
            $this->getConnection()->executeStatement('DELETE FROM `asset` WHERE `id` IN (?, ?)', [$assets['a'], $assets['b']]);
        }
    }

    public function thumbnailProvider(): array
    {
        return [
            'fill empty' => ['fill', null, false, 'b', 'b'],
            'fill replaces' => ['fill', 'a', false, 'b', 'b'],
            'append empty' => ['append', null, false, 'b', 'b'],
            'append keeps' => ['append', 'a', false, 'b', 'a'],
            'append no primary without primary' => ['append_no_primary', 'a', false, 'b', 'b'],
            'append no primary with primary' => ['append_no_primary', 'a', true, 'b', 'a'],
            'append no primary no thumbnail, empty' => ['append_no_primary_no_thumbnail', null, false, 'b', 'b'],
            'append no primary no thumbnail, with thumbnail' => ['append_no_primary_no_thumbnail', 'a', false, 'b', 'a'],
            'append no primary no thumbnail, with primary' => ['append_no_primary_no_thumbnail', null, true, 'b', null],
            'replace existing' => ['replace', 'a', false, 'b', 'b'],
            'replace without thumbnail' => ['replace', null, false, 'b', null],
            'remove same' => ['remove', 'a', false, 'a', null],
            'remove other' => ['remove', 'a', false, 'b', 'a'],
            'remove primary with primary' => ['remove_primary', 'a', true, '', null],
            'remove primary without primary' => ['remove_primary', 'a', false, '', 'a'],
            'delete' => ['delete', 'a', false, '', null],
            'delete without thumbnail' => ['delete', null, false, '', null],
            'missing asset' => ['fill', 'a', false, '', 'a'],
        ];
    }

    protected function createAsset(string $name): int
    {
        $connection = $this->getConnection();
        $connection->insert('asset', [
            'name' => $name,
            'media_type' => 'image/png',
            'storage_id' => 'bulkedit-matrix-' . $name . '-' . uniqid(),
            'extension' => 'png',
        ]);
        return (int) $connection->lastInsertId();
    }
}
