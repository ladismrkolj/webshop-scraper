<?php

use ProductImport\Repository\SourceRepository;
use ProductImport\Service\ImportLauncher;
use ProductImport\Service\BackgroundImport;
use ProductImport\Service\BackgroundImportUnsupportedException;
use ProductImport\Service\ImportLockBusyException;

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
        try {
            $rawSource = Tools::getValue('source');
            $selection = ($rawSource === false || $rawSource === '') ? 'all' : (int) $rawSource;
            if ($selection !== 'all' && $selection <= 0) {
                throw new InvalidArgumentException('source must be a positive source ID.');
            }
            $sources = ImportLauncher::selectSources((new SourceRepository())->findAll(), $selection);
            if (Tools::getValue('background') === '1') {
                try {
                    (new BackgroundImport())->start($selection, 'cron-background');
                    http_response_code(202);
                    $json = '{"started":true}';
                } catch (BackgroundImportUnsupportedException $error) {
                    $json = json_encode((new ImportLauncher())->run($sources, 'cron'), JSON_INVALID_UTF8_SUBSTITUTE);
                }
            } else {
                $json = json_encode((new ImportLauncher())->run($sources, 'cron'), JSON_INVALID_UTF8_SUBSTITUTE);
            }
        } catch (ImportLockBusyException $error) {
            http_response_code(409);
            $json = '{"error":"Import already running or lock unavailable"}';
        } catch (Throwable $error) {
            http_response_code(500);
            $json = json_encode(['error' => $error->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        die($json === false ? '{"error":"Unable to encode run summary"}' : $json);
    }
}
