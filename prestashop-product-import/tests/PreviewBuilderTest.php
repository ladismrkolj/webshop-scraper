<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\AttributeResolver;
use ProductImport\Service\CategoryPathNormalizer;
use ProductImport\Service\CategoryResolver;
use ProductImport\Service\ExpressionEvaluator;
use ProductImport\Service\ManufacturerResolver;
use ProductImport\Service\PreviewBuilder;
use ProductImport\Service\ProductFieldMapper;
use ProductImport\Service\ProductFilter;
use ProductImport\Service\VariantFieldMapper;

class PreviewBuilderTest extends TestCase
{
    private function build(array $source, array $item = []): array
    {
        $evaluator = new ExpressionEvaluator();
        $categories = new class () extends CategoryResolver {
            public function __construct()
            {
            }

            public function resolve(array $paths, int $source, ?int $root, bool $commit = true): array
            {
                TestCase::assertFalse($commit);
                TestCase::assertSame(7, $source);
                TestCase::assertSame(9, $root);
                return [['path' => $paths[0], 'id_category' => null, 'auto_create' => true]];
            }
        };
        $manufacturers = new class () extends ManufacturerResolver {
            public function resolve(?string $name, int $idSource, bool $commit = true)
            {
                TestCase::assertSame(7, $idSource);
                TestCase::assertFalse($commit);
                if ($name === 'failure') {
                    throw new \RuntimeException('Manufacturer lookup failed');
                }
                return ['name' => $name, 'id_manufacturer' => $name ? 12 : null, 'auto_create' => false];
            }
        };
        $attributes = new class () extends AttributeResolver {
            public function resolve(string $groupName, string $valueName, bool $commit = true): array
            {
                TestCase::assertFalse($commit);
                return ['id_attribute_group' => 1, 'id_attribute' => null, 'create_group' => false, 'create_value' => true];
            }
        };
        $builder = new PreviewBuilder(new ProductFilter($evaluator), new ProductFieldMapper($evaluator), new CategoryPathNormalizer(), $categories, $manufacturers, new VariantFieldMapper($evaluator), $attributes);
        return $builder->build($source + ['id_source' => 7, 'root_category_id' => 9, 'field_mapping' => []], $item);
    }

    public function testAssemblesFieldsAndReadOnlyResolutions(): void
    {
        $result = $this->build(['field_mapping' => ['name' => 'fields["name"]', 'category_paths' => '[" Boards "]', 'manufacturer' => '"Acme"']], ['name' => 'Board']);
        self::assertTrue($result['filter']['should_import']);
        self::assertSame('No filter configured.', $result['filter']['reason']);
        self::assertSame('Board', $result['base']['values']['name']);
        self::assertSame([['path' => ['Boards'], 'id_category' => null, 'auto_create' => true]], $result['categories']);
        self::assertSame(12, $result['manufacturer']['id_manufacturer']);
        self::assertSame([], $result['errors']);
    }

    public function testFalseFilterStillMaps(): void
    {
        $result = $this->build(['filter_expression' => 'false', 'field_mapping' => ['name' => '"Board"']]);
        self::assertFalse($result['filter']['should_import']);
        self::assertSame('Filter evaluated false.', $result['filter']['reason']);
        self::assertSame('Board', $result['base']['values']['name']);
    }

    public function testBrokenFilterAndFieldErrorsAllSurface(): void
    {
        $result = $this->build(['filter_expression' => 'fields[', 'field_mapping' => ['bad' => '1 / 0', 'good' => '42']]);
        self::assertNull($result['filter']['should_import']);
        self::assertArrayHasKey('filter', $result['errors']);
        self::assertArrayHasKey('bad', $result['base']['errors']);
        self::assertSame(42, $result['base']['values']['good']);
    }

    public function testVariantCapAndAllMappingErrors(): void
    {
        $result = $this->build(['variant_mapping' => [
            'variants_expression' => 'fields["variants"]',
            'attributes' => [['name' => 'Size', 'expression' => 'variant["size"]'], ['name' => 'Broken', 'expression' => 'variant[']],
            'fields' => ['reference' => 'variant["size"]', 'bad' => '1 / 0'],
        ]], ['variants' => array_fill(0, 8, ['size' => 'M'])]);
        self::assertSame(8, $result['variants']['total']);
        self::assertSame(3, $result['variants']['truncated']);
        self::assertCount(5, $result['variants']['items']);
        foreach ($result['variants']['items'] as $variant) {
            self::assertSame('M', $variant['fields']['values']['reference']);
            self::assertArrayHasKey('bad', $variant['fields']['errors']);
            self::assertArrayHasKey('Broken', $variant['attributes']['errors']);
            self::assertTrue($variant['resolutions']['Size']['create_value']);
            self::assertArrayNotHasKey('Broken', $variant['resolutions']);
        }
    }

    public function testBrokenVariantsListSurfaces(): void
    {
        $result = $this->build(['variant_mapping' => ['variants_expression' => 'null']]);
        self::assertArrayHasKey('variants', $result['errors']);
        self::assertSame([], $result['variants']['items']);
    }

    public function testEmptyAttributesAndResolverFailureSurface(): void
    {
        $result = $this->build(['field_mapping' => ['manufacturer' => '"failure"'], 'variant_mapping' => [
            'variants_expression' => '[[]]', 'attributes' => [['name' => 'Size', 'expression' => 'null']], 'fields' => [],
        ]]);
        self::assertSame('Manufacturer lookup failed', $result['errors']['manufacturer']);
        self::assertSame('Attribute value is empty.', $result['variants']['items'][0]['attributes']['errors']['Size']);
    }

    public function testDisabledAndEmptyVariants(): void
    {
        foreach ([null, [], ['variants_expression' => ' '], ['variants_expression' => '[]', 'attributes' => [], 'fields' => []]] as $mapping) {
            $result = $this->build(['variant_mapping' => $mapping]);
            self::assertSame(['total' => 0, 'truncated' => 0, 'items' => []], $result['variants']);
        }
    }
}
