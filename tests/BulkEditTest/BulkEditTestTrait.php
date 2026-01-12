<?php declare(strict_types=1);

namespace BulkEditTest;

use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Entity\Job;
use Omeka\Job\BatchUpdate;

/**
 * Shared test helpers for BulkEdit module tests.
 */
trait BulkEditTestTrait
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var array List of created resource IDs for cleanup.
     */
    protected $createdResources = [];

    /**
     * @var \Exception|null Last exception from job execution.
     */
    protected $lastJobException;

    /**
     * Get the API manager.
     */
    protected function api(): ApiManager
    {
        return $this->getServiceLocator()->get('Omeka\ApiManager');
    }

    /**
     * Get the service locator.
     */
    protected function getServiceLocator(): ServiceLocatorInterface
    {
        if ($this->services === null) {
            $this->services = $this->getApplication()->getServiceManager();
        }
        return $this->services;
    }

    /**
     * Get the entity manager.
     */
    protected function getEntityManager()
    {
        return $this->getServiceLocator()->get('Omeka\EntityManager');
    }

    /**
     * Get the database connection.
     */
    protected function getConnection()
    {
        return $this->getServiceLocator()->get('Omeka\Connection');
    }

    /**
     * Login as admin user.
     */
    protected function loginAdmin(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }

    /**
     * Logout current user.
     */
    protected function logout(): void
    {
        $auth = $this->getServiceLocator()->get('Omeka\AuthenticationService');
        $auth->clearIdentity();
    }

    /**
     * Create a test item.
     *
     * @param array $data Item data with property terms as keys.
     * @return ItemRepresentation
     */
    protected function createItem(array $data): ItemRepresentation
    {
        $itemData = [];
        $easyMeta = $this->getServiceLocator()->get('Common\EasyMeta');

        foreach ($data as $term => $values) {
            if (strpos($term, ':') === false) {
                $itemData[$term] = $values;
                continue;
            }

            $propertyId = $easyMeta->propertyId($term);
            if (!$propertyId) {
                continue;
            }

            $itemData[$term] = [];
            foreach ($values as $value) {
                $valueData = [
                    'type' => $value['type'] ?? 'literal',
                    'property_id' => $propertyId,
                ];
                if (isset($value['@value'])) {
                    $valueData['@value'] = $value['@value'];
                }
                if (isset($value['@id'])) {
                    $valueData['@id'] = $value['@id'];
                }
                if (isset($value['@language'])) {
                    $valueData['@language'] = $value['@language'];
                }
                if (isset($value['o:label'])) {
                    $valueData['o:label'] = $value['o:label'];
                }
                if (isset($value['is_public'])) {
                    $valueData['is_public'] = $value['is_public'];
                }
                $itemData[$term][] = $valueData;
            }
        }

        $response = $this->api()->create('items', $itemData);
        $item = $response->getContent();
        $this->createdResources[] = ['type' => 'items', 'id' => $item->id()];

        return $item;
    }

    /**
     * Create multiple test items with a specific value pattern.
     *
     * @param int $count Number of items to create.
     * @param string $valuePrefix Prefix for the title value.
     * @param string $searchableValue Value to include in dcterms:description for search/replace testing.
     * @return array List of created items.
     */
    protected function createItemsWithValue(int $count, string $valuePrefix = 'Item', string $searchableValue = 'SEARCH_ME'): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = $this->createItem([
                'dcterms:title' => [
                    ['type' => 'literal', '@value' => "$valuePrefix $i"],
                ],
                'dcterms:description' => [
                    ['type' => 'literal', '@value' => "Description with $searchableValue for item $i"],
                ],
            ]);
        }
        return $items;
    }

    /**
     * Run a job synchronously for testing.
     *
     * @param string $jobClass Job class name.
     * @param array $args Job arguments.
     * @param bool $expectError If true, don't rethrow exceptions.
     * @return Job
     */
    protected function runJob(string $jobClass, array $args, bool $expectError = false): Job
    {
        $this->lastJobException = null;
        $services = $this->getServiceLocator();
        $entityManager = $services->get('Omeka\EntityManager');
        $auth = $services->get('Omeka\AuthenticationService');

        // Clear the entity manager to ensure a fresh state.
        $entityManager->clear();

        $job = new Job();
        $job->setStatus(Job::STATUS_STARTING);
        $job->setClass($jobClass);
        $job->setArgs($args);

        // Re-fetch the user from database since EM was cleared.
        if ($auth->hasIdentity()) {
            $identity = $auth->getIdentity();
            $userRepo = $entityManager->getRepository(\Omeka\Entity\User::class);
            $user = $userRepo->find($identity->getId());
            if ($user) {
                $job->setOwner($user);
            }
        }

        $entityManager->persist($job);
        $entityManager->flush();

        $jobClass = $job->getClass();
        $jobInstance = new $jobClass($job, $services);
        $job->setStatus(Job::STATUS_IN_PROGRESS);
        $job->setStarted(new \DateTime('now'));
        $entityManager->flush();

        try {
            $jobInstance->perform();
            if ($job->getStatus() === Job::STATUS_IN_PROGRESS) {
                $job->setStatus(Job::STATUS_COMPLETED);
            }
        } catch (\Throwable $e) {
            $this->lastJobException = $e;
            $job->setStatus(Job::STATUS_ERROR);
            if (!$expectError) {
                throw $e;
            }
        }

        $job->setEnded(new \DateTime('now'));
        $entityManager->flush();

        return $job;
    }

    /**
     * Run a batch update job with string replacement.
     *
     * This simulates the batch-edit-all action from the admin controller.
     *
     * @param array $query Query to select items.
     * @param string $from String to search for.
     * @param string $to String to replace with.
     * @param string $mode Replacement mode (raw, raw_i, regex, etc.).
     * @param array $properties Properties to search in (default: all).
     * @return Job
     */
    protected function runBatchReplace(
        array $query,
        string $from,
        string $to,
        string $mode = 'raw',
        array $properties = ['all']
    ): Job {
        $data = [
            'bulkedit' => [
                'replace' => [
                    'value_part' => 'value',
                    'mode' => $mode,
                    'from' => $from,
                    'to' => $to,
                    'prepend' => '',
                    'append' => '',
                    'language' => '',
                    'language_clear' => false,
                    'properties' => $properties,
                ],
            ],
        ];

        return $this->runJob(BatchUpdate::class, [
            'resource' => 'items',
            'query' => $query,
            'data' => $data,
            'data_remove' => [],
            'data_append' => [],
        ]);
    }

    /**
     * Get the last exception from job execution.
     */
    protected function getLastJobException(): ?\Throwable
    {
        return $this->lastJobException;
    }

    /**
     * Refresh an item from the database.
     *
     * @param int $itemId Item ID.
     * @return ItemRepresentation|null
     */
    protected function refreshItem(int $itemId): ?ItemRepresentation
    {
        // Clear entity manager cache to ensure fresh data.
        $this->getEntityManager()->clear();

        try {
            return $this->api()->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get item value for a property.
     *
     * @param ItemRepresentation $item Item representation.
     * @param string $term Property term (e.g., 'dcterms:description').
     * @return string|null First value or null.
     */
    protected function getItemValue(ItemRepresentation $item, string $term): ?string
    {
        $values = $item->value($term, ['all' => true]);
        if (empty($values)) {
            return null;
        }
        return (string) $values[0];
    }

    /**
     * Assert that all items have a specific value replaced.
     *
     * @param array $itemIds List of item IDs to check.
     * @param string $term Property term to check.
     * @param string $expectedContains String that should be in the value.
     * @param string $notExpectedContains String that should NOT be in the value.
     */
    protected function assertAllItemsReplaced(
        array $itemIds,
        string $term,
        string $expectedContains,
        string $notExpectedContains
    ): void {
        $failed = [];
        $notReplaced = [];

        foreach ($itemIds as $index => $itemId) {
            $item = $this->refreshItem($itemId);
            if (!$item) {
                $failed[] = $itemId;
                continue;
            }

            $value = $this->getItemValue($item, $term);
            if ($value === null) {
                $failed[] = $itemId;
                continue;
            }

            if (strpos($value, $notExpectedContains) !== false) {
                $notReplaced[] = [
                    'id' => $itemId,
                    'index' => $index + 1,
                    'value' => $value,
                ];
            }

            if (strpos($value, $expectedContains) === false) {
                $notReplaced[] = [
                    'id' => $itemId,
                    'index' => $index + 1,
                    'value' => $value,
                    'reason' => 'missing expected value',
                ];
            }
        }

        $this->assertEmpty($failed, 'Failed to load items: ' . implode(', ', $failed));

        if (!empty($notReplaced)) {
            $details = array_map(function ($item) {
                return sprintf(
                    "Item #%d (index %d): %s",
                    $item['id'],
                    $item['index'],
                    $item['value']
                );
            }, $notReplaced);
            $this->fail(
                sprintf(
                    "String replacement failed for %d items. Expected '%s' to be replaced with '%s'.\nFailed items:\n%s",
                    count($notReplaced),
                    $notExpectedContains,
                    $expectedContains,
                    implode("\n", $details)
                )
            );
        }
    }

    /**
     * Clean up created resources after test.
     */
    protected function cleanupResources(): void
    {
        foreach ($this->createdResources as $resource) {
            try {
                $this->api()->delete($resource['type'], $resource['id']);
            } catch (\Exception $e) {
                // Ignore errors during cleanup.
            }
        }
        $this->createdResources = [];
    }
}
