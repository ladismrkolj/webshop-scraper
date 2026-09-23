<?php

namespace ProductImport\Service;

class ManufacturerResolver
{
    public function resolve(?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $name = trim($name);
        // Manufacturer names are in manufacturer itself, not manufacturer_lang.
        $id = \Db::getInstance()->getValue(
            'SELECT `id_manufacturer` FROM `' . _DB_PREFIX_ . "manufacturer` WHERE BINARY `name` = '" . pSQL($name, true) . "' ORDER BY `id_manufacturer`"
        );
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
