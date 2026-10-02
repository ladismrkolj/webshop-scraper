<?php

namespace ProductImport\Service;

class ProductDiscountSetter
{
    public function apply(int $idProduct, ?float $regularPrice, ?float $currentPrice): void
    {
        $shopId = (int) \Context::getContext()->shop->id;
        $date = \SpecificPrice::ORDER_DEFAULT_DATE;
        $sql = 'SELECT `id_specific_price` FROM `' . _DB_PREFIX_ . 'specific_price` WHERE `id_product` = ' . $idProduct
            . ' AND `id_product_attribute` = 0 AND `id_shop` = ' . $shopId
            . ' AND `id_shop_group` = 0 AND `id_cart` = 0 AND `id_currency` = 0'
            . ' AND `id_country` = 0 AND `id_group` = 0 AND `id_customer` = 0'
            . ' AND `from_quantity` = 1 AND `from` = \'' . pSQL($date) . '\' AND `to` = \'' . pSQL($date) . '\'';
        $rows = \Db::getInstance()->executeS($sql);
        if ($rows === false) {
            throw new \RuntimeException('Unable to find product discount.');
        }
        $hasDiscount = $regularPrice !== null && $currentPrice !== null && $regularPrice > $currentPrice;
        foreach ($rows as $index => $row) {
            $discount = new \SpecificPrice((int) $row['id_specific_price']);
            if (!\Validate::isLoadedObject($discount)) {
                throw new \RuntimeException('Unable to load product discount.');
            }
            if ($hasDiscount && $index === 0) {
                $discount->price = -1;
                $discount->reduction = $regularPrice - $currentPrice;
                $discount->reduction_tax = 0;
                $discount->reduction_type = 'amount';
                if (!$discount->update()) {
                    throw new \RuntimeException('Unable to update product discount.');
                }
            } elseif (!$discount->delete()) {
                throw new \RuntimeException('Unable to delete product discount.');
            }
        }
        if (!$hasDiscount || $rows !== []) {
            return;
        }
        $discount = new \SpecificPrice();
        $discount->id_product = $idProduct;
        $discount->id_product_attribute = 0;
        $discount->id_shop = $shopId;
        $discount->id_shop_group = 0;
        $discount->id_cart = 0;
        $discount->id_currency = 0;
        $discount->id_country = 0;
        $discount->id_group = 0;
        $discount->id_customer = 0;
        $discount->from_quantity = 1;
        $discount->from = $date;
        $discount->to = $date;
        $discount->price = -1;
        $discount->reduction = $regularPrice - $currentPrice;
        $discount->reduction_tax = 0;
        $discount->reduction_type = 'amount';
        if (!$discount->add()) {
            throw new \RuntimeException('Unable to create product discount.');
        }
    }
}
