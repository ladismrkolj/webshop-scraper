<?php

namespace ProductImport\Repository;

class ExternalCombinationRepository
{
    public function findCombinationId(int $idSource, string $externalId): ?int
    {
        $id = \Db::getInstance()->getValue(
            'SELECT `id_product_attribute` FROM `' . _DB_PREFIX_ . 'pi_external_combination` WHERE `id_source` = ' . (int) $idSource
            . " AND `external_id` = '" . pSQL($externalId, true) . "'"
        );

        return $id === false ? null : (int) $id;
    }

    public function link(int $idSource, string $externalId, int $idProductAttribute, int $idProduct): void
    {
        if (!\Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . 'pi_external_combination` (`id_source`, `external_id`, `id_product_attribute`, `id_product`, `date_upd`) VALUES ('
            . (int) $idSource . ", '" . pSQL($externalId, true) . "', " . (int) $idProductAttribute . ", " . (int) $idProduct . ", '" . pSQL(date('Y-m-d H:i:s'))
            . "') ON DUPLICATE KEY UPDATE `id_product_attribute` = VALUES(`id_product_attribute`), `id_product` = VALUES(`id_product`), `date_upd` = VALUES(`date_upd`)"
        )) {
            throw new \RuntimeException('Unable to link external combination.');
        }
    }
}
