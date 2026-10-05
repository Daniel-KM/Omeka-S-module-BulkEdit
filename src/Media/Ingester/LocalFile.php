<?php declare(strict_types=1);

namespace BulkEdit\Media\Ingester;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

/**
 * Internal ingester to create a media from a local file of the server.
 *
 * The filepath is an option of the request, so only the internal code can set
 * it, not an api client. The media is stored as an uploaded file, so it does
 * not depend on this module.
 */
class LocalFile implements IngesterInterface
{
    /**
     * @var \Omeka\File\TempFileFactory
     */
    protected $tempFileFactory;

    public function __construct(TempFileFactory $tempFileFactory)
    {
        $this->tempFileFactory = $tempFileFactory;
    }

    public function getLabel()
    {
        return 'Local file'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $filepath = $request->getOption('bulkedit_filepath');
        if (!$filepath || !is_file($filepath) || !is_readable($filepath)) {
            $errorStore->addError('o:ingester', 'The local file is not available.'); // @translate
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        if (!rename($filepath, $tempFile->getTempPath())) {
            $tempFile->delete();
            $errorStore->addError('o:ingester', 'The local file cannot be moved to the temp directory.'); // @translate
            return;
        }

        $tempFile->setSourceName($request->getValue('o:source') ?: basename($filepath));
        $media->setIngester('upload');
        $tempFile->mediaIngestFile($media, $request, $errorStore);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return '';
    }
}
