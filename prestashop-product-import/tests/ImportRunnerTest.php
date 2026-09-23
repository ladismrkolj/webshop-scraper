<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;
use ProductImport\Service\{JsonFetcher, ProductFilter, ProductFieldMapper, CategoryPathNormalizer, CategoryResolver, ManufacturerResolver, ProductImporter, VariantFieldMapper, AttributeResolver, CombinationImporter, ImportCatalog, ImportRunner, ExpressionEvaluator};

class ImportRunnerTest extends TestCase
{
    private function fixture(array $items, bool $fetchFails = false): array
    {
        $state = (object) ['products' => ['old' => ['id_product' => 10, 'id_run_last_seen' => null], 'stale' => ['id_product' => 11, 'id_run_last_seen' => 1]],
            'combinations' => ['old:stale' => ['id_product' => 10, 'id_product_attribute' => 50, 'id_run_last_seen' => null]],
            'deactivated' => [], 'zeroed' => [], 'finished' => [], 'prices' => [], 'staleCalls' => 0];
        $fetcher = new class ($items, $fetchFails) extends JsonFetcher {
            public function __construct(private array $items, private bool $fails)
            {
            }
            public function fetch(?string $url, ?string $filePath): array
            {
                if ($this->fails) {
                    throw new \RuntimeException('fetch failed');
                }
                return $this->items;
            }
        };
        $links = new class ($state) extends ExternalProductRepository {
            public function __construct(private object $state)
            {
            }
            public function findProductId(int $source, string $external): ?int
            {
                return $this->state->products[$external]['id_product'] ?? null;
            }
            public function link(int $source, string $external, int $product, ?int $run = null): void
            {
                $this->state->products[$external] = ['id_product' => $product, 'id_run_last_seen' => $run];
            }
            public function findStaleForSource(int $source, int $run): array
            {
                ++$this->state->staleCalls;
                return array_values(array_filter($this->state->products, static fn ($row) => $row['id_run_last_seen'] !== $run));
            }
        };
        $combinationLinks = new class ($state) extends ExternalCombinationRepository {
            public function __construct(private object $state)
            {
            }
            public function link(int $source, string $external, int $combination, int $product, ?int $run = null): void
            {
                $this->state->combinations[$external] = ['id_product' => $product, 'id_product_attribute' => $combination, 'id_run_last_seen' => $run];
            }
            public function findStaleForSource(int $source, int $run): array
            {
                ++$this->state->staleCalls;
                return array_values(array_filter($this->state->combinations, static fn ($row) => $row['id_run_last_seen'] !== $run));
            }
        };
        $runs = new class ($state) extends ImportRunRepository {
            public function __construct(private object $state)
            {
            }
            public function start(int $source): int
            {
                if ($source === 99) {
                    throw new \RuntimeException('start failed');
                }
                return 20;
            }
            public function finish(int $run, string $status, array $counts, string $log): void
            {
                $this->state->finished[] = compact('run', 'status', 'counts', 'log');
            }
        };
        $products = new class () extends ProductImporter {
            public function __construct()
            {
            }
            public function import(int $source, string $external, array $values, array $categories, ?int $manufacturer, int $language, bool $tax): int
            {
                if ($external === 'broken') {
                    throw new \RuntimeException('save failed');
                }
                TestCase::assertSame([3], $categories);
                TestCase::assertSame(4, $manufacturer);
                return $external === 'old' ? 10 : 12;
            }
        };
        $categories = new class () extends CategoryResolver {
            public function __construct()
            {
            }
            public function resolve(array $paths, int $source, ?int $root, bool $commit = true): array
            {
                TestCase::assertTrue($commit);
                TestCase::assertSame([['Boards']], $paths);
                return [3];
            }
        };
        $manufacturers = new class () extends ManufacturerResolver {
            public function resolve(?string $name, int $idSource, bool $commit = true)
            {
                TestCase::assertSame(1, $idSource);
                TestCase::assertTrue($commit);
                return 4;
            }
        };
        $attributes = new class () extends AttributeResolver {
            public function resolve(string $group, string $value, bool $commit = true): array
            {
                TestCase::assertTrue($commit);
                return ['id_attribute_group' => 5, 'id_attribute' => 6];
            }
        };
        $combinations = new class ($state) extends CombinationImporter {
            public function __construct(private object $state)
            {
            }
            public function import(int $source, string $parent, string $variant, int $product, float $price, array $fields, array $ids): int
            {
                $this->state->prices[] = $price;
                TestCase::assertSame([6], $ids);
                if ($variant === 'bad') {
                    throw new \RuntimeException('variant failed');
                }
                return 60;
            }
        };
        $catalog = new class ($state) extends ImportCatalog {
            public function __construct(private object $state)
            {
            }
            public function savedPrice(int $idProduct): float
            {
                return 123.5;
            }
            public function deactivateProduct(int $idProduct): void
            {
                $this->state->deactivated[] = $idProduct;
            }
            public function zeroCombination(int $idProduct, int $idCombination): void
            {
                $this->state->zeroed[] = [$idProduct, $idCombination];
            }
        };
        $evaluator = new ExpressionEvaluator();
        $runner = new ImportRunner($fetcher, new ProductFilter($evaluator), new ProductFieldMapper($evaluator), new CategoryPathNormalizer(), $categories, $manufacturers, $products, new VariantFieldMapper($evaluator), $attributes, $combinations, $links, $combinationLinks, $runs, $catalog);
        return [$runner, $state];
    }

