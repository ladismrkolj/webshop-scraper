<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_8_0($module)
{
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
    return true;
}
