<?php declare(strict_types=1);

namespace BulkEditTest;

use Omeka\Entity\User;
use Omeka\Stdlib\HtmlPurifier;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Test the safety of the processes run after the batch update.
 */
class PostProcessSafetyTest extends AbstractHttpControllerTestCase
{
    use BulkEditTestTrait;

    /**
     * @var int|null
     */
    protected $userId;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    public function tearDown(): void
    {
        $this->loginAdmin();
        $this->cleanupResources();
        if ($this->userId) {
            $this->api()->delete('users', $this->userId);
        }
        $this->logout();
        parent::tearDown();
    }

    public function testMediaHtmlIsPurified(): void
    {
        $services = $this->getServiceLocator();
        $services->setAllowOverride(true);
        $services->setService('Omeka\HtmlPurifier', new HtmlPurifier(true));
        $services->setAllowOverride(false);

        $item = $this->api()->create('items', [
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Old text</p>']],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $this->api()->batchUpdate('items', [$item->id()], [
            'bulkedit' => [
                'media_html' => [
                    'mode' => 'raw',
                    'from' => 'Old',
                    'to' => 'New<script>alert(1)</script>',
                ],
            ],
        ], ['continueOnError' => true, 'collectionAction' => 'replace']);

        $html = $this->refreshItem($item->id())->media()[0]->mediaData()['html'];
        $this->assertStringContainsString('New', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    /**
     * The resources that cannot be updated are skipped by the core, so they
     * should be skipped by the post processes too.
     */
    public function testPostProcessSkipsResourcesNotUpdated(): void
    {
        $own = $this->createItem(['dcterms:title' => [['@value' => 'Admin item']]]);

        $email = 'bulkedit-author-' . uniqid() . '@example.com';
        $this->userId = $this->api()->create('users', [
            'o:email' => $email,
            'o:name' => 'Author',
            'o:role' => 'author',
            'o:is_active' => true,
        ])->getContent()->id();
        $this->loginAs($this->userId);
        $authorItem = $this->api()->create('items', [
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => 'Author item']],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $authorItem->id()];

        $this->api()->batchUpdate('items', [$own->id(), $authorItem->id()], [
            'bulkedit' => [
                'cleaning' => [
                    'clean_language_codes' => '1',
                    'clean_language_codes_from' => '',
                    'clean_language_codes_to' => 'fr',
                    'clean_language_codes_properties' => ['dcterms:title'],
                ],
            ],
        ], ['continueOnError' => true, 'collectionAction' => 'replace']);

        $connection = $this->getConnection();
        $langs = $connection->fetchAllKeyValue(
            'SELECT `resource_id`, `lang` FROM `value` WHERE `resource_id` IN (?, ?) AND `property_id` = 1',
            [$own->id(), $authorItem->id()]
        );
        $this->assertSame('fr', $langs[$authorItem->id()]);
        $this->assertNull($langs[$own->id()], 'The item of another user should not be updated.');
    }

    public function testTrimValuesRemovesAnnotationsOfEmptyValues(): void
    {
        $item = $this->api()->create('items', [
            'dcterms:title' => [['type' => 'literal', 'property_id' => 1, '@value' => 'Kept']],
            'dcterms:subject' => [[
                'type' => 'literal',
                'property_id' => 3,
                '@value' => 'Emptied',
                '@annotation' => [
                    'dcterms:description' => [['type' => 'literal', 'property_id' => 4, '@value' => 'Note']],
                ],
            ]],
        ])->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        $connection = $this->getConnection();
        $annotationId = (int) $connection->fetchOne(
            'SELECT `value_annotation_id` FROM `value` WHERE `resource_id` = ? AND `property_id` = 3',
            [$item->id()]
        );
        $this->assertNotEmpty($annotationId);
        $connection->executeStatement(
            'UPDATE `value` SET `value` = "   " WHERE `resource_id` = ? AND `property_id` = 3',
            [$item->id()]
        );

        $this->getServiceLocator()->get('ControllerPluginManager')->get('trimValues')([$item->id()]);

        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `value` WHERE `resource_id` = ? AND `property_id` = 3', [$item->id()]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `resource` WHERE `id` = ?', [$annotationId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM `value` WHERE `resource_id` = ?', [$annotationId]));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM `value` WHERE `resource_id` = ?', [$item->id()]));
    }

    protected function loginAs(int $userId): void
    {
        $services = $this->getServiceLocator();
        $user = $services->get('Omeka\EntityManager')->find(User::class, $userId);
        $services->get('Omeka\AuthenticationService')->getStorage()->write($user);
    }
}
