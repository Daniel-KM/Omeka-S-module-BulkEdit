<?php declare(strict_types=1);

namespace BulkEditTest;

use BulkEdit\Stdlib\BulkEdit;
use Omeka\Api\Exception\ValidationException;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the processes on media and the filling of uris and labels.
 */
class MediaAndFillTest extends AbstractHttpControllerTestCase
{
    use BulkEditTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testExplodePdfCreatesMediaFromLocalFiles(): void
    {
        if (!$this->getServiceLocator()->get('Omeka\Cli')->getCommandPath('pdftoppm')) {
            $this->markTestSkipped('The command "pdftoppm" is required.');
        }

        $itemId = $this->createItemWithPdf();
        $tmpDir = $this->getServiceLocator()->get('Config')['temp_dir'] . '/bulkedit/' . $itemId;

        $this->batch([$itemId], [
            'explode_pdf' => ['mode' => 'all', 'process' => 'all', 'resolution' => 50, 'processor' => 'pdftoppm'],
        ]);

        $medias = $this->refreshItem($itemId)->media();
        $this->assertCount(2, $medias);
        $this->assertSame('image/jpeg', $medias[1]->mediaType());
        $this->assertSame('sample.0001.jpg', $medias[1]->source());
        $this->assertSame('upload', $medias[1]->ingester(), 'The media should not depend on the module.');
        $this->assertFileDoesNotExist($tmpDir);
    }

    public function testLocalIngesterRequiresInternalOption(): void
    {
        $this->expectException(ValidationException::class);
        $this->api()->create('items', [
            'o:media' => [['o:ingester' => 'bulkedit_local', 'o:source' => '/etc/passwd']],
        ]);
    }

    public function testLocalIngesterIsHidden(): void
    {
        $names = $this->getServiceLocator()->get('Omeka\Media\Ingester\Manager')->getRegisteredNames();
        $this->assertNotContains('bulkedit_local', $names);
    }

    public function testMediaHtmlRemoveWithoutString(): void
    {
        $item = $this->createItemWithHtml();

        $this->batch([$item->id()], ['media_html' => ['mode' => 'remove']]);

        $this->assertSame('', $this->refreshItem($item->id())->media()[0]->mediaData()['html']);
    }

    public function testMediaSourceBasenameWithoutString(): void
    {
        $item = $this->createItemWithHtml('https://example.org/path/file.jpg');

        $this->batch([$item->id()], ['media_source' => ['mode' => 'basename']]);

        $this->assertSame('file.jpg', $this->refreshItem($item->id())->media()[0]->source());
    }

    public function testFillUriSearchesLabelNotUri(): void
    {
        $this->requireValueSuggest();
        $item = $this->createItem([
            'dcterms:subject' => [
                ['type' => 'valuesuggest:idref:person', '@id' => 'https://www.idref.fr/old', 'o:label' => 'Victor Hugo'],
            ],
        ]);
        $data = json_decode(json_encode($item), true);
        $bulkEdit = $this->bulkEditStub();

        $bulkEdit->fillValuesForResource($item, $data, $this->fillParams('uri_all', 'valuesuggest:idref:person'));

        $this->assertSame(['Victor Hugo'], $bulkEdit->labels);
        $this->assertSame('https://www.idref.fr/new', $data['dcterms:subject'][0]['@id']);
        $this->assertSame('Victor Hugo', $data['dcterms:subject'][0]['o:label']);
    }

    public function testFillLabelWithSeveralDataTypes(): void
    {
        $this->requireValueSuggest();
        $item = $this->createItem([
            'dcterms:subject' => [
                ['type' => 'valuesuggest:idref:person', '@id' => 'https://www.idref.fr/a'],
                ['type' => 'valuesuggest:idref:subject', '@id' => 'https://www.idref.fr/b'],
            ],
        ]);
        $data = json_decode(json_encode($item), true);
        $bulkEdit = $this->bulkEditStub();

        $bulkEdit->fillValuesForResource($item, $data, $this->fillParams(
            'label_all',
            null,
            ['valuesuggest:idref:person', 'valuesuggest:idref:subject']
        ));

        $this->assertSame('Label', $data['dcterms:subject'][0]['o:label']);
        $this->assertSame('Label', $data['dcterms:subject'][1]['o:label']);
    }

    protected function requireValueSuggest(): void
    {
        if (!$this->getServiceLocator()->get('Omeka\DataTypeManager')->has('valuesuggest:idref:person')) {
            $this->markTestSkipped('The module Value Suggest is required.');
        }
    }

    /**
     * Get the service without remote requests.
     */
    protected function bulkEditStub(): BulkEdit
    {
        return new class($this->getServiceLocator()) extends BulkEdit {
            public $labels = [];

            protected function getValueSuggestUriForLabel(string $label, string $dataType, ?string $language = null): ?string
            {
                $this->labels[] = $label;
                return 'https://www.idref.fr/new';
            }

            protected function getLabelForUri(string $uri, string $dataType, array $options = []): ?string
            {
                return 'Label';
            }
        };
    }

    protected function fillParams(string $mode, ?string $dataType, ?array $dataTypes = null): array
    {
        return [
            'mode' => $mode,
            'properties' => ['dcterms:subject'],
            'datatypes' => $dataTypes ?? [$dataType],
            'datatype' => $dataType,
            'language' => '',
            'update_language' => 'keep',
            'featured_subject' => false,
        ];
    }

    protected function batch(array $ids, array $bulkedit): void
    {
        $this->api()->batchUpdate('items', $ids, ['bulkedit' => $bulkedit], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);
    }

    protected function createItemWithHtml(?string $source = null)
    {
        $item = $this->api()->create('items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Text</p>', 'o:source' => $source]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];
        return $item;
    }

    protected function createItemWithPdf(): int
    {
        $item = $this->api()->create('items', [])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $filepath = tempnam(sys_get_temp_dir(), 'bulkedit');
        file_put_contents($filepath, $this->minimalPdf());
        $this->api()->create('media', [
            'o:ingester' => 'bulkedit_local',
            'o:item' => ['o:id' => $item->id()],
            'o:source' => 'sample.pdf',
        ], [], ['bulkedit_filepath' => $filepath]);
        $this->getEntityManager()->clear();
        $this->loginAdmin();

        return $item->id();
    }

    /**
     * Get a pdf with one blank page: readers rebuild the missing xref table.
     */
    protected function minimalPdf(): string
    {
        return "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF\n";
    }
}
