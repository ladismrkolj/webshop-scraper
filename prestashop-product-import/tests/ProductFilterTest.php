<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluationException;
use ProductImport\Service\ExpressionEvaluator;
use ProductImport\Service\ProductFilter;

class ProductFilterTest extends TestCase
{
    #[DataProvider('filterCases')]
    public function testFilter(?string $expression, bool $expected): void
    {
        $items = json_decode(file_get_contents(__DIR__ . '/fixtures/products.json'), true);
        self::assertSame($expected, (new ProductFilter(new ExpressionEvaluator()))->shouldImport($items[0], $expression));
    }

    public static function filterCases(): array
    {
        return [
            [null, true], ['', true], ['  ', true],
            ['fields["price"] > 200', true], ['fields["price"] < 200', false],
            ['regex("/InStock$/", fields["availability"])', true],
            ['1', true], ['0', false], ['path(fields, "missing")', false],
        ];
    }

    #[DataProvider('brokenFilters')]
    public function testBrokenFilterPropagates(string $expression): void
    {
        $this->expectException(ExpressionEvaluationException::class);
        (new ProductFilter(new ExpressionEvaluator()))->shouldImport([], $expression);
    }

    public static function brokenFilters(): array
    {
        return [['fields['], ['path(fields, [])']];
    }
}
