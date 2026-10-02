<?php

namespace ProductImport\Service;

use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Repository\ExternalCombinationRepository;
use ProductImport\Repository\ImportRunRepository;

class ImportRunner
{
    public function __construct(
        private JsonFetcher $fetcher,
        private ProductFilter $filter,
        private ProductFieldMapper $mapper,
        private CategoryPathNormalizer $normalizer,
        private CategoryResolver $categories,
        private ManufacturerResolver $manufacturers,
        private ProductImporter $products,
        private VariantFieldMapper $variants,
        private AttributeResolver $attributes,
        private CombinationImporter $combinations,
        private ExternalProductRepository $productLinks,
        private ExternalCombinationRepository $combinationLinks,
        private ImportRunRepository $runs,
        private ImportCatalog $catalog
    ) {
    }

    /** Source mapping columns must be decoded by the caller, as for PreviewBuilder. */
    public function runOne(array $source, string $triggeredBy): array
    {
        $idSource = (int) $source['id_source'];
        $idRun = $this->runs->start($idSource, $triggeredBy);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $log = '';
        try {
            $items = $this->fetcher->fetch($source['json_url'] ?? null, $source['json_file_path'] ?? null);
        } catch (\Throwable $error) {
            $log = ImportRunRepository::capLog($error->getMessage());
            $this->runs->finish($idRun, 'failed', $counts, $log);
            return ['id_source' => $idSource, 'id_run' => $idRun, 'status' => 'failed', 'triggered_by' => $triggeredBy, 'counts' => $counts, 'error_log' => $log];
        }
        foreach ($items as $index => $item) {
            try {
                if (!is_array($item) || !is_scalar($item[$source['identifier_field']] ?? null) || (string) $item[$source['identifier_field']] === '') {
                    throw new \RuntimeException('missing identifier_field value');
                }
                $externalId = (string) $item[$source['identifier_field']];
                if (!$this->filter->shouldImport($item, $source['filter_expression'] ?? null)) {
                    ++$counts['skipped'];
                    continue;
                }
                $existing = $this->productLinks->findProductId($idSource, $externalId);
                $mapped = $this->mapper->map($item, $source['field_mapping']);
                foreach ($mapped['errors'] as $field => $error) {
                    $this->log($log, "item $index field $field: $error");
                }
                $values = $mapped['values'];
                $categoryIds = isset($values['category_paths']) ? $this->categories->resolve($this->normalizer->normalize($values['category_paths']), $idSource, isset($source['root_category_id']) ? (int) $source['root_category_id'] : null, true, isset($source['default_id_category']) ? (int) $source['default_id_category'] : null) : [];
                $manufacturer = $this->manufacturers->resolve($values['manufacturer'] ?? null, $idSource, true, isset($source['default_id_manufacturer']) ? (int) $source['default_id_manufacturer'] : null);
                $idProduct = $this->products->import($idSource, $externalId, $values, $categoryIds, $manufacturer, isset($source['id_supplier']) && $source['id_supplier'] !== null ? (int) $source['id_supplier'] : null, (int) $source['id_lang_default']);
                // Importers retain their existing signatures; refresh their link with this run ID.
                $this->productLinks->link($idSource, $externalId, $idProduct, $idRun);
                ++$counts[$existing === null ? 'created' : 'updated'];
                $configuration = $source['variant_mapping'] ?? null;
                if (!$configuration || trim($configuration['variants_expression'] ?? '') === '') {
                    continue;
                }
                $variants = $this->variants->variants($item, $configuration['variants_expression']);
                $basePrice = $this->catalog->savedPrice($idProduct);
                foreach ($variants as $variantIndex => $variant) {
                    try {
                        $fields = $this->variants->mapFields($item, $variant, $configuration['fields']);
                        $attributes = $this->variants->mapAttributes($item, $variant, $configuration['attributes']);
                        foreach ($fields['errors'] as $field => $error) {
                            $this->log($log, "item $index variant $variantIndex field $field: $error");
                        }
                        if ($attributes['errors']) {
                            throw new \RuntimeException('Attribute errors: ' . json_encode($attributes['errors']));
                        }
                        $reference = $fields['values']['reference'] ?? null;
                        if (!is_scalar($reference) || trim((string) $reference) === '') {
                            throw new \RuntimeException('Missing variant reference.');
                        }
                        $ids = [];
                        foreach ($attributes['values'] as $group => $value) {
                            if ($value === null || trim($value) === '') {
                                throw new \RuntimeException('Empty attribute: ' . $group);
                            }
                            $ids[] = $this->attributes->resolve((string) $group, $value, true)['id_attribute'];
                        }
                        $idCombination = $this->combinations->import($idSource, $externalId, (string) $reference, $idProduct, $basePrice, $fields['values'], $ids);
                        $this->combinationLinks->link($idSource, $externalId . ':' . $reference, $idCombination, $idProduct, $idRun);
                    } catch (\Throwable $error) {
                        ++$counts['failed'];
                        $this->log($log, "item $index variant $variantIndex: " . $error->getMessage());
                    }
                }
            } catch (\Throwable $error) {
                ++$counts['failed'];
                $this->log($log, "item $index: " . $error->getMessage());
            }
        }
        if (!empty($source['deactivate_missing'])) {
            try {
                foreach ($this->productLinks->findStaleForSource($idSource, $idRun) as $row) {
                    try {
                        $this->catalog->deactivateProduct((int) $row['id_product']);
                    } catch (\Throwable $error) {
                        ++$counts['failed'];
                        $this->log($log, 'Deactivate product: ' . $error->getMessage());
                    }
                }
                foreach ($this->combinationLinks->findStaleForSource($idSource, $idRun) as $row) {
                    try {
                        $this->catalog->zeroCombination((int) $row['id_product'], (int) $row['id_product_attribute']);
                    } catch (\Throwable $error) {
                        ++$counts['failed'];
                        $this->log($log, 'Deactivate combination: ' . $error->getMessage());
                    }
                }
            } catch (\Throwable $error) {
                ++$counts['failed'];
                $this->log($log, 'Stale lookup: ' . $error->getMessage());
            }
        }
        $status = $counts['failed'] > 0 ? 'completed_with_errors' : 'completed';
        $this->runs->finish($idRun, $status, $counts, $log);
        return ['id_source' => $idSource, 'id_run' => $idRun, 'status' => $status, 'triggered_by' => $triggeredBy, 'counts' => $counts, 'error_log' => $log];
    }

    public function runAll(array $sources, string $triggeredBy): array
    {
        $results = [];
        foreach ($sources as $source) {
            if (empty($source['active'])) {
                continue;
            }
            try {
                $results[] = $this->runOne($source, $triggeredBy);
            } catch (\Throwable $error) {
                $results[] = ['id_source' => $source['id_source'], 'status' => 'failed', 'triggered_by' => $triggeredBy, 'counts' => ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0], 'error_log' => ImportRunRepository::capLog($error->getMessage())];
            }
        }
        return $results;
    }

    private function log(string &$log, string $message): void
    {
        $log = ImportRunRepository::capLog($log . ($log === '' ? '' : "\n") . $message);
    }
}
