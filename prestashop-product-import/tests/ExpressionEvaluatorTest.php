<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluationException;
use ProductImport\Service\ExpressionEvaluator;

class ExpressionEvaluatorTest extends TestCase
{
    #[DataProvider('helperCases')]
    public function testHelpers(string $expression, array $fields, $expected): void
    {
        self::assertSame($expected, (new ExpressionEvaluator())->evaluate($expression, ['fields' => $fields]));
    }

    public static function helperCases(): array
    {
        return [
            ['path(fields, "breadcrumbs.0.title")', ['breadcrumbs' => [['title' => 'Sails']]], 'Sails'],
            ['path(fields, "missing.deep")', [], null],
            ['path(fields, "price.deep")', ['price' => 12], null],
            ['path(fields, "empty.deep")', ['empty' => null], null],
            ['path(fields, "flag")', ['flag' => false], false],
            ['num(path(fields, "price"))', ['price' => '12.50'], 12.5],
            ['num(12)', [], 12.0], ['num(null)', [], null],
            ['num("EUR 12")', [], null], ['num([])', [], null],
            ['str(12)', [], '12'], ['str(null)', [], null], ['str(false)', [], ''],
            ['regex("/stock/i", "InStock")', [], true],
            ['regex("/yes/", "no")', [], false], ['regex("/yes/", null)', [], false],
            ['first(["Sails", "Boards"])', [], 'Sails'], ['first([])', [], null],
            ['first(null)', [], null], ['first("Sails")', [], null],
            ['first(fields)', ['named' => ['title' => 'Sails']], ['title' => 'Sails']],
        ];
    }

    #[DataProvider('brokenExpressions')]
    public function testWrapsSyntaxAndRuntimeErrors(string $expression): void
    {
        try {
            (new ExpressionEvaluator())->evaluate($expression, ['fields' => []]);
            self::fail('Expected expression evaluation to fail.');
        } catch (ExpressionEvaluationException $error) {
            self::assertSame($expression, $error->getExpression());
            self::assertInstanceOf(\Throwable::class, $error->getPrevious());
            self::assertStringContainsString($error->getPrevious()->getMessage(), $error->getMessage());
        }
    }

    public static function brokenExpressions(): array
    {
        return [['fields['], ['unknown_function()'], ['path(fields, [])'], ['1 / 0'], ['regex("/[invalid/", "value")'], ['str([])']];
    }
}
