<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_12_0($module)
{
    foreach (['source_format' => 'VARCHAR(8) NULL', 'xml_item_path' => 'VARCHAR(255) NULL'] as $column => $definition) {
        $rows = Db::getInstance()->executeS('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME = '" . pSQL(_DB_PREFIX_ . 'pi_source') . "' AND COLUMN_NAME = '" . pSQL($column) . "'");
        if ($rows === false || ($rows === [] && !Db::getInstance()->execute('ALTER TABLE `' . _DB_PREFIX_ . 'pi_source` ADD COLUMN `' . $column . '` ' . $definition))) {
            return false;
        }
    }
    return true;
}
