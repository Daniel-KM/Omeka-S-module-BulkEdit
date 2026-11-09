<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the processes applied when a resource is saved.
 */
class SaveTest extends AbstractHttpControllerTestCase
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

    public function testGenericResourceTypeIsSpecified(): void
    {
        $itemSet = $this->api()->create('item_sets', [])->getContent();
        $this->createdResources[] = ['type' => 'item_sets', 'id' => $itemSet->id()];
        $linked = $this->createItem(['dcterms:title' => [['@value' => 'Linked']]]);
        $subject = $this->getServiceLocator()->get('Common\EasyMeta')->propertyId('dcterms:subject');

        $item = $this->api()->create('items', [
            'dcterms:subject' => [
                ['type' => 'resource', 'property_id' => $subject, 'value_resource_id' => $linked->id()],
                ['type' => 'resource', 'property_id' => $subject, 'value_resource_id' => $itemSet->id()],
                ['type' => 'resource:item', 'property_id' => $subject, 'value_resource_id' => $linked->id()],
            ],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $types = $this->getConnection()->fetchFirstColumn(
            'SELECT `type` FROM `value` WHERE `resource_id` = ? ORDER BY `id`',
            [$item->id()]
        );
        $this->assertSame(['resource:item', 'resource:itemset'], array_values(array_unique($types)));
        $this->assertNotContains('resource', $types);
    }

    public function testUnknownLinkedResourceKeepsGenericType(): void
    {
        $subject = $this->getServiceLocator()->get('Common\EasyMeta')->propertyId('dcterms:subject');
        $data = ['dcterms:subject' => [['type' => 'resource', 'property_id' => $subject, 'value_resource_id' => 99999999]]];
        $request = new \Omeka\Api\Request('create', 'items');
        $request->setContent($data);
        $event = new \Laminas\EventManager\Event('api.create.pre', null, ['request' => $request]);

        $module = new \BulkEdit\Module();
        $module->setServiceLocator($this->getServiceLocator());
        $module->handleResourceProcessPre($event);

        $this->assertSame('resource', $request->getContent()['dcterms:subject'][0]['type']);
    }
}
