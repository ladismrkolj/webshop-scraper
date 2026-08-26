<?php
/**
 * Walks a feed and collects every distinct category value in it.
 *
 * Run before the first import to learn what the feed offers, and again
 * whenever the shop it scrapes invents new categories: values already decided
 * on are left alone, so re-scanning only ever surfaces what is new.
 */
class CsvSyncCategoryScanner
{
    /** @var CsvSyncSource */
    private $source;

    public function __construct(CsvSyncSource $source)
    {
        $this->source = $source;
    }

    /**
     * @return array ['added' => int, 'distinct' => int, 'rows' => int, 'new_values' => string[]]
     *
     * @throws Exception when the feed cannot be read
     */
    public function scan()
    {
        $mapper = new CsvSyncRowMapper($this->source);
        if (!in_array('categories', $mapper->getTargets(), true)) {
            throw new Exception('Map a CSV column to the Categories field first — there is nothing to scan otherwise.');
        }

        $known = array_keys(CsvSyncCategory::getMap((int) $this->source->id));
        $counts = [];
        $rows = 0;

        $reader = new CsvSyncReader($this->source);
        try {
            $reader->open();
            while (($row = $reader->next()) !== null) {
                ++$rows;
                foreach ($mapper->categoryValues($mapper->map($row)) as $value) {
                    $value = Tools::substr($value, 0, 512);
                    $counts[$value] = isset($counts[$value]) ? $counts[$value] + 1 : 1;
                }
            }
        } finally {
            $reader->close();
        }

        $result = CsvSyncCategory::record((int) $this->source->id, $counts);
        $new_values = array_values(array_diff(array_keys($counts), $known));
        sort($new_values);

        return [
            'added' => $result['added'],
            'distinct' => count($counts),
            'rows' => $rows,
            'new_values' => $new_values,
        ];
    }
}
