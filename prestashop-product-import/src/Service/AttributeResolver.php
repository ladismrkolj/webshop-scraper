<?php

namespace ProductImport\Service;

class AttributeResolver
{
    public function resolve(string $groupName, string $valueName): array
    {
        $groupName = trim($groupName);
        $valueName = trim($valueName);
        if ($groupName === '' || $valueName === '') {
            throw new \InvalidArgumentException('Attribute group and value names must not be empty.');
        }
        $idGroup = (int) \Db::getInstance()->getValue(
            'SELECT `id_attribute_group` FROM `' . _DB_PREFIX_ . "attribute_group_lang` WHERE BINARY `name` = '" . pSQL($groupName, true) . "' ORDER BY `id_attribute_group`"
        );
        if (!$idGroup) {
            $group = new \AttributeGroup();
            $group->group_type = 'select';
            $group->is_color_group = false;
            foreach (\Language::getLanguages(false) as $language) {
                $group->name[(int) $language['id_lang']] = $groupName;
                $group->public_name[(int) $language['id_lang']] = $groupName;
            }
            if (!$group->add()) {
                throw new \RuntimeException('Unable to create attribute group.');
            }
            $idGroup = (int) $group->id;
        }
        $idAttribute = (int) \Db::getInstance()->getValue(
            'SELECT a.`id_attribute` FROM `' . _DB_PREFIX_ . 'attribute` a INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON al.`id_attribute` = a.`id_attribute`'
            . ' WHERE a.`id_attribute_group` = ' . (int) $idGroup . " AND BINARY al.`name` = '" . pSQL($valueName, true) . "' ORDER BY a.`id_attribute`"
        );
        if (!$idAttribute) {
            // PS 8 renamed Attribute to ProductAttribute to avoid PHP 8's built-in Attribute.
            $class = class_exists('ProductAttribute') ? 'ProductAttribute' : 'Attribute';
            if (!is_subclass_of($class, 'ObjectModel')) {
                throw new \RuntimeException('No PrestaShop attribute ObjectModel is available.');
            }
            $attribute = new $class();
            $attribute->id_attribute_group = $idGroup;
            foreach (\Language::getLanguages(false) as $language) {
                $attribute->name[(int) $language['id_lang']] = $valueName;
            }
            if (!$attribute->add()) {
                throw new \RuntimeException('Unable to create attribute value.');
            }
            $idAttribute = (int) $attribute->id;
        }

        return ['id_attribute_group' => $idGroup, 'id_attribute' => $idAttribute];
    }
}
