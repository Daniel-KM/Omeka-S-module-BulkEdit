<?php declare(strict_types=1);

namespace BulkEditTest\Matrix;

use BulkEditTest\BulkEditTestTrait;

/**
 * Helpers to test the processes with lists of values and options.
 *
 * A value to create is a string (literal) or an array:
 * - ['literal', 'value', 'language', isPublic]
 * - ['uri', 'uri', 'label', 'language', isPublic]
 * - ['link', 'title of a new linked item', isPublic]
 *
 * A value is described as a compact string, with "!" when it is private:
 * "literal:value@language", "uri:uri|label@language", "link:title".
 */
trait MatrixTestTrait
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

    protected function propertyId(string $term): int
    {
        return $this->getServiceLocator()->get('Common\EasyMeta')->propertyId($term);
    }

    protected function valueData(string $term, $spec): array
    {
        $spec = is_string($spec) ? ['literal', $spec] : $spec;
        $propertyId = $this->propertyId($term);
        switch ($spec[0]) {
            case 'uri':
                return array_filter([
                    'type' => 'uri',
                    'property_id' => $propertyId,
                    '@id' => $spec[1],
                    'o:label' => $spec[2] ?? null,
                    'o:lang' => $spec[3] ?? null,
                ], fn ($v) => $v !== null && $v !== '') + ['is_public' => $spec[4] ?? true];
            case 'link':
                $linked = $this->createItem(['dcterms:title' => [['@value' => $spec[1]]]]);
                return [
                    'type' => 'resource:item',
                    'property_id' => $propertyId,
                    'value_resource_id' => $linked->id(),
                    'is_public' => $spec[2] ?? true,
                ];
            case 'literal':
            default:
                return array_filter([
                    'type' => 'literal',
                    'property_id' => $propertyId,
                    '@value' => $spec[1],
                    '@language' => $spec[2] ?? null,
                ], fn ($v) => $v !== null && $v !== '') + ['is_public' => $spec[3] ?? true];
        }
    }

    protected function resourceData(array $valuesByTerm): array
    {
        $data = [];
        foreach ($valuesByTerm as $term => $specs) {
            $data[$term] = array_map(fn ($spec) => $this->valueData($term, $spec), $specs);
        }
        return $data;
    }

    protected function createResource(array $valuesByTerm, string $resourceName = 'items', array $extra = [])
    {
        $resource = $this->api()->create($resourceName, $this->resourceData($valuesByTerm) + $extra)->getContent();
        $this->createdResources[] = ['type' => $resourceName, 'id' => $resource->id()];
        return $resource;
    }

    protected function batch(array $ids, array $bulkedit, string $resourceName = 'items'): void
    {
        $this->api()->batchUpdate($resourceName, $ids, ['bulkedit' => $bulkedit], [
            'continueOnError' => true,
            'collectionAction' => 'replace',
        ]);
    }

    protected function reloaded(int $id, string $resourceName = 'items')
    {
        $this->getEntityManager()->clear();
        $this->loginAdmin();
        return $this->api()->read($resourceName, $id)->getContent();
    }

    protected function describeValue($value): string
    {
        $main = $this->getServiceLocator()->get('Common\EasyMeta')->dataTypeMain($value->type());
        if ($main === 'resource') {
            $string = 'link:' . $value->valueResource()->displayTitle();
        } elseif ($main === 'uri') {
            $string = $value->type() . ':' . $value->uri() . (strlen((string) $value->value()) ? '|' . $value->value() : '');
        } else {
            $string = $value->type() . ':' . $value->value();
        }
        return $string
            . (strlen((string) $value->lang()) ? '@' . $value->lang() : '')
            . ($value->isPublic() ? '' : '!');
    }

    /**
     * Get the description of the values of a term of a reloaded resource.
     */
    protected function described(int $id, string $term, string $resourceName = 'items'): array
    {
        $values = $this->reloaded($id, $resourceName)->value($term, ['all' => true]);
        return array_map(fn ($value) => $this->describeValue($value), $values);
    }

    protected function bulkEditService()
    {
        return $this->getServiceLocator()->get('BulkEdit');
    }
}
