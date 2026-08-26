<?php
/**
 * Answers "what would this import do?" without touching the catalogue.
 *
 * Two halves, because they answer different questions: a sample of rows shown
 * field by field (is the mapping right?) and a full pass over the feed that
 * counts the verdicts (is the run safe?). Nothing here writes.
 */
class CsvSyncPreview
{
    const ACTION_CREATE = 'create';
    const ACTION_UPDATE = 'update';
    const ACTION_UNCHANGED = 'unchanged';
    const ACTION_SKIP = 'skip';

    /** @var CsvSyncSource */
    private $source;

    /** @var CsvSyncRowMapper */
    private $mapper;

    public function __construct(CsvSyncSource $source)
    {
        $this->source = $source;
        $this->mapper = new CsvSyncRowMapper($source);
    }

    /**
     * @param int $limit rows to render
     *
     * @return array ['problems' => string[], 'rows' => array[], 'header' => string[]]
     *
     * @throws Exception
     */
    public function sample($limit = 10)
    {
        $problems = $this->mapper->validate();
        $rows = [];

        $reader = new CsvSyncReader($this->source);
        try {
            $reader->open();
            $seen = [];
            while (count($rows) < (int) $limit && ($row = $reader->next()) !== null) {
                $mapped = $this->mapper->map($row);
                $key = $this->mapper->key($mapped);
                $verdict = $this->verdict($key, $mapped, $seen);

                $rows[] = [
                    'line' => $reader->getLineNumber(),
                    'key' => $key,
                    'action' => $verdict['action'],
                    'reason' => $verdict['reason'],
                    'id_product' => $verdict['id_product'],
                    'fields' => $this->flatten($mapped),
                    'categories' => $this->previewCategories($mapped),
                    'raw' => $row,
                ];
            }

            return ['problems' => $problems, 'rows' => $rows, 'header' => $reader->getHeader()];
        } finally {
            $reader->close();
        }
    }

    /**
     * A full dry run: every row is mapped and judged, nothing is written.
     *
     * @return array counters plus the removals the run would perform
     *
     * @throws Exception
     */
    public function fullRun()
    {
        $problems = $this->mapper->validate();
        $counts = [
            self::ACTION_CREATE => 0,
            self::ACTION_UPDATE => 0,
            self::ACTION_UNCHANGED => 0,
            self::ACTION_SKIP => 0,
        ];
        $reasons = [];
        $unmapped_categories = [];
        $seen = [];
        $seen_products = [];

        $reader = new CsvSyncReader($this->source);
        try {
            $reader->open();
            while (($row = $reader->next()) !== null) {
                $mapped = $this->mapper->map($row);
                $key = $this->mapper->key($mapped);
                $verdict = $this->verdict($key, $mapped, $seen);
                ++$counts[$verdict['action']];
                if ($verdict['reason'] !== '') {
                    $reasons[$verdict['reason']] = isset($reasons[$verdict['reason']]) ? $reasons[$verdict['reason']] + 1 : 1;
                }
                if ($verdict['id_product']) {
                    $seen_products[(int) $verdict['id_product']] = true;
                }
                foreach ($this->previewCategories($mapped) as $category) {
                    if ($category['status'] === CsvSyncCategory::STATUS_NEW || $category['status'] === 'unknown') {
                        $unmapped_categories[$category['value']] = true;
                    }
                }
            }
        } finally {
            $reader->close();
        }

        return [
            'problems' => $problems,
            'counts' => $counts,
            'reasons' => $reasons,
            'unmapped_categories' => array_keys($unmapped_categories),
            'removals' => $this->previewRemovals($seen_products),
        ];
    }

