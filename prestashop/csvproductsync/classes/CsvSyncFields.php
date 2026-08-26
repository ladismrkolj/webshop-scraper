<?php
/**
 * The PrestaShop side of the mapping table.
 *
 * Everything the importer knows how to write is declared here once: the
 * mapping screen builds its dropdown from this list and the importer asks it
 * how each target behaves, so adding a field is a one-line change.
 */
class CsvSyncFields
{
    /** Written straight onto the Product object. */
    const KIND_PRODUCT = 'product';
    /** Written onto the Product object, once per language. */
    const KIND_LANG = 'lang';
    /** Needs its own handling in the importer (stock, images, categories...). */
    const KIND_SPECIAL = 'special';

    /**
     * @return array field key => ['label' => string, 'kind' => string, 'group' => string, 'hint' => string]
     */
    public static function all()
    {
        $t = function ($string) {
            return Context::getContext()->getTranslator()->trans($string, [], 'Modules.Csvproductsync.Admin');
        };

        return [
            // --- identity ------------------------------------------------
            'external_id' => ['label' => $t('External ID (feed key)'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Identity'), 'hint' => $t('The feed\'s own product id. Map this so the importer can follow a product across runs even when its name or price changes.')],
            'reference' => ['label' => $t('Reference'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Identity')],
            'ean13' => ['label' => $t('EAN-13'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Identity')],
            'upc' => ['label' => $t('UPC'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Identity')],
            'isbn' => ['label' => $t('ISBN'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Identity')],
            'mpn' => ['label' => $t('MPN'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Identity')],
            'supplier_reference' => ['label' => $t('Supplier reference'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Identity')],

            // --- text ----------------------------------------------------
            'name' => ['label' => $t('Name'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],
            'description' => ['label' => $t('Description'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],
            'description_short' => ['label' => $t('Short description'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],
            'link_rewrite' => ['label' => $t('Friendly URL'), 'kind' => self::KIND_LANG, 'group' => $t('Text'), 'hint' => $t('Left empty, it is generated from the name.')],
            'meta_title' => ['label' => $t('Meta title'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],
            'meta_description' => ['label' => $t('Meta description'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],
            'available_now' => ['label' => $t('Availability label (in stock)'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],
            'available_later' => ['label' => $t('Availability label (out of stock)'), 'kind' => self::KIND_LANG, 'group' => $t('Text')],

            // --- price ---------------------------------------------------
            'price' => ['label' => $t('Price'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Price'), 'hint' => $t('The source\'s price rules (multiplier, tax, rounding) are applied on top of this value.')],
            'wholesale_price' => ['label' => $t('Wholesale price'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Price')],
            'id_tax_rules_group' => ['label' => $t('Tax rule group ID'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Price')],
            'on_sale' => ['label' => $t('On sale flag'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Price')],

            // --- stock ---------------------------------------------------
            'quantity' => ['label' => $t('Quantity'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Stock')],
            'in_stock' => ['label' => $t('In stock (yes/no)'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Stock'), 'hint' => $t('For feeds that only say whether a product is available. Yes becomes the default quantity below, no becomes 0.')],

            // --- catalogue -----------------------------------------------
            'manufacturer' => ['label' => $t('Brand / manufacturer name'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Catalogue'), 'hint' => $t('Created if it does not exist yet.')],
            'supplier' => ['label' => $t('Supplier name'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Catalogue'), 'hint' => $t('Created if it does not exist yet.')],
            'categories' => ['label' => $t('Categories'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Catalogue'), 'hint' => $t('A category path such as "Boards > Kites", or several paths separated by "|". Missing categories are created.')],
            'images' => ['label' => $t('Image URLs'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Catalogue'), 'hint' => $t('One URL, or several separated by "|" or ",". The first becomes the cover.')],
            'tags' => ['label' => $t('Tags'), 'kind' => self::KIND_SPECIAL, 'group' => $t('Catalogue')],

            // --- flags and shipping --------------------------------------
            'active' => ['label' => $t('Enabled'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'available_for_order' => ['label' => $t('Available for order'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'show_price' => ['label' => $t('Show price'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'condition' => ['label' => $t('Condition'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other'), 'hint' => $t('new, used or refurbished.')],
            'visibility' => ['label' => $t('Visibility'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'weight' => ['label' => $t('Weight'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'width' => ['label' => $t('Width'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'height' => ['label' => $t('Height'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
            'depth' => ['label' => $t('Depth'), 'kind' => self::KIND_PRODUCT, 'group' => $t('Other')],
        ];
    }

    /**
     * The dropdown on the mapping screen, grouped the way the list above is.
     *
     * @return array group label => [field key => field label]
     */
    public static function grouped()
    {
        $grouped = [];
        foreach (self::all() as $key => $field) {
            $grouped[$field['group']][$key] = $field['label'];
        }

        return $grouped;
    }

    public static function exists($key)
    {
        return isset(self::all()[$key]) || self::isFeature($key);
    }

    /**
     * "feature:Board volume" writes to the product feature of that name,
     * which is the one target that cannot be enumerated in advance.
     */
    public static function isFeature($key)
    {
        return strpos((string) $key, 'feature:') === 0;
    }

    public static function featureName($key)
    {
        return trim(substr((string) $key, strlen('feature:')));
    }

    public static function kind($key)
    {
        if (self::isFeature($key)) {
            return self::KIND_SPECIAL;
        }
        $all = self::all();

        return isset($all[$key]) ? $all[$key]['kind'] : null;
    }

    public static function label($key)
    {
        if (self::isFeature($key)) {
            return sprintf('Feature: %s', self::featureName($key));
        }
        $all = self::all();

        return isset($all[$key]) ? $all[$key]['label'] : $key;
    }

    /** Fields the source's match_by setting may point at. */
    public static function matchableFields()
    {
        return ['external_id', 'reference', 'ean13', 'mpn', 'upc', 'name'];
    }
}
