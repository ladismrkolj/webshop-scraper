<?php
/**
 * Remembers that a given product came from a given feed row.
 *
 * Without this the importer could not tell an imported product from one the
 * shop owner added by hand, and "remove what disappeared from the feed" would
 * be free to delete the wrong catalogue.
 */
class CsvSyncLink extends ObjectModel
{
    /** @var int */
    public $id_csvsync_source;

    /** @var string the feed's own identifier for the row */
    public $external_id;

    /** @var int */
    public $id_product;

    /** @var string hash of the mapped row, so unchanged rows cost no writes */
    public $row_hash;

    /** @var string hash of the image URL list, so images are re-fetched only when they change */
    public $image_hash;

    /** @var string last run that still found this row in the feed */
    public $date_seen;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'csvsync_link',
        'primary' => 'id_csvsync_link',
        'fields' => [
            'id_csvsync_source' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'external_id' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 255],
            'id_product' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'row_hash' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 40],
            'image_hash' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 40],
            'date_seen' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * @return CsvSyncLink|null
     */
    public static function findByExternalId($id_source, $external_id)
    {
        $sql = new DbQuery();
        $sql->select('id_csvsync_link')
            ->from('csvsync_link')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->where('external_id = "' . pSQL($external_id) . '"');
        $id = (int) Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    /**
     * @return CsvSyncLink|null
     */
    public static function findByProduct($id_source, $id_product)
    {
        $sql = new DbQuery();
        $sql->select('id_csvsync_link')
            ->from('csvsync_link')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->where('id_product = ' . (int) $id_product);
        $id = (int) Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    /**
     * Rows of this feed that the latest run did not see again.
     *
     * @return array[] id_csvsync_link, id_product, external_id
     */
    public static function getStale($id_source, $seen_since)
    {
        $sql = new DbQuery();
        $sql->select('id_csvsync_link, id_product, external_id')
            ->from('csvsync_link')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->where('date_seen < "' . pSQL($seen_since) . '"');

        return Db::getInstance()->executeS($sql) ?: [];
    }

    public static function countForSource($id_source)
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)')->from('csvsync_link')->where('id_csvsync_source = ' . (int) $id_source);

        return (int) Db::getInstance()->getValue($sql);
    }
}