    /**
     * What the run would do to products the feed no longer lists, including
     * whether the safety limit would stop it.
     *
     * @return array
     */
    private function previewRemovals(array $seen_products)
    {
        $total = CsvSyncLink::countForSource((int) $this->source->id);
        $missing = [];
        $sql = new DbQuery();
        $sql->select('id_product, external_id')
            ->from('csvsync_link')
            ->where('id_csvsync_source = ' . (int) $this->source->id);
        foreach (Db::getInstance()->executeS($sql) ?: [] as $row) {
            if (!isset($seen_products[(int) $row['id_product']])) {
                $missing[] = $row;
            }
        }

        $percent = $total > 0 ? (count($missing) / $total) * 100 : 0;
        $blocked = $this->source->missing_max_percent > 0 && $percent > (int) $this->source->missing_max_percent;

        return [
            'action' => $this->source->missing_action,
            'count' => count($missing),
            'linked_total' => $total,
            'percent' => round($percent, 1),
            'blocked_by_safety_limit' => $blocked,
            'sample' => array_slice($missing, 0, 20),
        ];
    }

    /**
     * @return array ['action' => string, 'reason' => string, 'id_product' => int]
     */
    private function verdict($key, array $mapped, array &$seen)
    {
        if ($key === '') {
            return ['action' => self::ACTION_SKIP, 'reason' => 'no value for the matching field', 'id_product' => 0];
        }
        if (isset($seen[$key])) {
            return ['action' => self::ACTION_SKIP, 'reason' => 'duplicate of an earlier row', 'id_product' => 0];
        }
        $seen[$key] = true;

        $link = CsvSyncLink::findByExternalId((int) $this->source->id, $key);
        if ($link && Validate::isLoadedObject(new Product((int) $link->id_product))) {
            if ($link->row_hash === $this->mapper->hash($mapped)) {
                return ['action' => self::ACTION_UNCHANGED, 'reason' => '', 'id_product' => (int) $link->id_product];
            }

            return ['action' => self::ACTION_UPDATE, 'reason' => '', 'id_product' => (int) $link->id_product];
        }

        if (!$this->source->create_missing_products) {
            return ['action' => self::ACTION_SKIP, 'reason' => 'new product, and this source may not create products', 'id_product' => 0];
        }

        return ['action' => self::ACTION_CREATE, 'reason' => '', 'id_product' => 0];
    }

    /**
     * @return array[] value, status, and the shop category it resolves to
     */
    private function previewCategories(array $mapped)
    {
        $values = $this->mapper->categoryValues($mapped);
        if (!$values) {
            return [];
        }

        if ($this->source->category_mode !== CsvSyncSource::CATEGORY_MAP) {
            return array_map(function ($value) {
                return ['value' => $value, 'status' => 'auto', 'target' => $value . ' (created if missing)'];
            }, $values);
        }

        $map = CsvSyncCategory::getMap((int) $this->source->id);
        $preview = [];
        foreach ($values as $value) {
            if (!isset($map[$value])) {
                $preview[] = ['value' => $value, 'status' => 'unknown', 'target' => 'not scanned yet'];
                continue;
            }
            $entry = $map[$value];
            if ($entry['status'] === CsvSyncCategory::STATUS_IGNORED) {
                $preview[] = ['value' => $value, 'status' => $entry['status'], 'target' => 'ignored'];
                continue;
            }
            if ($entry['status'] !== CsvSyncCategory::STATUS_MAPPED || !$entry['id_category']) {
                $preview[] = ['value' => $value, 'status' => CsvSyncCategory::STATUS_NEW, 'target' => 'not mapped yet'];
                continue;
            }
            $category = new Category((int) $entry['id_category'], (int) $this->source->id_lang);
            $preview[] = [
                'value' => $value,
                'status' => CsvSyncCategory::STATUS_MAPPED,
                'target' => Validate::isLoadedObject($category) ? $category->name : 'category #' . (int) $entry['id_category'],
            ];
        }

        return $preview;
    }

    /**
     * @return array[] label => value, for the sample table
     */
    private function flatten(array $mapped)
    {
        $flat = [];
        foreach (['product', 'lang', 'special'] as $bucket) {
            foreach ($mapped[$bucket] as $field => $value) {
                $flat[] = ['label' => CsvSyncFields::label($field), 'value' => Tools::substr((string) $value, 0, 300)];
            }
        }
        foreach ($mapped['features'] as $name => $value) {
            $flat[] = ['label' => 'Feature: ' . $name, 'value' => Tools::substr((string) $value, 0, 300)];
        }

        return $flat;
    }
}
