<?php

namespace ProductImport\Service;

class ProductFilter
{
    private $evaluator;

    public function __construct(ExpressionEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    public function shouldImport(array $item, ?string $filterExpression): bool
    {
        if ($filterExpression === null || trim($filterExpression) === '') {
            return true;
        }

        return (bool) $this->evaluator->evaluate($filterExpression, ['fields' => $item]);
    }
}
