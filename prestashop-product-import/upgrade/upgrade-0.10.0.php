<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_10_0($module)
{
    $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . 'pi_import_run') . "' AND COLUMN_NAME = 'triggered_by'");
    if ($rows === false) {
        return false;
    }
    return $rows !== [] || Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'pi_import_run` ADD COLUMN `triggered_by` VARCHAR(32) NULL');
}
