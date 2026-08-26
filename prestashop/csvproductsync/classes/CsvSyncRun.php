<?php
/**
 * The record of one import: what it changed and, when it went wrong, why.
 */
class CsvSyncRun extends ObjectModel
{
    const STATUS_RUNNING = 'running';
    const STATUS_SUCCESS = 'success';
    const STATUS_PARTIAL = 'partial';
    const STATUS_ERROR = 'error';

    /** @var int */
    public $id_csvsync_source;

    /** @var string */
    public $status = self::STATUS_RUNNING;

    /** @var string manual|cron */
    public $trigger_type = 'manual';

    /** @var int */
    public $rows_read = 0;

    /** @var int */
    public $products_created = 0;

    /** @var int */
    public $products_updated = 0;

    /** @var int */
    public $products_unchanged = 0;

    /** @var int */
    public $products_removed = 0;

    /** @var int */
    public $rows_skipped = 0;

    /** @var string first lines of trouble, newline separated */
    public $message;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table' => 'csvsync_run',
        'primary' => 'id_csvsync_run',
        'fields' => [
            'id_csvsync_source' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'trigger_type' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 16],
            'rows_read' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'products_created' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'products_updated' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'products_unchanged' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'products_removed' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'rows_skipped' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'],
            'message' => ['type' => self::TYPE_HTML, 'validate' => 'isCleanHtml'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * @return CsvSyncRun|null
     */
    public static function getLastForSource($id_source)
    {
        $sql = new DbQuery();
        $sql->select('id_csvsync_run')
            ->from('csvsync_run')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->orderBy('id_csvsync_run DESC');
        $id = (int) Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    /**
     * A run left behind by a crashed PHP process would otherwise block the
     * next one forever, so anything older than the lock timeout is fair game.
     */
    public static function hasRunningRun($id_source, $stale_after_seconds = 7200)
    {
        $sql = new DbQuery();
        $sql->select('COUNT(*)')
            ->from('csvsync_run')
            ->where('id_csvsync_source = ' . (int) $id_source)
            ->where('status = "' . pSQL(self::STATUS_RUNNING) . '"')
            ->where('date_upd > "' . pSQL(date('Y-m-d H:i:s', time() - (int) $stale_after_seconds)) . '"');

        return (int) Db::getInstance()->getValue($sql) > 0;
    }
}
