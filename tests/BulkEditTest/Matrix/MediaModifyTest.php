<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the action "modify media", that groups the processes on media with
 * common filters.
 */
class MediaModifyTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    /**
     * The media of the ingester html have no media type, so set one.
     */
    protected function createHtmlMedia(): array
    {
        $item = $this->api()->create('items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Old text</p>']],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];
        $mediaId = $item->media()[0]->id();
        $this->getConnection()->executeStatement('UPDATE `media` SET `media_type` = "text/html" WHERE `id` = ?', [$mediaId]);
        return [$item->id(), $mediaId];
    }

    protected function media(int $id)
    {
        $this->getEntityManager()->clear();
        return $this->api()->read('media', $id)->getContent();
    }

    /**
     * @dataProvider htmlFiltersProvider
     */
    public function testHtmlWithFilters(array $filters, string $expected): void
    {
        [$itemId, $mediaId] = $this->createHtmlMedia();

        $this->batch([$itemId], ['media_modify' => $filters + [
            'media_html' => ['enabled' => '1', 'mode' => 'raw', 'from' => 'Old', 'to' => 'New', 'prepend' => '', 'append' => ''],
        ]]);

        $this->assertStringContainsString($expected, $this->media($mediaId)->mediaData()['html']);
    }

    public function htmlFiltersProvider(): array
    {
        return [
            'no filter' => [[], 'New text'],
            'ingester html' => [['ingesters' => ['html']], 'New text'],
            'other ingester' => [['ingesters' => ['upload']], 'Old text'],
            'media type' => [['media_types' => ['text/html']], 'New text'],
            'other media type' => [['media_types' => ['image/jpeg']], 'Old text'],
        ];
    }

    public function testDisabledOperationIsSkipped(): void
    {
        $item = $this->api()->create('items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Old text</p>']],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];
        $media = $item->media()[0];

        $this->batch([$item->id()], ['media_modify' => [
            'media_html' => ['enabled' => '', 'mode' => 'raw', 'from' => 'Old', 'to' => 'New', 'prepend' => '', 'append' => ''],
            'media_visibility' => ['enabled' => '0', 'visibility' => '0'],
        ]]);

        $media = $this->media($media->id());
        $this->assertStringContainsString('Old text', $media->mediaData()['html']);
        $this->assertTrue($media->isPublic());
    }

    /**
     * @dataProvider visibilityAndTypeFiltersProvider
     */
    public function testVisibilityAndTypeWithFilters(array $filters, bool $isPublic, string $mediaType): void
    {
        [$itemId, $mediaId] = $this->createHtmlMedia();

        $this->batch([$itemId], ['media_modify' => $filters + [
            'media_type' => ['enabled' => '1', 'from' => 'text/html', 'to' => 'text/plain'],
            'media_visibility' => ['enabled' => '1', 'visibility' => '0'],
        ]]);

        $media = $this->media($mediaId);
        $this->assertSame($isPublic, $media->isPublic());
        $this->assertSame($mediaType, $media->mediaType());
    }

    public function visibilityAndTypeFiltersProvider(): array
    {
        return [
            'no filter' => [[], false, 'text/plain'],
            'renderer html' => [['renderers' => ['html']], false, 'text/plain'],
            'other renderer' => [['renderers' => ['file']], true, 'text/html'],
        ];
    }
}
