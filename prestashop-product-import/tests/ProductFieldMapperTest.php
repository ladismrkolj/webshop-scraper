<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluator;
use ProductImport\Service\ProductFieldMapper;

class ProductFieldMapperTest extends TestCase
{
    public function testMapsFieldsAndPreservesNestedBreadcrumbs(): void
    {
        $items = json_decode(file_get_contents(__DIR__ . '/fixtures/products.json'), true);
        $result = (new ProductFieldMapper(new ExpressionEvaluator()))->map($items[0], [
            'name' => 'str(path(fields, "name"))',
            'price' => 'num(path(fields, "price")) * 2',
            'categories' => 'path(fields, "breadcrumbs")',
        ]);
        self::assertSame(['name' => 'Freeride sail', 'price' => 499.0, 'categories' => $items[0]['breadcrumbs']], $result['values']);
        self::assertSame([], $result['errors']);
    }

    public function testPassesThroughStringLists(): void
    {
        $items = json_decode(file_get_contents(__DIR__ . '/fixtures/products.json'), true);
        $result = (new ProductFieldMapper(new ExpressionEvaluator()))->map($items[1], ['categories' => 'fields["categories"]']);
        self::assertSame(['values' => ['categories' => ['SUP', 'Boards']], 'errors' => []], $result);
    }

    public function testFailedFieldsDoNotAbortLaterFields(): void
    {
        $result = (new ProductFieldMapper(new ExpressionEvaluator()))->map(['name' => 'Sail'], [
            'price' => 'fields[', 'bad_type' => 'path(fields, [])', 'name' => 'fields["name"]', 'missing' => 'path(fields, "missing")',
        ]);
        self::assertSame(['price' => null, 'bad_type' => null, 'name' => 'Sail', 'missing' => null], $result['values']);
        self::assertSame(['price', 'bad_type'], array_keys($result['errors']));
        self::assertStringContainsString('fields[', $result['errors']['price']);
    }

    public function testEmptyMapping(): void
    {
        self::assertSame(['values' => [], 'errors' => []], (new ProductFieldMapper(new ExpressionEvaluator()))->map([], []));
    }
}
