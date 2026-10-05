<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the processes on media from items and from media.
 */
class MediaMatrixTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * @dataProvider mediaHtmlProvider
     */
    public function testMediaHtml(string $html, array $options, string $expected): void
    {
        foreach (['items', 'media'] as $resourceName) {
            $item = $this->createItemWithMedia([['o:ingester' => 'html', 'html' => $html]]);
            $mediaId = $item->media()[0]->id();

            $this->batch(
                [$resourceName === 'items' ? $item->id() : $mediaId],
                ['media_html' => $options + ['mode' => 'raw', 'from' => '', 'to' => '', 'prepend' => '', 'append' => '']],
                $resourceName
            );

            $this->assertSame($expected, $this->reloaded($mediaId, 'media')->mediaData()['html'], $resourceName);
        }
    }

    public function mediaHtmlProvider(): array
    {
        return [
            'raw' => ['<p>Old</p>', ['from' => 'Old', 'to' => 'New'], '<p>New</p>'],
            'raw is case sensitive' => ['<p>Old</p>', ['from' => 'old', 'to' => 'New'], '<p>Old</p>'],
            'raw_i' => ['<p>Old</p>', ['mode' => 'raw_i', 'from' => 'old', 'to' => 'New'], '<p>New</p>'],
            'regex' => ['<p>Old 12</p>', ['mode' => 'regex', 'from' => '/\d+/', 'to' => 'N'], '<p>Old N</p>'],
            'regex invalid' => ['<p>Old</p>', ['mode' => 'regex', 'from' => '/invalid(', 'to' => 'N'], '<p>Old</p>'],
            'html' => ['<p>a &amp; b</p>', ['mode' => 'html', 'from' => '&', 'to' => 'and'], '<p>a and b</p>'],
            'unicode' => ['<p>cafÃ©</p>', ['mode' => 'unicode'], '<p>café</p>'],
            'remove' => ['<p>Old</p>', ['mode' => 'remove'], ''],
            'prepend' => ['<p>Old</p>', ['prepend' => '<h1>T</h1>'], '<h1>T</h1><p>Old</p>'],
            'append' => ['<p>Old</p>', ['append' => '<p>End</p>'], '<p>Old</p><p>End</p>'],
            'replace and affix' => ['<p>Old</p>', ['from' => 'Old', 'to' => 'New', 'prepend' => '<div>', 'append' => '</div>'], '<div><p>New</p></div>'],
            'without match' => ['<p>Old</p>', ['from' => 'None', 'to' => 'New'], '<p>Old</p>'],
            'mode without option' => ['<p>Old</p>', ['mode' => 'raw'], '<p>Old</p>'],
        ];
    }

    /**
     * @dataProvider mediaSourceProvider
     */
    public function testMediaSource(string $source, array $options, ?string $expected): void
    {
        foreach (['items', 'media'] as $resourceName) {
            $item = $this->createItemWithMedia([['o:ingester' => 'html', 'html' => '<p>a</p>', 'o:source' => $source]]);
            $mediaId = $item->media()[0]->id();

            $this->batch(
                [$resourceName === 'items' ? $item->id() : $mediaId],
                ['media_source' => $options + ['mode' => 'raw', 'from' => '', 'to' => '', 'prepend' => '', 'append' => '']],
                $resourceName
            );

            $this->assertSame($expected, $this->reloaded($mediaId, 'media')->source(), $resourceName);
        }
    }

    public function mediaSourceProvider(): array
    {
        $source = 'https://example.org/path/File.jpg';
        return [
            'raw' => [$source, ['from' => 'File', 'to' => 'New'], 'https://example.org/path/New.jpg'],
            'raw is case sensitive' => [$source, ['from' => 'file', 'to' => 'New'], $source],
            'raw_i' => [$source, ['mode' => 'raw_i', 'from' => 'file', 'to' => 'New'], 'https://example.org/path/New.jpg'],
            'regex' => [$source, ['mode' => 'regex', 'from' => '~^.*/~', 'to' => ''], 'File.jpg'],
            'basename' => [$source, ['mode' => 'basename'], 'File.jpg'],
            'filename' => [$source, ['mode' => 'filename'], 'File'],
            'unicode' => ['cafÃ©.jpg', ['mode' => 'unicode'], 'café.jpg'],
            'remove' => [$source, ['mode' => 'remove'], null],
            'prepend' => ['File.jpg', ['prepend' => 'dir/'], 'dir/File.jpg'],
            'append' => ['File', ['append' => '.jpg'], 'File.jpg'],
            'basename and affix' => [$source, ['mode' => 'basename', 'prepend' => 'x-'], 'x-File.jpg'],
            'mode without option' => [$source, ['mode' => 'raw'], $source],
        ];
    }

    /**
     * @dataProvider mediaTypeProvider
     */
    public function testMediaType(array $options, array $expected): void
    {
        $item = $this->createItemWithFiles(['a.png' => $this->png(), 'b.pdf' => $this->pdf()]);

        $this->batch([$item->id()], ['media_type' => $options]);

        $types = array_map(fn ($media) => $media->mediaType(), $this->reloaded($item->id())->media());
        $this->assertSame($expected, $types);
    }

    public function mediaTypeProvider(): array
    {
        return [
            'matching' => [['from' => 'application/pdf', 'to' => 'application/x-pdf'], ['image/png', 'application/x-pdf']],
            'not matching' => [['from' => 'text/plain', 'to' => 'text/html'], ['image/png', 'application/pdf']],
            'case and spaces' => [['from' => ' APPLICATION/PDF ', 'to' => ' Application/X-Pdf '], ['image/png', 'application/x-pdf']],
            'invalid destination' => [['from' => 'application/pdf', 'to' => 'not a type'], ['image/png', 'application/pdf']],
            'empty destination' => [['from' => 'application/pdf', 'to' => ''], ['image/png', 'application/pdf']],
            'same' => [['from' => 'application/pdf', 'to' => 'application/pdf'], ['image/png', 'application/pdf']],
        ];
    }

    public function testMediaTypeFromMedia(): void
    {
        $item = $this->createItemWithFiles(['a.png' => $this->png(), 'b.pdf' => $this->pdf()]);
        $medias = $item->media();

        // Only the selected media is updated.
        $this->batch([$medias[0]->id()], ['media_type' => ['from' => 'image/png', 'to' => 'image/x-png']], 'media');

        $this->assertSame('image/x-png', $this->reloaded($medias[0]->id(), 'media')->mediaType());
        $this->assertSame('application/pdf', $this->reloaded($medias[1]->id(), 'media')->mediaType());
    }

    /**
     * @dataProvider mediaVisibilityProvider
     */
    public function testMediaVisibility(array $options, array $expected): void
    {
        $item = $this->createItemWithFiles(['a.png' => $this->png(), 'b.pdf' => $this->pdf()], [
            ['o:ingester' => 'html', 'html' => '<p>a</p>'],
        ]);

        $this->batch([$item->id()], ['media_visibility' => $options + [
            'visibility' => '0',
            'media_types' => [],
            'ingesters' => [],
            'renderers' => [],
        ]]);

        $publics = array_map(fn ($media) => $media->isPublic(), $this->reloaded($item->id())->media());
        $this->assertSame($expected, $publics);
    }

    public function mediaVisibilityProvider(): array
    {
        return [
            'all private' => [[], [false, false, false]],
            'all public' => [['visibility' => '1'], [true, true, true]],
            'media type' => [['media_types' => ['image/png']], [false, true, true]],
            'media types' => [['media_types' => ['image/png', 'application/pdf']], [false, false, true]],
            'ingester' => [['ingesters' => ['html']], [true, true, false]],
            'renderer' => [['renderers' => ['file']], [false, false, true]],
            'renderer and media type' => [['renderers' => ['file'], 'media_types' => ['application/pdf']], [true, false, true]],
            'ingester and media type do not overlap' => [['ingesters' => ['html'], 'media_types' => ['image/png']], [true, true, true]],
        ];
    }

    public function testMediaVisibilityFromMedia(): void
    {
        $item = $this->createItemWithFiles(['a.png' => $this->png(), 'b.pdf' => $this->pdf()]);
        $medias = $item->media();

        $this->batch([$medias[1]->id()], ['media_visibility' => ['visibility' => '0', 'media_types' => [], 'ingesters' => [], 'renderers' => []]], 'media');

        $this->assertTrue($this->reloaded($medias[0]->id(), 'media')->isPublic());
        $this->assertFalse($this->reloaded($medias[1]->id(), 'media')->isPublic());
    }

    /**
     * @dataProvider mediaRemoveProvider
     */
    public function testMediaRemove(array $options, array $expected): void
    {
        $item = $this->createItemWithFiles(['a.png' => $this->png(), 'b.pdf' => $this->pdf()], [
            ['o:ingester' => 'html', 'html' => '<p>a</p>', 'o:source' => 'html'],
        ]);

        $this->batch([$item->id()], ['media_remove' => $options + ['mediatypes' => [], 'extensions' => []]]);

        $sources = array_map(fn ($media) => $media->source(), $this->reloaded($item->id())->media());
        $this->assertSame($expected, $sources);
    }

    public function mediaRemoveProvider(): array
    {
        return [
            'all' => [['mode' => 'all'], []],
            'media type' => [['mode' => 'media_type', 'mediatypes' => ['application/pdf']], ['a.png', 'html']],
            'media types' => [['mode' => 'media_type', 'mediatypes' => ['application/pdf', 'image/png']], ['html']],
            'media type without list' => [['mode' => 'media_type'], ['a.png', 'b.pdf', 'html']],
            'media type not matching' => [['mode' => 'media_type', 'mediatypes' => ['video/mp4']], ['a.png', 'b.pdf', 'html']],
            'extension' => [['mode' => 'media_extension', 'extensions' => ['png']], ['b.pdf', 'html']],
            'extensions' => [['mode' => 'media_extension', 'extensions' => ['png', 'pdf']], ['html']],
            'extension without list' => [['mode' => 'media_extension'], ['a.png', 'b.pdf', 'html']],
            'invalid mode' => [['mode' => 'other'], ['a.png', 'b.pdf', 'html']],
        ];
    }

    /**
     * @dataProvider mediaOrderProvider
     */
    public function testMediaOrder(array $options, array $expected): void
    {
        $item = $this->createItemWithFiles(
            ['b.png' => $this->png(), 'c.pdf' => $this->pdf(), 'a.pdf' => $this->pdf('A')],
            [],
            ['b.png' => 'Title 3', 'c.pdf' => 'Title 1', 'a.pdf' => 'Title 2']
        );

        $this->batch([$item->id()], ['media_order' => $options + ['mediatypes' => [], 'extensions' => []]]);

        $sources = array_map(fn ($media) => $media->source(), $this->reloaded($item->id())->media());
        $this->assertSame($expected, $sources);
    }

    public function mediaOrderProvider(): array
    {
        return [
            'title' => [['order' => 'title'], ['c.pdf', 'a.pdf', 'b.png']],
            'source' => [['order' => 'source'], ['a.pdf', 'b.png', 'c.pdf']],
            'basename' => [['order' => 'basename'], ['a.pdf', 'b.png', 'c.pdf']],
            'media type' => [['order' => 'mediatype'], ['c.pdf', 'a.pdf', 'b.png']],
            'media type list' => [['order' => 'mediatype', 'mediatypes' => ['image/png', 'application/pdf']], ['b.png', 'c.pdf', 'a.pdf']],
            'main media type list' => [['order' => 'mediatype', 'mediatypes' => ['image']], ['b.png', 'c.pdf', 'a.pdf']],
            'extension' => [['order' => 'extension'], ['c.pdf', 'a.pdf', 'b.png']],
            'extension list' => [['order' => 'extension', 'extensions' => ['png']], ['b.png', 'c.pdf', 'a.pdf']],
            'media type then source' => [['order' => 'mediatype/source'], ['a.pdf', 'c.pdf', 'b.png']],
            'list then source' => [['order' => 'mediatype/source', 'mediatypes' => ['image/png', 'application/pdf']], ['b.png', 'a.pdf', 'c.pdf']],
            'extension then title' => [['order' => 'extension/title'], ['c.pdf', 'a.pdf', 'b.png']],
            'source then media type' => [['order' => 'source/mediatype'], ['a.pdf', 'b.png', 'c.pdf']],
            'invalid order' => [['order' => 'other'], ['b.png', 'c.pdf', 'a.pdf']],
            'invalid second order' => [['order' => 'title/other'], ['b.png', 'c.pdf', 'a.pdf']],
        ];
    }

    /**
     * @dataProvider explodeItemProvider
     */
    public function testExplodeItem(string $mode, array $first, array $second): void
    {
        $title = 'Explode ' . uniqid();
        $subject = $this->propertyId('dcterms:subject');
        $item = $this->createResource(['dcterms:title' => [$title], 'dcterms:subject' => ['Item subject']], 'items', [
            'o:media' => [
                ['o:ingester' => 'html', 'html' => '<p>1</p>', 'dcterms:subject' => [['type' => 'literal', 'property_id' => $subject, '@value' => 'Media 1']]],
                ['o:ingester' => 'html', 'html' => '<p>2</p>', 'dcterms:subject' => [['type' => 'literal', 'property_id' => $subject, '@value' => 'Media 2']]],
            ],
        ]);

        $this->batch([$item->id()], ['explode_item' => ['mode' => $mode]]);

        $this->assertCount(1, $this->reloaded($item->id())->media());
        $this->assertSame($first, $this->described($item->id(), 'dcterms:subject'));

        $otherIds = $this->otherItems($item->id(), 'Item subject', 'Media 2');
        $this->assertCount(1, $otherIds);
        $this->assertCount(1, $this->reloaded($otherIds[0])->media());
        $this->assertSame($second, $this->described($otherIds[0], 'dcterms:subject'));
    }

    public function explodeItemProvider(): array
    {
        return [
            'append' => ['append', ['literal:Item subject', 'literal:Media 1'], ['literal:Item subject', 'literal:Media 2']],
            'update' => ['update', ['literal:Media 1'], ['literal:Media 2']],
            'replace' => ['replace', ['literal:Media 1'], ['literal:Media 2']],
            'none' => ['none', ['literal:Item subject'], ['literal:Item subject']],
        ];
    }

    public function testExplodeItemReplaceRemovesOtherProperties(): void
    {
        $subject = $this->propertyId('dcterms:subject');
        $item = $this->createResource(['dcterms:description' => ['Item only'], 'dcterms:subject' => ['Item subject']], 'items', [
            'o:media' => [
                ['o:ingester' => 'html', 'html' => '<p>1</p>', 'dcterms:subject' => [['type' => 'literal', 'property_id' => $subject, '@value' => 'Media 1']]],
            ],
        ]);

        $this->batch([$item->id()], ['explode_item' => ['mode' => 'replace']]);

        $this->assertSame([], $this->described($item->id(), 'dcterms:description'));
        $this->assertSame(['literal:Media 1'], $this->described($item->id(), 'dcterms:subject'));
    }

    public function testExplodeItemInvalidMode(): void
    {
        $item = $this->createItemWithMedia([
            ['o:ingester' => 'html', 'html' => '<p>1</p>'],
            ['o:ingester' => 'html', 'html' => '<p>2</p>'],
        ]);

        $this->batch([$item->id()], ['explode_item' => ['mode' => 'other']]);

        $this->assertCount(2, $this->reloaded($item->id())->media());
    }

    /**
     * @dataProvider explodePdfProvider
     */
    public function testExplodePdf(array $options, array $files, array $expected): void
    {
        if (!$this->getServiceLocator()->get('Omeka\Cli')->getCommandPath('pdftoppm')) {
            $this->markTestSkipped('The command "pdftoppm" is required.');
        }
        $contents = array_map(fn ($name) => strpos($name, '.jpg') ? $this->jpeg() : $this->pdf($name), array_combine($files, $files));
        $item = $this->createItemWithFiles($contents);

        $this->batch([$item->id()], ['explode_pdf' => $options + ['process' => 'all', 'resolution' => 50, 'processor' => 'pdftoppm']]);

        $sources = array_map(fn ($media) => $media->source(), $this->reloaded($item->id())->media());
        $this->assertSame($expected, $sources);
    }

    public function explodePdfProvider(): array
    {
        return [
            'all' => [['mode' => 'all'], ['a.pdf', 'b.pdf'], ['a.pdf', 'b.pdf', 'a.0001.jpg', 'b.0001.jpg']],
            'first' => [['mode' => 'first'], ['a.pdf', 'b.pdf'], ['a.pdf', 'b.pdf', 'a.0001.jpg']],
            'last' => [['mode' => 'last'], ['a.pdf', 'b.pdf'], ['a.pdf', 'b.pdf', 'b.0001.jpg']],
            'skip existing' => [['mode' => 'all', 'process' => 'skip'], ['a.pdf', 'a.0001.jpg'], ['a.pdf', 'a.0001.jpg']],
            'all recreates existing' => [['mode' => 'all', 'process' => 'all'], ['a.pdf', 'a.0001.jpg'], ['a.pdf', 'a.0001.jpg', 'a.0001.jpg']],
            'no pdf' => [['mode' => 'all'], ['a.0001.jpg'], ['a.0001.jpg']],
        ];
    }

    public function testExplodePdfUnavailableProcessor(): void
    {
        $cli = $this->getServiceLocator()->get('Omeka\Cli');
        if ($cli->getCommandPath('gs')) {
            $this->markTestSkipped('The command "gs" should be unavailable.');
        }
        $item = $this->createItemWithFiles(['a.pdf' => $this->pdf()]);

        $this->batch([$item->id()], ['explode_pdf' => ['mode' => 'all', 'process' => 'all', 'resolution' => 50, 'processor' => 'gs']]);

        $this->assertCount(1, $this->reloaded($item->id())->media());
    }

    protected function createItemWithMedia(array $media)
    {
        return $this->createResource(['dcterms:title' => ['Kept']], 'items', ['o:media' => $media]);
    }

    /**
     * Create an item with files via the internal ingester, then other media.
     */
    protected function createItemWithFiles(array $files, array $media = [], array $titles = [])
    {
        $item = $this->createItemWithMedia($media);
        foreach ($files as $name => $content) {
            $filepath = tempnam(sys_get_temp_dir(), 'bulkedit');
            file_put_contents($filepath, $content);
            $data = [
                'o:ingester' => 'bulkedit_local',
                'o:item' => ['o:id' => $item->id()],
                'o:source' => $name,
            ];
            if (isset($titles[$name])) {
                $data['dcterms:title'] = [$this->valueData('dcterms:title', $titles[$name])];
            }
            $this->api()->create('media', $data, [], ['bulkedit_filepath' => $filepath]);
        }
        // The files are created after the media, so reorder them.
        if ($media) {
            $this->getConnection()->executeStatement('UPDATE `media` SET `position` = 99 WHERE `item_id` = ? AND `ingester` = "html"', [$item->id()]);
        }
        // The media are created separately, so refresh the list of the item.
        $entityManager = $this->getEntityManager();
        $entityManager->refresh($entityManager->find(\Omeka\Entity\Item::class, $item->id()));
        return $this->api()->read('items', $item->id())->getContent();
    }

    protected function otherItems(int $itemId, string ...$values): array
    {
        $ids = $this->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT `resource_id` FROM `value` WHERE `value` IN (?) AND `resource_id` <> ?',
            [$values, $itemId],
            [\Doctrine\DBAL\Connection::PARAM_STR_ARRAY, \PDO::PARAM_INT]
        );
        $ids = array_map('intval', $ids);
        $ids = array_values(array_intersect($ids, array_map('intval', $this->getConnection()->fetchFirstColumn('SELECT `id` FROM `item` WHERE `id` > ?', [$itemId]))));
        foreach ($ids as $id) {
            $this->createdResources[] = ['type' => 'items', 'id' => $id];
        }
        return $ids;
    }

    protected function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    protected function jpeg(): string
    {
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($image);
        return ob_get_clean();
    }

    /**
     * Get a pdf with one blank page: the content avoids identical files.
     */
    protected function pdf(string $comment = ''): string
    {
        return "%PDF-1.4\n%$comment\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
