<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_7_0($module)
{
    if (Tab::getIdFromClassName('AdminPiCategoryMap')) {
        return true;
    }
    $tab = new Tab();
    $tab->active = 1;
    $tab->class_name = 'AdminPiCategoryMap';
    $tab->module = $module->name;
    // Hidden from the main menu: the page requires a source selected via its link.
    $tab->id_parent = -1;
    foreach (Language::getLanguages(false) as $language) {
        $tab->name[(int) $language['id_lang']] = 'Category mappings';
    }
    return $tab->add();
}
