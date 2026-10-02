<?php

namespace ProductImport\Service;

use ProductImport\Repository\ExternalProductRepository;

class ProductImporter
{
    private $products;

    public function __construct(ExternalProductRepository $products)
    {
        $this->products = $products;
    }

    public function import(
        int $idSource,
        string $externalId,
        array $mappedValues,
        array $categoryIds,
        ?int $idManufacturer,
        int $idLangDefault
    ): int {
        if ($externalId === '' || \Tools::strlen($externalId) > 191) {
            throw new \InvalidArgumentException('External product ID must contain 1 to 191 characters.');
        }
        $id = $this->products->findProductId($idSource, $externalId);
        $product = $id === null ? new \Product() : new \Product($id);
        if ($id !== null && !\Validate::isLoadedObject($product)) {
            throw new \RuntimeException('Linked product no longer exists: ' . $id);
        }
        $isNew = $id === null;
        if ($isNew && trim((string) ($mappedValues['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('A name is required to create a product.');
        }
        foreach (['name' => 'name', 'description' => 'description', 'short_description' => 'description_short'] as $target => $property) {
            if (isset($mappedValues[$target])) {
                $translations = is_array($product->$property) ? $product->$property : [];
                $translations[$idLangDefault] = (string) $mappedValues[$target];
                $product->$property = $translations;
            }
        }
        if (isset($mappedValues['name'])) {
            $slugs = is_array($product->link_rewrite) ? $product->link_rewrite : [];
            $slugs[$idLangDefault] = \Tools::str2url((string) $mappedValues['name']) ?: 'product';
            $product->link_rewrite = $slugs;
        }
        // ObjectModel requires name/link_rewrite in the shop default language too.
        if ($isNew) {
            $shopLanguage = (int) \Configuration::get('PS_LANG_DEFAULT');
            $product->name[$shopLanguage] = $product->name[$shopLanguage] ?? $product->name[$idLangDefault];
            $product->link_rewrite[$shopLanguage] = $product->link_rewrite[$shopLanguage] ?? $product->link_rewrite[$idLangDefault];
            $product->id_shop_default = (int) \Context::getContext()->shop->id;
        }
        foreach (['reference', 'ean13'] as $field) {
            if (isset($mappedValues[$field])) {
                $product->$field = (string) $mappedValues[$field];
            }
        }
        if (isset($mappedValues['price'])) {
            $product->price = (float) $mappedValues['price'];
        }
        if (isset($mappedValues['weight'])) {
            $product->weight = (float) $mappedValues['weight'];
        } elseif ($isNew) {
            $product->weight = 0.0;
        }
        if (isset($mappedValues['active'])) {
            $product->active = (bool) $mappedValues['active'];
        } elseif ($isNew) {
            $product->active = true;
        }
        if ($idManufacturer !== null) {
            $product->id_manufacturer = $idManufacturer;
        }
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds !== []) {
            $product->id_category_default = $categoryIds[0];
        } elseif ($isNew) {
            $product->id_category_default = (int) \Configuration::get('PS_HOME_CATEGORY');
        }
        if (!$product->save()) {
            throw new \RuntimeException('Unable to save product: ' . $externalId);
        }
        if ($categoryIds !== [] && !$product->updateCategories($categoryIds)) {
            throw new \RuntimeException('Unable to update product categories: ' . $externalId);
        }
        if ($isNew && $categoryIds === [] && !$product->updateCategories([(int) $product->id_category_default])) {
            throw new \RuntimeException('Unable to associate new product with its default category.');
        }
        if (isset($mappedValues['quantity'])) {
            \StockAvailable::setQuantity((int) $product->id, 0, (int) $mappedValues['quantity']);
        }
        $this->importImages($product, $mappedValues);
        $this->products->link($idSource, $externalId, (int) $product->id);

        return (int) $product->id;
    }

    private function importImages(\Product $product, array $values): void
    {
        $urls = [];
        foreach (is_array($values['images'] ?? null) ? $values['images'] : [] as $url) {
            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }
        $main = is_string($values['main_image'] ?? null) ? trim($values['main_image']) : '';
        $main = $main !== '' ? $main : ($urls[0] ?? '');
        if ($main !== '') {
            // Attempt the preferred cover first, including a standalone main_image.
            array_unshift($urls, $main);
        }
        foreach (array_unique($urls) as $url) {
            try {
                (new ProductImageAttacher())->attach((int) $product->id, $url);
            } catch (\Throwable $error) {
                \PrestaShopLogger::addLog('Product import image skipped: ' . $error->getMessage(), 2, null, 'Product', (int) $product->id);
            }
        }
    }

}