    private function source(array $overrides = []): array
    {
        return $overrides + ['id_source' => 1, 'active' => 1, 'identifier_field' => 'id', 'id_lang_default' => 1, 'price_tax_included' => false,
            'field_mapping' => ['name' => '"Board"', 'category_paths' => '["Boards"]'], 'filter_expression' => 'path(fields, "skip") != true', 'deactivate_missing' => false];
    }

    public function testMixedItemsAndCleanupOnlyStaleLinks(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'new'], ['id' => 'skip', 'skip' => true], [], ['id' => 'broken']]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true]));
        self::assertSame(['created' => 1, 'updated' => 1, 'skipped' => 1, 'failed' => 2], $result['counts']);
        self::assertSame('completed_with_errors', $result['status']);
        self::assertStringContainsString('item 3: missing identifier_field value', $result['error_log']);
        self::assertStringContainsString('item 4: save failed', $result['error_log']);
        self::assertSame([11], $state->deactivated);
        self::assertSame([[10, 50]], $state->zeroed);
        self::assertSame(20, $state->products['old']['id_run_last_seen']);
        self::assertSame($result['counts'], $state->finished[0]['counts']);
    }

    public function testFetchFailureNeverCleansUp(): void
    {
        [$runner, $state] = $this->fixture([], true);
        $result = $runner->runOne($this->source(['deactivate_missing' => true]));
        self::assertSame('failed', $result['status']);
        self::assertSame('fetch failed', $result['error_log']);
        self::assertSame(0, $state->staleCalls);
        self::assertSame([], $state->deactivated);
        self::assertSame('failed', $state->finished[0]['status']);
    }

    public function testVariantsUseSavedPriceAndContinueAfterFailure(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old', 'variants' => [['sku' => 'bad'], ['sku' => 'good']]]]);
        $result = $runner->runOne($this->source(['deactivate_missing' => true, 'variant_mapping' => ['variants_expression' => 'fields["variants"]', 'attributes' => [['name' => 'Size', 'expression' => '"M"']], 'fields' => ['reference' => 'variant["sku"]']]]));
        self::assertSame(1, $result['counts']['updated']);
        self::assertSame(1, $result['counts']['failed']);
        self::assertSame([123.5, 123.5], $state->prices);
        self::assertSame(20, $state->combinations['old:good']['id_run_last_seen']);
        self::assertSame([[10, 50]], $state->zeroed);
    }

    public function testBrokenFilterIsIsolatedPerItem(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'old'], ['id' => 'new']]);
        $result = $runner->runOne($this->source(['filter_expression' => 'fields[']));
        self::assertSame(2, $result['counts']['failed']);
        self::assertSame(0, $result['counts']['created']);
        self::assertNull($state->products['old']['id_run_last_seen']);
    }

    public function testFieldWarningsDoNotPreventImportAndLogIsBounded(): void
    {
        [$runner, $state] = $this->fixture([['id' => 'new']]);
        $source = $this->source();
        $source['field_mapping']['bad'] = 'fields[';
        $result = $runner->runOne($source);
        self::assertSame(1, $result['counts']['created']);
        self::assertStringContainsString('field bad', $result['error_log']);
        self::assertSame(0, $state->staleCalls);
        $log = ImportRunRepository::capLog(str_repeat('ž', 50000));
        self::assertLessThanOrEqual(65536, strlen($log));
        self::assertStringEndsWith('[log truncated]', $log);
        self::assertTrue(mb_check_encoding($log, 'UTF-8'));
    }

    public function testRunAllSkipsInactiveAndSurvivesStartFailure(): void
    {
        [$runner, $state] = $this->fixture([]);
        $results = $runner->runAll([$this->source(['active' => 0]), $this->source(['id_source' => 99]), $this->source()]);
        self::assertCount(2, $results);
        self::assertSame('failed', $results[0]['status']);
        self::assertSame('completed', $results[1]['status']);
        self::assertCount(1, $state->finished);
    }
}
