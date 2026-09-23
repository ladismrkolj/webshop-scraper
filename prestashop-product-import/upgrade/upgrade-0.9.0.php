<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_9_0($module)
{
    // MySQL 8.0 has no ADD COLUMN IF NOT EXISTS; check metadata before each ALTER.
    foreach (['default_id_category', 'default_id_manufacturer'] as $column) {
        $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . 'pi_source') . "' AND COLUMN_NAME = '" . pSQL($column) . "'");
        if ($rows === false) {
            return false;
        }
        if ($rows === [] && !Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'pi_source` ADD COLUMN `' . $column . '` INT UNSIGNED NULL')) {
            return false;
        }
    }
    if (Tab::getIdFromClassName('AdminPiManufacturerMap')) {
        return true;
    }
    $tab = new Tab();
    $tab->active = 1;
    $tab->class_name = 'AdminPiManufacturerMap';
    $tab->module = $module->name;
    $tab->id_parent = -1;
    foreach (Language::getLanguages(false) as $language) {
        $tab->name[(int) $language['id_lang']] = 'Brand mappings';
    }
    return $tab->add();
}
