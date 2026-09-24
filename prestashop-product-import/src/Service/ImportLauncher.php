<?php

namespace ProductImport\Service;

use ProductImport\Repository\ImportRunRepository;

class ImportLauncher
{
    public static function selectSources(array $sources, $selection): array
    {
        if ($selection === 'all') {
            return array_values(array_filter($sources, static function ($source) {
                return !empty($source['active']);
            }));
        }
        foreach ($sources as $source) {
            if ((int) $source['id_source'] === $selection) {
                return [$source];
            }
        }
        throw new \InvalidArgumentException('Source not found.');
    }

    public function run(array $sources): array
    {
        $lock = fopen(_PS_CACHE_DIR_ . 'productimport-cron.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }
            throw new ImportLockBusyException('Import already running or lock unavailable');
        }
        try {
            $ready = [];
            $results = [];
            foreach ($sources as $source) {
                try {
                    foreach (['field_mapping', 'variant_mapping'] as $column) {
                        if ($column === 'variant_mapping' && empty($source[$column])) {
                            $source[$column] = null;
                        } else {
                            $source[$column] = json_decode($source[$column], true, 512, JSON_THROW_ON_ERROR);
                            if (!is_array($source[$column])) {
                                throw new \InvalidArgumentException('Invalid ' . $column . ' configuration.');
                            }
                        }
                    }
                    $source['active'] = 1;
                    $ready[] = $source;
                } catch (\Throwable $error) {
                    $runs = new ImportRunRepository();
                    $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
                    $message = $error->getMessage();
                    try {
                        $idRun = $runs->start((int) $source['id_source']);
                        $runs->finish($idRun, 'failed', $counts, $message);
                    } catch (\Throwable $loggingError) {
                        $message .= '; unable to record run: ' . $loggingError->getMessage();
                    }
                    $results[] = ['id_source' => $source['id_source'], 'status' => 'failed', 'counts' => $counts, 'error_log' => $message];
                }
            }
            return ['runs' => array_merge($results, ImportRunnerFactory::create()->runAll($ready))];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
