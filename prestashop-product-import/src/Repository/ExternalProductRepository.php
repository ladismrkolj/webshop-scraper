<?php

namespace ProductImport\Repository;

class ExternalProductRepository
{
    public function findProductId(int $idSource, string $externalId): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'"
        );

        return $id === false ? null : (int) $id;
    }

    public function link(int $idSource, string $externalId, int $idProduct, ?int $idRun = null): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_external_product` (`id_source`, `external_id`, `id_product`, `id_run_last_seen`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($externalId, true) . "', " . (int) $idProduct . ", " . ($idRun === null ? 'NULL' : (int) $idRun) . ", '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `id_product` = VALUES(`id_product`), `date_upd` = VALUES(`date_upd`), `id_run_last_seen` = COALESCE(VALUES(`id_run_last_seen`), `id_run_last_seen`)"
        )) {
            throw new \RuntimeException('Unable to link external product.');
        }
    }

    public function unlink(int $idSource, string $externalId): void
    {
        if (!\Db::getInstance()->execute('DELETE FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource . " AND `external_id` = '" . pSQL($externalId, true) . "'")) {
            throw new \RuntimeException('Unable to unlink external product.');
        }
    }

    public function findStaleForSource(int $idSource, int $currentRunId): array
    {
        $rows = \Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'pi_external_product` WHERE `id_source` = ' . (int) $idSource
            . ' AND (`id_run_last_seen` IS NULL OR `id_run_last_seen` <> ' . (int) $currentRunId . ')');
        if ($rows === false) {
            throw new \RuntimeException('Unable to find stale links.');
        }
        return $rows;
    }
}
