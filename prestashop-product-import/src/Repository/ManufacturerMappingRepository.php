<?php

namespace ProductImport\Repository;

class ManufacturerMappingRepository
{
    public function findAllForSource(int $idSource): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` WHERE `id_source` = ' . (int) $idSource . ' ORDER BY `id_mapping`');
        if ($rows === false) {
            throw new \RuntimeException('Unable to list manufacturer mappings.');
        }
        return $rows;
    }

    public function findOverride(int $idSource, string $name): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` WHERE `id_source` = ' . (int) $idSource
            . " AND `source_name` = '" . pSQL($name, true) . "'"
        );

        return $row ?: null;
    }

    public function upsertSeen(int $idSource, string $name): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` (`id_source`, `source_name`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($name, true) . "', '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException('Unable to record manufacturer name.');
        }
    }

    public function setOverride(int $idSource, string $name, ?int $idManufacturer): void
    {
        // Only previously discovered names can be edited.
        if (!\Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'pi_manufacturer_mapping` SET `id_manufacturer` = '
            . ($idManufacturer === null ? 'NULL' : (int) $idManufacturer)
            . ", `date_upd` = '" . pSQL(date('Y-m-d H:i:s')) . "' WHERE `id_source` = " . (int) $idSource
            . " AND `source_name` = '" . pSQL($name, true) . "'"
        )) {
            throw new \RuntimeException('Unable to save manufacturer override.');
        }
    }
}
