<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'csvsync_source` (
    `id_csvsync_source` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `location` VARCHAR(1024) NOT NULL,
    `delimiter` VARCHAR(4) NOT NULL DEFAULT ",",
    `enclosure` VARCHAR(4) NOT NULL DEFAULT "\\"",
    `encoding` VARCHAR(32) NOT NULL DEFAULT "UTF-8",
    `has_header` TINYINT(1) NOT NULL DEFAULT 1,
    `id_lang` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_shop` INT(11) UNSIGNED NOT NULL DEFAULT 1,
    `id_category_default` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `id_tax_rules_group` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `match_by` VARCHAR(32) NOT NULL DEFAULT "external_id",
    `create_missing_products` TINYINT(1) NOT NULL DEFAULT 1,
    `activate_new_products` TINYINT(1) NOT NULL DEFAULT 0,
    `update_price` TINYINT(1) NOT NULL DEFAULT 1,
    `update_stock` TINYINT(1) NOT NULL DEFAULT 1,
    `update_text` TINYINT(1) NOT NULL DEFAULT 0,
    `update_images` TINYINT(1) NOT NULL DEFAULT 0,
    `update_categories` TINYINT(1) NOT NULL DEFAULT 0,
    `category_mode` VARCHAR(16) NOT NULL DEFAULT "auto",
    `unmapped_category_action` VARCHAR(32) NOT NULL DEFAULT "default",
    `missing_action` VARCHAR(32) NOT NULL DEFAULT "disable",
    `missing_max_percent` INT(11) UNSIGNED NOT NULL DEFAULT 30,
    `price_multiplier` DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    `price_tax_included` TINYINT(1) NOT NULL DEFAULT 0,
    `price_round` INT(11) UNSIGNED NOT NULL DEFAULT 2,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_add` DATETIME NULL,
    `date_upd` DATETIME NULL,
    PRIMARY KEY (`id_csvsync_source`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'csvsync_mapping` (
    `id_csvsync_mapping` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_csvsync_source` INT(11) UNSIGNED NOT NULL,
    `csv_column` VARCHAR(255) NOT NULL,
    `ps_field` VARCHAR(128) NOT NULL,
    `transform` VARCHAR(64) NOT NULL DEFAULT "none",
    `default_value` VARCHAR(1024) NULL,
    `position` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id_csvsync_mapping`),
    KEY `idx_source` (`id_csvsync_source`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'csvsync_category` (
    `id_csvsync_category` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_csvsync_source` INT(11) UNSIGNED NOT NULL,
    `csv_value` VARCHAR(512) NOT NULL,
    `id_category` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT "new",
    `occurrences` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `date_add` DATETIME NULL,
    `date_upd` DATETIME NULL,
    PRIMARY KEY (`id_csvsync_category`),
    UNIQUE KEY `idx_source_value` (`id_csvsync_source`, `csv_value`(191)),
    KEY `idx_status` (`id_csvsync_source`, `status`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'csvsync_link` (
    `id_csvsync_link` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_csvsync_source` INT(11) UNSIGNED NOT NULL,
    `external_id` VARCHAR(255) NOT NULL,
    `id_product` INT(11) UNSIGNED NOT NULL,
    `row_hash` VARCHAR(40) NULL,
    `image_hash` VARCHAR(40) NULL,
    `date_seen` DATETIME NULL,
    `date_add` DATETIME NULL,
    `date_upd` DATETIME NULL,
    PRIMARY KEY (`id_csvsync_link`),
    UNIQUE KEY `idx_source_external` (`id_csvsync_source`, `external_id`),
    KEY `idx_product` (`id_product`),
    KEY `idx_seen` (`id_csvsync_source`, `date_seen`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'csvsync_run` (
    `id_csvsync_run` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_csvsync_source` INT(11) UNSIGNED NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT "running",
    `trigger_type` VARCHAR(16) NOT NULL DEFAULT "manual",
    `rows_read` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_created` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_updated` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_unchanged` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `products_removed` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `rows_skipped` INT(11) UNSIGNED NOT NULL DEFAULT 0,
    `message` TEXT NULL,
    `date_add` DATETIME NULL,
    `date_upd` DATETIME NULL,
    PRIMARY KEY (`id_csvsync_run`),
    KEY `idx_source` (`id_csvsync_source`, `id_csvsync_run`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
