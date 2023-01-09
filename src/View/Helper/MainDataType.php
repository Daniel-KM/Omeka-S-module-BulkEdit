<?php declare(strict_types=1);

namespace BulkEdit\View\Helper;

use Laminas\View\Helper\AbstractHelper;

/**
 * @see \AdvancedResourceTemplate\\View\Helper\MainDataType
 * @see \BulkEdit\View\Helper\MainDataType
 * @see \BulkExport\View\Helper\MainDataType
 * @see \BulkImport\View\Helper\MainDataType
 * Used in Contribute.
 */
class MainDataType extends AbstractHelper
{
    /**
     * @var CustomVocabBaseType
     */
    protected $customVocabBaseType;

    // public function __construct(CustomVocabBaseType $customVocabBaseType)
    public function __construct($customVocabBaseType)
    {
        $this->customVocabBaseType = $customVocabBaseType;
    }

    /**
     * Get the main type of a data type, so "literal", "resource", or "uri".
     *
     * @return string|null
     */
    public function __invoke(?string $dataType): ?string
    {
        if (empty($dataType)) {
            return null;
        }
        $mainDataTypes = [
            'literal' => 'literal',
            'uri' => 'uri',
            'resource' => 'resource',
            'resource:item' => 'resource',
            'resource:itemset' => 'resource',
            'resource:media' => 'resource',
            // Module Annotate.
            'resource:annotation' => 'resource',
            'annotation' => 'resource',
            // Module DataTypeGeometry.
            'geography' => 'literal',
            'geography:coordinates' => 'literal',
            'geometry' => 'literal',
            'geometry:coordinates' => 'literal',
            'geometry:position' => 'literal',
            // TODO Deprecated. Remove in v4.
            'geometry:geography:coordinates' => 'literal',
            'geometry:geography' => 'literal',
            'geometry:geometry' => 'literal',
            // Module DataTypePlace.
            'place' => 'literal',
            // Module DataTypeRdf.
            'html' => 'literal',
            'xml' => 'literal',
            'boolean' => 'literal',
            // Specific module.
            'email' => 'literal',
            // Module NumericDataTypes.
            'numeric:timestamp' => 'literal',
            'numeric:integer' => 'literal',
            'numeric:duration' => 'literal',
            'numeric:interval' => 'literal',
        ];
        $dataType = strtolower($dataType);
        if (array_key_exists($dataType, $mainDataTypes)) {
            return $mainDataTypes[$dataType];
        }
        // Module ValueSuggest.
        if (substr($dataType, 0, 12) === 'valuesuggest'
            // || substr($dataType, 0, 15) === 'valuesuggestall'
        ) {
            return 'uri';
        }
        if (substr($dataType, 0, 11) === 'customvocab') {
            return $this->customVocabBaseType->__invoke(substr($dataType, 12));
        }
        return null;
    }
}
