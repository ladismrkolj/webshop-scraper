<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_13_0($module)
{
    $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
        . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . 'pi_source') . "' AND COLUMN_NAME = 'consecutive_empty_count'");
    if ($rows === false || ($rows === [] && !Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'pi_source` ADD COLUMN `consecutive_empty_count` INT UNSIGNED NOT NULL DEFAULT 0'))) {
        return false;
    }
    return true;
}
