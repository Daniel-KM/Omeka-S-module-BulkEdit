<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use Omeka\Entity\Job;
use Omeka\Job\BatchUpdate;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the processes run in background and the batches by chunks.
 */
class BackgroundTest extends AbstractHttpControllerTestCase
{
    use MatrixTestTrait;

    public function testExplodeItemOnSeveralChunks(): void
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('batch_chunk_size', 1);
        $items = [];
        foreach ([1, 2, 3] as $number) {
            $items[] = $this->createResource(['dcterms:title' => ["Chunk $number"]], 'items', [
                'o:media' => [
                    ['o:ingester' => 'html', 'html' => '<p>1</p>'],
                    ['o:ingester' => 'html', 'html' => '<p>2</p>'],
                ],
            ]);
        }

        try {
            $job = $this->runJob(BatchUpdate::class, [
                'resource' => 'items',
                'query' => ['id' => array_map(fn ($item) => $item->id(), $items)],
                'data' => ['bulkedit' => ['explode_item' => ['mode' => 'none']]],
                'data_remove' => [],
                'data_append' => [],
            ]);
        } finally {
            $settings->delete('batch_chunk_size');
        }

        $this->assertSame(Job::STATUS_COMPLETED, $job->getStatus());
        foreach ($items as $item) {
            $this->assertCount(1, $this->reloaded($item->id())->media());
        }
        $connection = $this->getConnection();
        $ids = $connection->fetchFirstColumn('SELECT `id` FROM `item` WHERE `id` > ?', [end($items)->id()]);
        foreach ($ids as $id) {
            $this->createdResources[] = ['type' => 'items', 'id' => (int) $id];
        }
        $this->assertCount(3, $ids, 'One new item should be created for each chunk.');
    }

    public function testExplodePdfOnSeveralChunks(): void
    {
        if (!$this->getServiceLocator()->get('Omeka\Cli')->getCommandPath('pdftoppm')) {
            $this->markTestSkipped('The command "pdftoppm" is required.');
        }
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $settings->set('batch_chunk_size', 1);
        $ids = [];
        foreach ([1, 2, 3] as $number) {
            $item = $this->createResource(['dcterms:title' => ["Pdf $number"]]);
            $filepath = tempnam(sys_get_temp_dir(), 'bulkedit');
            file_put_contents($filepath, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 72 72]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
            $this->api()->create('media', ['o:ingester' => 'bulkedit_local', 'o:item' => ['o:id' => $item->id()], 'o:source' => 'sample.pdf'], [], ['bulkedit_filepath' => $filepath]);
            $ids[] = $item->id();
        }

        try {
            $this->runJob(BatchUpdate::class, [
                'resource' => 'items',
                'query' => ['id' => $ids],
                'data' => ['bulkedit' => ['explode_pdf' => ['mode' => 'all', 'process' => 'all', 'resolution' => 50, 'processor' => 'pdftoppm']]],
                'data_remove' => [],
                'data_append' => [],
            ]);
        } finally {
            $settings->delete('batch_chunk_size');
        }

        foreach ($ids as $id) {
            $this->assertCount(2, $this->reloaded($id)->media(), "Item #$id");
        }
    }

    /**
     * @dataProvider slowProcessesProvider
     */
    public function testSlowProcessesAreDispatchedFromController(array $bulkedit, array $background, string $resourceName = 'items'): void
    {
        $id = $this->resourceId($resourceName);
        $dispatcher = $this->recordDispatches();

        $this->api()->batchUpdate($resourceName, [$id], ['bulkedit' => $bulkedit], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
            'detachEntities' => false,
        ]);

        if (!$background) {
            $this->assertSame([], $dispatcher->dispatched);
            return;
        }
        $this->assertCount(1, $dispatcher->dispatched);
        [$class, $args] = $dispatcher->dispatched[0];
        $this->assertSame(BatchUpdate::class, $class);
        $this->assertSame($resourceName, $args['resource']);
        $this->assertSame(['id' => [$id]], $args['query']);
        $this->assertSame($background, array_keys($args['data']['bulkedit']));
    }

    public function slowProcessesProvider(): array
    {
        $html = ['mode' => 'raw', 'from' => 'a', 'to' => 'b', 'prepend' => '', 'append' => ''];
        $source = $html;
        $visibility = ['visibility' => '0', 'media_types' => [], 'ingesters' => [], 'renderers' => []];
        $fill = fn (string $mode) => ['mode' => $mode, 'properties' => ['dcterms:subject'], 'datatypes' => ['valuesuggest:idref:person'], 'datatype' => 'valuesuggest:idref:person', 'language' => '', 'update_language' => 'keep'];
        $convert = fn (string $from, string $to, string $literal = 'label') => [
            'from' => $from, 'to' => $to, 'properties' => ['dcterms:subject'], 'literal_value' => $literal,
            'literal_extract_html_text' => false, 'literal_html_only_tagged_string' => false,
            'resource_value_preprocess' => '', 'resource_properties' => [], 'uri_extract_label' => false,
            'uri_label' => '', 'uri_language' => '', 'uri_base_site' => '', 'contains' => '',
        ];
        $replace = ['value_part' => 'value', 'mode' => 'raw', 'from' => 'a', 'to' => 'b', 'prepend' => '', 'append' => '', 'language' => '', 'language_clear' => false, 'properties' => ['dcterms:subject']];
        return [
            'explode item' => [['explode_item' => ['mode' => 'none']], ['explode_item']],
            'explode item without mode' => [['explode_item' => ['mode' => '']], []],
            'explode pdf' => [['explode_pdf' => ['mode' => 'all', 'resolution' => 50]], ['explode_pdf']],
            'fill labels' => [['fill_values' => $fill('label_all')], ['fill_values']],
            'fill uris' => [['fill_values' => $fill('uri_missing')], ['fill_values']],
            'remove labels is local' => [['fill_values' => $fill('label_remove')], []],
            'convert to coordinates' => [['convert' => $convert('uri', 'literal', 'coordinates')], ['convert']],
            'convert to place' => [['convert' => $convert('uri', 'place')], ['convert']],
            'convert to geography' => [['convert' => $convert('literal', 'geography:coordinates')], ['convert']],
            'convert is local' => [['convert' => $convert('literal', 'uri')], []],
            'media remove' => [['media_remove' => ['mode' => 'all', 'extensions' => []]], ['media_remove']],
            'media order' => [['media_order' => ['order' => 'title', 'extensions' => []]], ['media_order']],
            'media html from items' => [['media_html' => $html], ['media_html']],
            'media source from items' => [['media_source' => $source], ['media_source']],
            'media type from items' => [['media_type' => ['from' => 'image/png', 'to' => 'image/x-png']], ['media_type']],
            'media visibility from items' => [['media_visibility' => $visibility], ['media_visibility']],
            'media html from media' => [['media_html' => $html], [], 'media'],
            'media visibility from media' => [['media_visibility' => $visibility], [], 'media'],
            'replace is quick' => [['replace' => $replace], []],
            'cleaning is quick' => [['cleaning' => ['trim_values' => '1']], []],
        ];
    }

    public function testQuickProcessesAreRunAndSlowOnesAreNot(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Old']], 'items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>1</p>'], ['o:ingester' => 'html', 'html' => '<p>2</p>']],
        ]);
        $dispatcher = $this->recordDispatches();

        $this->api()->batchUpdate('items', [$item->id()], ['bulkedit' => [
            'replace' => ['value_part' => 'value', 'mode' => 'raw', 'from' => 'Old', 'to' => 'New', 'prepend' => '', 'append' => '', 'language' => '', 'language_clear' => false, 'properties' => ['dcterms:title']],
            'explode_item' => ['mode' => 'none'],
        ]], ['continueOnError' => true, 'collectionAction' => 'replace', 'detachEntities' => false]);

        $this->assertSame(['literal:New'], $this->described($item->id(), 'dcterms:title'));
        $this->assertCount(2, $this->reloaded($item->id())->media(), 'The item should not be exploded during the request.');
        $this->assertSame(['explode_item'], array_keys($dispatcher->dispatched[0][1]['data']['bulkedit']));
    }

    public function testJobsDoNotDispatchSlowProcesses(): void
    {
        $item = $this->createResource(['dcterms:title' => ['Old']], 'items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>1</p>'], ['o:ingester' => 'html', 'html' => '<p>2</p>']],
        ]);
        $dispatcher = $this->recordDispatches();

        // A job does not set the option "detachEntities".
        $this->batch([$item->id()], ['explode_item' => ['mode' => 'none']]);

        $this->assertSame([], $dispatcher->dispatched);
        $this->assertCount(1, $this->reloaded($item->id())->media());
        $ids = $this->getConnection()->fetchFirstColumn('SELECT `id` FROM `item` WHERE `id` > ?', [$item->id()]);
        foreach ($ids as $id) {
            $this->createdResources[] = ['type' => 'items', 'id' => (int) $id];
        }
    }

    protected function resourceId(string $resourceName): int
    {
        $item = $this->createResource(['dcterms:title' => ['Kept']], 'items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>a</p>']],
        ]);
        return $resourceName === 'media' ? $item->media()[0]->id() : $item->id();
    }

    /**
     * Replace the job dispatcher, else a real background job would be launched
     * on the main database.
     */
    protected function recordDispatches()
    {
        $dispatcher = new class extends \Omeka\Job\Dispatcher {
            public $dispatched = [];

            public function __construct()
            {
            }

            public function dispatch($class, $args = null, ?\Omeka\Job\DispatchStrategy\StrategyInterface $strategy = null)
            {
                $this->dispatched[] = [$class, $args];
                $job = new Job();
                $property = new \ReflectionProperty(Job::class, 'id');
                $property->setAccessible(true);
                $property->setValue($job, 1);
                return $job;
            }
        };
        $services = $this->getServiceLocator();
        $services->setAllowOverride(true);
        $services->setService('Omeka\Job\Dispatcher', $dispatcher);
        $services->setAllowOverride(false);
        return $dispatcher;
    }
}
