<?php

namespace ProductImport\Service;

class CatalogOptions
{
    public function categories(int $idShop, int $idLang): array
    {
        $rows = \Db::getInstance()->executeS('SELECT c.`id_category`, c.`level_depth`, cl.`name` FROM `' . _DB_PREFIX_ . 'category` c'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs ON cs.`id_category` = c.`id_category` AND cs.`id_shop` = ' . $idShop
            . ' LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON cl.`id_category` = c.`id_category` AND cl.`id_shop` = cs.`id_shop` AND cl.`id_lang` = ' . $idLang
            . ' ORDER BY c.`nleft`, c.`id_category`');
        if ($rows === false) {
            throw new \RuntimeException('Unable to list categories.');
        }
        return array_map(static function (array $row): array {
            return ['id_category' => (int) $row['id_category'], 'label' => str_repeat('— ', min(30, (int) $row['level_depth'])) . ($row['name'] ?? '(unnamed)') . ' #' . (int) $row['id_category']];
        }, $rows);
    }

    public function manufacturers(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_manufacturer`, `name` FROM `' . _DB_PREFIX_ . 'manufacturer` ORDER BY `name`');
        if ($rows === false) {
            throw new \RuntimeException('Unable to list manufacturers.');
        }
        return array_map(static function (array $row): array {
            return ['id_manufacturer' => (int) $row['id_manufacturer'], 'label' => $row['name']];
        }, $rows);
    }
    public function suppliers(): array
    {
        $rows = \Db::getInstance()->executeS('SELECT `id_supplier`, `name` FROM `' . _DB_PREFIX_ . 'supplier` ORDER BY `name`');
        if ($rows === false) {
            throw new \RuntimeException('Unable to list suppliers.');
        }
        return array_map(static function (array $row): array {
            return ['id_supplier' => (int) $row['id_supplier'], 'label' => $row['name']];
        }, $rows);
    }
}
