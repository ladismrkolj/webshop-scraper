<?php

use ProductImport\Repository\SourceRepository;
use ProductImport\Service\ImportRunnerFactory;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

class ProductImportCronModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        set_time_limit(0);
        header('Content-Type: application/json; charset=utf-8');
        $expected = Configuration::get('PIIMPORT_CRON_TOKEN');
        $provided = Tools::getValue('token');
        if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
            http_response_code(403);
            die('{"error":"Forbidden"}');
        }
        // Serialize local cron requests so run markers cannot race each other.
        $lock = fopen(_PS_CACHE_DIR_ . 'productimport-cron.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            http_response_code(409);
            die('{"error":"Import already running or lock unavailable"}');
        }
        try {
            $sources = [];
            $results = [];
            foreach ((new SourceRepository())->findAll() as $source) {
                if (empty($source['active'])) {
                    continue;
                }
                try {
                    foreach (['field_mapping', 'variant_mapping'] as $column) {
                        if ($column === 'variant_mapping' && empty($source[$column])) {
                            $source[$column] = null;
                        } else {
                            $source[$column] = json_decode($source[$column], true, 512, JSON_THROW_ON_ERROR);
                            if (!is_array($source[$column])) {
                                throw new InvalidArgumentException('Invalid ' . $column . ' configuration.');
                            }
                        }
                    }
                    $sources[] = $source;
                } catch (Throwable $error) {
                    $runs = new \ProductImport\Repository\ImportRunRepository();
                    $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
                    $message = $error->getMessage();
                    try {
                        $idRun = $runs->start((int) $source['id_source']);
                        $runs->finish($idRun, 'failed', $counts, $error->getMessage());
                    } catch (Throwable $loggingError) {
                        $message .= '; unable to record run: ' . $loggingError->getMessage();
                    }
                    $results[] = ['id_source' => $source['id_source'], 'status' => 'failed', 'counts' => $counts, 'error_log' => $message];
                }
            }
            $results = array_merge($results, ImportRunnerFactory::create()->runAll($sources));
            $json = json_encode(['runs' => $results], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (Throwable $error) {
            http_response_code(500);
            $json = json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        die($json === false ? '{"error":"Unable to encode run summary"}' : $json);
    }
}
