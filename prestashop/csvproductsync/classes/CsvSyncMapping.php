<?php
/**
 * One line of a source's mapping table: "CSV column X feeds PrestaShop field Y,
 * after running it through transform Z".
 */
class CsvSyncMapping extends ObjectModel
{
    /** @var int */
    public $id_csvsync_source;

    /** @var string column name (or 0-based index when the CSV has no header) */
    public $csv_column;

    /** @var string a key of CsvSyncFields::all(), or "feature:Colour" */
    public $ps_field;

    /** @var string a key of CsvSyncTransformer::all() */
    public $transform = 'none';

    /** @var string used when the CSV cell is empty */
    public $default_value;

    /** @var int */
    public $position = 0;

    public static $definition = [
        'table' => 'csvsync_mapping',
        'primary' => 'id_csvsync_mapping',
        'fields' => [
            'id_csvsync_source' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'csv_column' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 255],
            'ps_field' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 128],
            'transform' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 64],
            'default_value' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 1024],
            'position' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
        ],
    ];

    /**
     * @return CsvSyncMapping[]
     */
    public static function getForSource($id_source)
    {
        $sql = new DbQuery();
        $sql->select('id_csvsync_mapping')
            ->from('csvsync_mapping')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->orderBy('position ASC, id_csvsync_mapping ASC');

        $mappings = [];
        foreach (Db::getInstance()->executeS($sql) ?: [] as $row) {
            $mappings[] = new self((int) $row['id_csvsync_mapping']);
        }

        return $mappings;
    }

    public static function deleteForSource($id_source)
    {
        return Db::getInstance()->delete('csvsync_mapping', 'id_csvsync_source = ' . (int) $id_source);
    }
}
