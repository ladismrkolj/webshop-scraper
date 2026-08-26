<?php
/**
 * One CSV feed: where it lives, how it is parsed and what the importer is
 * allowed to change when it runs.
 */
class CsvSyncSource extends ObjectModel
{
    /** Everything the importer may do with a product that fell out of the feed. */
    const MISSING_NOTHING = 'nothing';
    const MISSING_DISABLE = 'disable';
    const MISSING_OUT_OF_STOCK = 'out_of_stock';
    const MISSING_DELETE = 'delete';

    /** Create whatever category path the feed names. */
    const CATEGORY_AUTO = 'auto';
    /** Only use the categories someone mapped by hand. */
    const CATEGORY_MAP = 'map';

    /** Products whose category is not mapped yet go to the default category. */
    const UNMAPPED_DEFAULT = 'default';
    /** Import the product, but leave its categories alone. */
    const UNMAPPED_SKIP_CATEGORY = 'skip_category';
    /** Do not import the product at all until its category is decided. */
    const UNMAPPED_SKIP_PRODUCT = 'skip_product';

    /** @var string */
    public $name;

    /** @var string local path or http(s) URL of the CSV */
    public $location;

    /** @var string */
    public $delimiter = ',';

    /** @var string */
    public $enclosure = '"';

    /** @var string */
    public $encoding = 'UTF-8';

    /** @var bool the first row holds column names */
    public $has_header = true;

    /** @var int */
    public $id_lang;

    /** @var int */
    public $id_shop;

    /** @var int fallback category for products the feed does not place */
    public $id_category_default;

    /** @var int */
    public $id_tax_rules_group = 0;

    /** @var string which product field identifies a row: external_id|reference|ean13|mpn|name */
    public $match_by = 'external_id';

    /** @var bool create products that are in the feed but not in the shop */
    public $create_missing_products = true;

    /** @var bool new products are created enabled */
    public $activate_new_products = false;

    /** @var bool */
    public $update_price = true;

    /** @var bool */
    public $update_stock = true;

    /** @var bool names, descriptions, meta */
    public $update_text = false;

    /** @var bool */
    public $update_images = false;

    /** @var bool */
    public $update_categories = false;

    /** @var string auto|map — invent the feed's categories, or use the mapping table */
    public $category_mode = self::CATEGORY_AUTO;

    /** @var string what to do with a row whose category nobody has mapped yet */
    public $unmapped_category_action = self::UNMAPPED_DEFAULT;

    /** @var string what to do with products that vanished from the feed */
    public $missing_action = self::MISSING_DISABLE;

    /**
     * A feed that suddenly lost most of its rows is far more likely to be a
     * broken scrape than a real clearance, so runs that shrink beyond this
     * percentage refuse to apply the missing_action.
     *
     * @var int
     */
    public $missing_max_percent = 30;

    /** @var float multiplier applied to every mapped price (1 = feed price as-is) */
    public $price_multiplier = 1.0;

    /** @var bool the feed's prices include tax and must be converted */
    public $price_tax_included = false;

    /** @var int decimals to round the final price to */
    public $price_round = 2;

    /** @var bool */
    public $active = true;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'csvsync_source',
        'primary' => 'id_csvsync_source',
        'fields' => [
            'name' => ['type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true, 'size' => 128],
            'location' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 1024],
            'delimiter' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 4],
            'enclosure' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 4],
            'encoding' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'has_header' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'id_lang' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_shop' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_category_default' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_tax_rules_group' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'match_by' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'create_missing_products' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'activate_new_products' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'update_price' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'update_stock' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'update_text' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'update_images' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'update_categories' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'category_mode' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'unmapped_category_action' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'missing_action' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 32],
            'missing_max_percent' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'price_multiplier' => ['type' => self::TYPE_FLOAT, 'validate' => 'isFloat'],
            'price_tax_included' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'price_round' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'active' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * @return CsvSyncSource[]
     */
    public static function getSources($only_active = false)
    {
        $sql = new DbQuery();
        $sql->select('id_csvsync_source')->from('csvsync_source');
        if ($only_active) {
            $sql->where('active = 1');
        }
        $sql->orderBy('name ASC');

        $sources = [];
        foreach (Db::getInstance()->executeS($sql) ?: [] as $row) {
            $sources[] = new self((int) $row['id_csvsync_source']);
        }

        return $sources;
    }

    public static function categoryModes()
    {
        return [self::CATEGORY_AUTO, self::CATEGORY_MAP];
    }

    public static function unmappedCategoryActions()
    {
        return [self::UNMAPPED_DEFAULT, self::UNMAPPED_SKIP_CATEGORY, self::UNMAPPED_SKIP_PRODUCT];
    }

    public static function missingActions()
    {
        return [
            self::MISSING_NOTHING,
            self::MISSING_DISABLE,
            self::MISSING_OUT_OF_STOCK,
            self::MISSING_DELETE,
        ];
    }

    /**
     * @return CsvSyncMapping[]
     */
    public function getMappings()
    {
        return CsvSyncMapping::getForSource((int) $this->id);
    }

    public function delete()
    {
        Db::getInstance()->delete('csvsync_mapping', 'id_csvsync_source = ' . (int) $this->id);
        Db::getInstance()->delete('csvsync_link', 'id_csvsync_source = ' . (int) $this->id);
        Db::getInstance()->delete('csvsync_run', 'id_csvsync_source = ' . (int) $this->id);
        Db::getInstance()->delete('csvsync_category', 'id_csvsync_source = ' . (int) $this->id);

        return parent::delete();
    }
}
