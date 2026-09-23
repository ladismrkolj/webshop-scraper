<?php

namespace ProductImport\Service;

trait EvaluatesFieldMappings
{
    private function evaluateMapping(array $fieldMapping, array $variables): array
    {
        $values = [];
        $errors = [];
        foreach ($fieldMapping as $target => $expression) {
            try {
                $values[$target] = $this->evaluator->evaluate($expression, $variables);
            } catch (ExpressionEvaluationException $error) {
                $values[$target] = null;
                $errors[$target] = $error->getMessage();
            }
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
