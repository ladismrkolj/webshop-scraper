<?php

namespace ProductImport\Service;

class ProductFieldMapper
{
    private $evaluator;

    public function __construct(ExpressionEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    /** @return array{values: array<string, mixed>, errors: array<string, string>} */
    public function map(array $item, array $fieldMapping): array
    {
        $values = [];
        $errors = [];
        foreach ($fieldMapping as $target => $expression) {
            try {
                $values[$target] = $this->evaluator->evaluate($expression, ['fields' => $item]);
            } catch (ExpressionEvaluationException $error) {
                $values[$target] = null;
                $errors[$target] = $error->getMessage();
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
