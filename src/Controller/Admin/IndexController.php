<?php declare(strict_types=1);

namespace BulkEdit\Controller\Admin;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Omeka\Form\ResourceBatchUpdateForm;

class IndexController extends AbstractActionController
{
    /**
     * @var \BulkEdit\Module
     */
    protected $module;

    public function __construct(\BulkEdit\Module $module)
    {
        $this->module = $module;
    }

    /**
     * Preview the values updated by the batch edit form, without saving.
     */
    public function previewAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->jsonError('The preview requires a form.'); // @translate
        }

        $resourceTypes = [
            'item' => ['items', 'item'],
            'item-set' => ['item_sets', 'itemSet'],
            'media' => ['media', 'media'],
        ];
        $post = $this->params()->fromPost();
        [$resourceName, $formResourceType] = $resourceTypes[$post['resource_controller'] ?? ''] ?? $resourceTypes['item'];

        // Use the form to get the same data than the batch update.
        $form = $this->getForm(ResourceBatchUpdateForm::class, ['resource_type' => $formResourceType]);
        $form->setData($post);
        if (!$form->isValid()) {
            return $this->jsonError('The form is invalid.'); // @translate
        }
        $bulkedit = $form->getData()['bulkedit'] ?? [];

        $ids = array_values(array_filter(array_map('intval', (array) ($post['resource_ids'] ?? []))));
        if (!$ids) {
            $query = json_decode((string) ($post['query'] ?? ''), true) ?: [];
            $ids = array_map('intval', $this->api()->search($resourceName, $query, ['returnScalar' => 'id'])->getContent());
        }

        return new JsonModel([
            'status' => 'success',
            'data' => $this->module->previewBulkEdit($resourceName, $ids, $bulkedit, 10, max(0, (int) ($post['preview_start'] ?? 0))),
        ]);
    }

    protected function jsonError(string $message): JsonModel
    {
        $this->getResponse()->setStatusCode(400);
        return new JsonModel([
            'status' => 'error',
            'message' => $this->translate($message),
        ]);
    }
}
