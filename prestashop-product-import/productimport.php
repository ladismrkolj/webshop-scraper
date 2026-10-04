<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class ProductImport extends Module
{
    public function __construct()
    {
        $this->name = 'productimport';
        $this->tab = 'administration';
        $this->version = '0.13.0';
        $this->author = 'Product Import';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.99.99'];
        parent::__construct();
        $this->displayName = $this->l('Product Import');
        $this->description = $this->l('Configure JSON or XML sources, filters and product field expressions.');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_source` (
            `id_source` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `technical_key` VARCHAR(64) NOT NULL,
            `json_url` TEXT NULL,
            `json_file_path` TEXT NULL,
            `source_format` VARCHAR(8) NULL,
            `xml_item_path` VARCHAR(255) NULL,
            `identifier_field` VARCHAR(191) NOT NULL,
            `filter_expression` LONGTEXT NULL,
            `field_mapping` LONGTEXT NOT NULL,
            `variant_mapping` LONGTEXT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `root_category_id` INT UNSIGNED NULL,
            `id_supplier` INT UNSIGNED NULL,
            `default_id_category` INT UNSIGNED NULL,
            `default_id_manufacturer` INT UNSIGNED NULL,
            `id_lang_default` INT UNSIGNED NOT NULL DEFAULT 1,
            `deactivate_missing` TINYINT(1) NOT NULL DEFAULT 0,
            `consecutive_empty_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_source`),
            UNIQUE KEY `technical_key` (`technical_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        if (!Db::getInstance()->execute($sql)) {
            return false;
        }
        if (!Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` (
            `id_mapping` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_source` INT UNSIGNED NOT NULL,
            `source_name` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `id_manufacturer` INT UNSIGNED NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_mapping`),
            UNIQUE KEY `source_name` (`id_source`, `source_name`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }
        if (!Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_category_mapping` (
            `id_mapping` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_source` INT UNSIGNED NOT NULL,
            `source_path_hash` CHAR(40) NOT NULL,
            `source_path` TEXT NOT NULL,
            `id_category` INT UNSIGNED NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_mapping`),
            UNIQUE KEY `source_path` (`id_source`, `source_path_hash`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }
        if (!Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_external_product` (
            `id_source` INT UNSIGNED NOT NULL,
            `external_id` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_run_last_seen` INT UNSIGNED NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_source`, `external_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }
        if (!Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_external_combination` (
            `id_source` INT UNSIGNED NOT NULL,
            `external_id` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            `id_product_attribute` INT UNSIGNED NOT NULL,
            `id_product` INT UNSIGNED NOT NULL,
            `id_run_last_seen` INT UNSIGNED NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_source`, `external_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }
        if (!Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pi_import_run` (
            `id_run` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_source` INT UNSIGNED NOT NULL,
            `started_at` DATETIME NOT NULL,
            `finished_at` DATETIME NULL,
            `status` VARCHAR(32) NOT NULL,
            `triggered_by` VARCHAR(32) NULL,
            `created_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `updated_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `skipped_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `error_log` LONGTEXT NULL,
            PRIMARY KEY (`id_run`), KEY `source_run` (`id_source`, `id_run`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }
        if (!Configuration::updateValue('PIIMPORT_CRON_TOKEN', bin2hex(random_bytes(20)))) {
            return false;
        }
        foreach (['AdminPiSource' => 'Product Import', 'AdminPiRunLog' => 'Product Import runs', 'AdminPiCategoryMap' => 'Category mappings', 'AdminPiManufacturerMap' => 'Brand mappings'] as $class => $title) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $class;
            $tab->module = $this->name;
            $tab->id_parent = in_array($class, ['AdminPiCategoryMap', 'AdminPiManufacturerMap'], true) ? -1 : (int) Tab::getIdFromClassName('AdminCatalog');
            foreach (Language::getLanguages(false) as $language) {
                $tab->name[(int) $language['id_lang']] = $title;
            }
            if (!$tab->add()) {
                return false;
            }
        }
        return true;
    }

    public function uninstall()
    {
        foreach (['pi_import_run', 'pi_external_combination', 'pi_external_product', 'pi_category_mapping', 'pi_manufacturer_mapping', 'pi_source'] as $table) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`')) {
                return false;
            }
        }
        foreach (['AdminPiManufacturerMap', 'AdminPiCategoryMap', 'AdminPiRunLog', 'AdminPiSource'] as $class) {
            $idTab = (int) Tab::getIdFromClassName($class);
            if ($idTab && !(new Tab($idTab))->delete()) {
                return false;
            }
        }
        if (!Configuration::deleteByName('PIIMPORT_CRON_TOKEN')) {
            return false;
        }

        return parent::uninstall();
    }

    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminPiSource'));

        return '';
    }
}
