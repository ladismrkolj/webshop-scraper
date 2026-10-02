<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_11_0($module)
{
    $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . 'pi_source') . "' AND COLUMN_NAME = 'id_supplier'");
    if ($rows === false) {
        return false;
    }
    return $rows !== [] || Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'pi_source` ADD COLUMN `id_supplier` INT UNSIGNED NULL');
}
