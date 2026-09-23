<?php

namespace ProductImport\Service;

/** Small injectable boundary for catalog operations not owned by the existing importers. */
class ImportCatalog
{
    public function savedPrice(int $idProduct): float
    {
        return (float) $this->product($idProduct)->price;
    }

    public function deactivateProduct(int $idProduct): void
    {
        $product = $this->product($idProduct);
        $product->active = false;
        if (!$product->save()) {
            throw new \RuntimeException('Unable to deactivate product ' . $idProduct);
        }
    }

    public function zeroCombination(int $idProduct, int $idCombination): void
    {
        \StockAvailable::setQuantity($idProduct, $idCombination, 0);
    }

    private function product(int $id): \Product
    {
        $product = new \Product($id);
        if (!\Validate::isLoadedObject($product)) {
            throw new \RuntimeException('Product does not exist: ' . $id);
        }
        return $product;
    }
}
