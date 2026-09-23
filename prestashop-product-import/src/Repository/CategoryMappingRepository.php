<?php

namespace ProductImport\Repository;

class CategoryMappingRepository
{
    public function findOverride(int $idSource, string $hash): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'pi_category_mapping` WHERE `id_source` = ' . (int) $idSource
            . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        );

        return $row ?: null;
    }

    public function upsertSeen(int $idSource, string $hash, string $sourcePath): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_category_mapping` (`id_source`, `source_path_hash`, `source_path`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($hash) . "', '" . pSQL($sourcePath, true) . "', '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException('Unable to record category path.');
        }
    }

    public function setOverride(int $idSource, string $hash, ?int $idCategory): void
    {
        // Only discovered paths can be edited: the hash alone cannot recover source_path.
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_category_mapping` SET `id_category` = '
            . ($idCategory === null ? 'NULL' : (int) $idCategory)
            . ", `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "' WHERE `id_source` = " . (int) $idSource
            . " AND `source_path_hash` = '" . pSQL($hash) . "'"
        )) {
            throw new \RuntimeException('Unable to save category override.');
        }
    }
}
