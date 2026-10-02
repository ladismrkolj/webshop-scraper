<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\ExpressionEvaluationException;
use ProductImport\Service\ExpressionEvaluator;
use ProductImport\Service\VariantFieldMapper;

class VariantFieldMapperTest extends TestCase
{
    public function testMapsAttributesWithBothContextsAndStringConversion(): void
    {
        $mapper = new VariantFieldMapper(new ExpressionEvaluator());
        self::assertSame(['values' => ['Size' => '42', 'Color' => 'Blue'], 'errors' => []], $mapper->mapAttributes(
            ['color' => 'Blue'],
            ['size' => 42],
            [['name' => 'Size', 'expression' => 'variant["size"]'], ['name' => 'Color', 'expression' => 'fields["color"]']]
        ));
    }

    public function testAttributeErrorsDoNotAbortRemainingAttributes(): void
    {
        $result = (new VariantFieldMapper(new ExpressionEvaluator()))->mapAttributes([], ['size' => 'M'], [
            ['name' => 'Broken', 'expression' => 'variant['],
            ['name' => 'Array', 'expression' => '[]'],
            ['name' => 'Size', 'expression' => 'variant["size"]'],
            ['name' => 'Missing', 'expression' => 'path(variant, "missing")'],
        ]);
        self::assertSame(['Broken' => null, 'Array' => null, 'Size' => 'M', 'Missing' => null], $result['values']);
        self::assertSame(['Broken', 'Array'], array_keys($result['errors']));
        self::assertStringContainsString('variant[', $result['errors']['Broken']);
    }

    public function testMapsFieldsWithBothContexts(): void
    {
        $result = (new VariantFieldMapper(new ExpressionEvaluator()))->mapFields(['price' => 100], ['sku' => 'M', 'extra' => 12, 'available' => true], [
            'reference' => 'variant["sku"]',
            'price' => 'fields["price"] + variant["extra"]',
            'quantity' => 'variant["available"] ? 9999 : 0',
            'active' => 'variant["available"]',
        ]);
        self::assertSame(['values' => ['reference' => 'M', 'price' => 112, 'quantity' => 9999, 'active' => true], 'errors' => []], $result);
    }

    public function testFieldErrorsDoNotAbortAndNestedValuesPassThrough(): void
    {
        $result = (new VariantFieldMapper(new ExpressionEvaluator()))->mapFields([], ['nested' => ['a' => [1]]], [
            'syntax' => 'variant[', 'runtime' => '1 / 0', 'nested' => 'variant["nested"]',
        ]);
        self::assertSame(['syntax' => null, 'runtime' => null, 'nested' => ['a' => [1]]], $result['values']);
        self::assertSame(['syntax', 'runtime'], array_keys($result['errors']));
    }

    public function testEmptyMappings(): void
    {
        $mapper = new VariantFieldMapper(new ExpressionEvaluator());
        self::assertSame(['values' => [], 'errors' => []], $mapper->mapAttributes([], [], []));
        self::assertSame(['values' => [], 'errors' => []], $mapper->mapFields([], [], []));
    }

    public function testVariantContextsDoNotLeakBetweenCalls(): void
    {
        $mapper = new VariantFieldMapper(new ExpressionEvaluator());
        foreach (['M', 'L'] as $size) {
            self::assertSame($size, $mapper->mapFields([], ['size' => $size], ['reference' => 'variant["size"]'])['values']['reference']);
        }
    }

    public function testEvaluatesVariantsList(): void
    {
        $mapper = new VariantFieldMapper(new ExpressionEvaluator());
        self::assertSame([['size' => 'M'], ['size' => 'L']], $mapper->variants(['variants' => [['size' => 'M'], ['size' => 'L']]], 'fields["variants"]'));
        self::assertSame([], $mapper->variants([], '[]'));
    }

    public function testNullVariantsMeansNoVariants(): void
    {
        $mapper = new VariantFieldMapper(new ExpressionEvaluator());
        self::assertSame([], $mapper->variants(['variants' => null], 'fields["variants"]'));
        self::assertSame([], $mapper->variants([], 'path(fields, "variants")'));
    }

    #[DataProvider('invalidVariants')]
    public function testInvalidVariantListsSurface($variants): void
    {
        $this->expectException(ExpressionEvaluationException::class);
        (new VariantFieldMapper(new ExpressionEvaluator()))->variants(['variants' => $variants], 'fields["variants"]');
    }

    public static function invalidVariants(): array
    {
        return [['bad'], [false], [['size' => 'M']], [[1 => ['size' => 'M']]], [['M']], [[null]]];
    }

    public function testBrokenVariantsExpressionSurfaces(): void
    {
        $this->expectException(ExpressionEvaluationException::class);
        (new VariantFieldMapper(new ExpressionEvaluator()))->variants([], 'fields[');
    }
}
