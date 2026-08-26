<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

$tables = ['csvsync_run', 'csvsync_link', 'csvsync_category', 'csvsync_mapping', 'csvsync_source'];
foreach ($tables as $table) {
    Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . bqSQL($table) . '`');
}

return true;
