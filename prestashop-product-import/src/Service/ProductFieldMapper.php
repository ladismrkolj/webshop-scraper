<?php

namespace ProductImport\Service;

class ProductFieldMapper
{
    use EvaluatesFieldMappings;

    private $evaluator;

    public function __construct(ExpressionEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    /** @return array{values: array<string, mixed>, errors: array<string, string>} */
    public function map(array $item, array $fieldMapping): array
    {
        return $this->evaluateMapping($fieldMapping, ['fields' => $item]);
    }
}
