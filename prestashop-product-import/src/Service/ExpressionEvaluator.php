<?php

namespace ProductImport\Service;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

class ExpressionEvaluator
{
    private $language;

    public function __construct()
    {
        $this->language = new ExpressionLanguage();
        foreach (['path', 'num', 'str', 'regex', 'first'] as $name) {
            $this->language->register(
                $name,
                static function (...$arguments) use ($name) {
                    return '\\' . self::class . '::' . $name . '(' . implode(', ', $arguments) . ')';
                },
                static function (array $variables, ...$arguments) use ($name) {
                    return self::$name(...$arguments);
                }
            );
        }
    }

    /** @return mixed PHP 7.2-compatible equivalent of a mixed return type. */
    public function evaluate(string $expression, array $variables)
    {
        try {
            return $this->language->evaluate($expression, $variables);
        } catch (\Throwable $error) {
            throw new ExpressionEvaluationException($expression, $error);
        }
    }

    public static function path($data, string $path)
    {
        foreach (explode('.', $path) as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return null;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    public static function num($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function str($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_scalar($value) && !(is_object($value) && method_exists($value, '__toString'))) {
            throw new \InvalidArgumentException('str() expects a scalar or stringable value.');
        }

        return (string) $value;
    }

    public static function regex(string $pattern, $subject): bool
    {
        if ($subject === null) {
            return false;
        }
        $result = @preg_match($pattern, self::str($subject));
        if ($result === false) {
            throw new \InvalidArgumentException('Invalid regular expression (PCRE error ' . preg_last_error() . ').');
        }

        return $result === 1;
    }

    public static function first($list)
    {
        return is_array($list) && $list !== [] ? reset($list) : null;
    }
}
