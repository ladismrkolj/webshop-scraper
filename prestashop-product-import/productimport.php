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
        $this->version = '0.3.0';
        $this->author = 'Product Import';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '8.99.99'];
        parent::__construct();
        $this->displayName = $this->l('Product Import');
        $this->description = $this->l('Configure JSON sources, filters and product field expressions.');
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
            `identifier_field` VARCHAR(191) NOT NULL,
            `filter_expression` LONGTEXT NULL,
            `field_mapping` LONGTEXT NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `root_category_id` INT UNSIGNED NULL,
            `id_lang_default` INT UNSIGNED NOT NULL DEFAULT 1,
            `price_tax_included` TINYINT(1) NOT NULL DEFAULT 0,
            `deactivate_missing` TINYINT(1) NOT NULL DEFAULT 0,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_source`),
            UNIQUE KEY `technical_key` (`technical_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';
        if (!Db::getInstance()->execute($sql)) {
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
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_source`, `external_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4')) {
            return false;
        }
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminPiSource';
        $tab->module = $this->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'Product Import';
        }

        return $tab->add();
    }

    public function uninstall()
    {
        foreach (['pi_external_product', 'pi_category_mapping', 'pi_source'] as $table) {
            if (!Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`')) {
                return false;
            }
        }
        $idTab = (int) Tab::getIdFromClassName('AdminPiSource');
        if ($idTab && !(new Tab($idTab))->delete()) {
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
