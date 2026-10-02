<?php

namespace ProductImport\Service;

class ValueSampler
{
    public function sample(array $items, string $expression): array
    {
        $evaluator = new ExpressionEvaluator();
        $counts = [];
        $failed = 0;
        $errors = [];
        foreach ($items as $index => $item) {
            try {
                if (!is_array($item)) {
                    throw new \InvalidArgumentException('Item is not an object.');
                }
                $value = $evaluator->evaluate($expression, ['fields' => $item]);
                if ($value === null) {
                    continue;
                }
                if (is_array($value)) {
                    $key = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                } elseif (is_bool($value)) {
                    $key = $value ? 'true' : 'false';
                } elseif (is_scalar($value)) {
                    $key = (string) $value;
                } else {
                    throw new \InvalidArgumentException('Expression result must be a scalar, array, or null.');
                }
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            } catch (\Throwable $error) {
                ++$failed;
                if (count($errors) < 20) {
                    $errors[] = 'Item ' . $index . ': ' . mb_substr($error->getMessage(), 0, 500);
                }
            }
        }
        arsort($counts);
        $values = [];
        foreach (array_slice($counts, 0, 50, true) as $value => $count) {
            $values[] = ['value' => (string) $value, 'count' => $count];
        }

        return ['items_scanned' => count($items), 'errors' => ['count' => $failed, 'messages' => $errors],
            'values' => $values, 'distinct_total' => count($counts), 'truncated' => count($counts) > 50];
    }
}
