<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/config.inc.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use ProductImport\Repository\SourceRepository;
use ProductImport\Service\BackgroundImport;
use ProductImport\Service\ImportLauncher;
use ProductImport\Service\ImportLockBusyException;

try {
    if ($argc < 2 || $argc > 3) {
        throw new InvalidArgumentException('Usage: php bin/import.php all|<id_source> [cli|cron-background|admin-background]');
    }
    $raw = $argv[1];
    $selection = $raw === 'all' ? 'all' : (ctype_digit($raw) && (string) (int) $raw === $raw ? (int) $raw : null);
    BackgroundImport::selectionArgument($selection);
    $triggeredBy = $argv[2] ?? 'cli';
    if (!in_array($triggeredBy, ['cli', 'cron-background', 'admin-background'], true)) {
        throw new InvalidArgumentException('Invalid import trigger. Usage: php bin/import.php all|<id_source> [cli|cron-background|admin-background]');
    }
    set_time_limit(0);
    $context = Context::getContext();
    if (!$context->shop) {
        $context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
    }
    if (!$context->language) {
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    }
    $sources = ImportLauncher::selectSources((new SourceRepository())->findAll(), $selection);
    echo json_encode((new ImportLauncher())->run($sources, $triggeredBy), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (ImportLockBusyException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(3);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
