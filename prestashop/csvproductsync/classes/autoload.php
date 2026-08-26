<?php
/**
 * The module's classes, loaded in dependency order.
 *
 * PrestaShop's own autoloader indexes module classes, but the cron entry point
 * runs before that index is guaranteed to be warm, so the module is explicit
 * about what it needs.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

$csvsync_classes = [
    'CsvSyncSource',
    'CsvSyncMapping',
    'CsvSyncCategory',
    'CsvSyncLink',
    'CsvSyncRun',
    'CsvSyncFields',
    'CsvSyncTransformer',
    'CsvSyncReader',
    'CsvSyncRowMapper',
    'CsvSyncCategoryScanner',
    'CsvSyncPreview',
    'CsvSyncImporter',
];

foreach ($csvsync_classes as $csvsync_class) {
    if (!class_exists($csvsync_class, false)) {
        require_once __DIR__ . '/' . $csvsync_class . '.php';
    }
}
