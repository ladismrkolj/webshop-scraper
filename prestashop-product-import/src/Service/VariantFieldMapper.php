<?php

namespace ProductImport\Service;

class VariantFieldMapper
{
    use EvaluatesFieldMappings;

    private $evaluator;

    public function __construct(ExpressionEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    /** @return array{values: array<string, mixed>, errors: array<string, string>} */
    public function mapAttributes(array $item, array $variant, array $attributeDefinitions): array
    {
        $mapping = [];
        foreach ($attributeDefinitions as $definition) {
            // str() enforces scalar/stringable attribute values inside the error boundary.
            $mapping[$definition['name']] = 'str(' . $definition['expression'] . ')';
        }

        return $this->evaluateMapping($mapping, ['fields' => $item, 'variant' => $variant]);
    }

    /** @return array{values: array<string, mixed>, errors: array<string, string>} */
    public function mapFields(array $item, array $variant, array $fieldMapping): array
    {
        return $this->evaluateMapping($fieldMapping, ['fields' => $item, 'variant' => $variant]);
    }

    /** Evaluate once per product; null means no variants, while malformed lists surface. */
    public function variants(array $item, string $expression): array
    {
        $variants = $this->evaluator->evaluate($expression, ['fields' => $item]);
        if ($variants === null) {
            return [];
        }
        if (!is_array($variants) || ($variants !== [] && array_keys($variants) !== range(0, count($variants) - 1))) {
            throw new ExpressionEvaluationException($expression, new \UnexpectedValueException('Variants must be a list.'));
        }
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                throw new ExpressionEvaluationException($expression, new \UnexpectedValueException('Each variant must be an array/object decoded as an array.'));
            }
        }

        return $variants;
    }
}
