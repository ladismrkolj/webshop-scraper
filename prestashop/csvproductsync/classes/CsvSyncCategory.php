<?php
/**
 * One category value seen in a feed, and the shop category it should become.
 *
 * Feeds invent new categories over their lifetime, so this table is filled by
 * a scan that can be re-run at any time: values already in it keep the mapping
 * they were given, values that are new arrive unmapped and wait for a decision.
 */
class CsvSyncCategory extends ObjectModel
{
    /** Waiting for someone to decide. */
    const STATUS_NEW = 'new';
    /** Send products here. */
    const STATUS_MAPPED = 'mapped';
    /** Deliberately not a category in this shop. */
    const STATUS_IGNORED = 'ignored';

    /** @var int */
    public $id_csvsync_source;

    /** @var string the value as it appears in the feed, after the column's transform */
    public $csv_value;

    /** @var int shop category, 0 while unmapped */
    public $id_category;

    /** @var string */
    public $status = self::STATUS_NEW;

    /** @var int rows carrying this value at the last scan */
    public $occurrences = 0;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'csvsync_category',
        'primary' => 'id_csvsync_category',
        'fields' => [
            'id_csvsync_source' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'csv_value' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 512],
            'id_category' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'occurrences' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * @return array[] raw rows, newest-unmapped first so new values are the
     *                 first thing on screen after a re-scan
     */
    public static function getForSource($id_source, $status = null)
    {
        $sql = new DbQuery();
        $sql->select('*')
            ->from('csvsync_category')
            ->where('id_csvsync_source = ' . (int) $id_source);
        if ($status !== null) {
            $sql->where('status = "' . pSQL($status) . '"');
        }
        $sql->orderBy('FIELD(status, "new", "mapped", "ignored"), occurrences DESC, csv_value ASC');

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * The lookup the importer uses on every row.
     *
     * @return array csv value => ['id_category' => int, 'status' => string]
     */
    public static function getMap($id_source)
    {
        $map = [];
        foreach (self::getForSource($id_source) as $row) {
            $map[$row['csv_value']] = [
                'id_category' => (int) $row['id_category'],
                'status' => $row['status'],
            ];
        }

        return $map;
    }

    public static function countByStatus($id_source)
    {
        $counts = [self::STATUS_NEW => 0, self::STATUS_MAPPED => 0, self::STATUS_IGNORED => 0];
        $sql = new DbQuery();
        $sql->select('status, COUNT(*) AS total')
            ->from('csvsync_category')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->groupBy('status');
        foreach (Db::getInstance()->executeS($sql) ?: [] as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Records the values a scan found.
     *
     * Existing values keep their mapping and only have their occurrence count
     * refreshed, which is what makes re-scanning safe: a scan can only ever
     * add rows to decide on, never undo a decision already made.
     *
     * @param array $values csv value => occurrences
     *
     * @return array ['added' => int, 'seen' => int]
     */
    public static function record($id_source, array $values)
    {
        $existing = [];
        foreach (self::getForSource($id_source) as $row) {
            $existing[$row['csv_value']] = (int) $row['id_csvsync_category'];
        }

        $added = 0;
        foreach ($values as $value => $occurrences) {
            $value = Tools::substr(trim((string) $value), 0, 512);
            if ($value === '') {
                continue;
            }
            if (isset($existing[$value])) {
                Db::getInstance()->update(
                    'csvsync_category',
                    ['occurrences' => (int) $occurrences, 'date_upd' => pSQL(date('Y-m-d H:i:s'))],
                    'id_csvsync_category = ' . (int) $existing[$value]
                );
                continue;
            }

            $category = new self();
            $category->id_csvsync_source = (int) $id_source;
            $category->csv_value = $value;
            $category->id_category = 0;
            $category->status = self::STATUS_NEW;
            $category->occurrences = (int) $occurrences;
            if ($category->add()) {
                ++$added;
            }
        }

        return ['added' => $added, 'seen' => count($values)];
    }

    public static function deleteForSource($id_source)
    {
        return Db::getInstance()->delete('csvsync_category', 'id_csvsync_source = ' . (int) $id_source);
    }
}
