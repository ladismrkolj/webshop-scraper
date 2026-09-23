<?php

namespace ProductImport\Service;

class ManufacturerResolver
{
    /** @return int|null|array Commit: ID/null; preview: {name, id_manufacturer, auto_create}. */
    public function resolve(?string $name, bool $commit = true)
    {
        if ($name === null || trim($name) === '') {
            return $commit ? null : ['name' => null, 'id_manufacturer' => null, 'auto_create' => false];
        }
        $name = trim($name);
        // Manufacturer names are in manufacturer itself, not manufacturer_lang.
        $id = \Db::getInstance()->getValue(
            'SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . "manufacturer` WHERE BINARY `name` = '" . pSQL($name, true) . "' ORDER BY `id_manufacturer`"
        );
        if (!$commit) {
            return ['name' => $name, 'id_manufacturer' => $id === false ? null : (int) $id, 'auto_create' => $id === false];
        }
        if ($id !== false) {
            return (int) $id;
        }
        $manufacturer = new \Manufacturer();
        $manufacturer->name = $name;
        $manufacturer->active = true;
        if (!$manufacturer->add()) {
            throw new \RuntimeException('Unable to create manufacturer: ' . $name);
        }

        return (int) $manufacturer->id;
    }
}
