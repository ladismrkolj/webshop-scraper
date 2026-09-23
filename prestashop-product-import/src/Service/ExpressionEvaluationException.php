<?php

namespace ProductImport\Service;

class ExpressionEvaluationException extends \RuntimeException
{
    private $expression;

    public function __construct(string $expression, \Throwable $previous)
    {
        $this->expression = $expression;
        parent::__construct('Expression "' . $expression . '": ' . $previous->getMessage(), 0, $previous);
    }

    public function getExpression(): string
    {
        return $this->expression;
    }
}
