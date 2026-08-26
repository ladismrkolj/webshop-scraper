<?php
/**
 * Applies a source's mapping table to one CSV row.
 *
 * The importer, the preview and the category scan all have to agree on what a
 * row means, down to the last transform and price rule, or the preview would
 * be a lie. So they all go through here.
 */
class CsvSyncRowMapper
{
    /** @var CsvSyncSource */
    private $source;

    /** @var CsvSyncMapping[] */
    private $mappings;

    /**
     * @param CsvSyncMapping[]|null $mappings loaded from the source when omitted
     */
    public function __construct(CsvSyncSource $source, array $mappings = null)
    {
        $this->source = $source;
        $this->mappings = $mappings === null ? $source->getMappings() : $mappings;
    }

    /**
     * @return CsvSyncMapping[]
     */
    public function getMappings()
    {
        return $this->mappings;
    }

    /**
     * @return string[] the PrestaShop fields this source writes to
     */
    public function getTargets()
    {
        $targets = [];
        foreach ($this->mappings as $mapping) {
            $targets[] = $mapping->ps_field;
        }

        return $targets;
    }

    /**
     * @return array ['product' => [], 'lang' => [], 'special' => [], 'features' => []]
     */
    public function map(array $row)
    {
        $mapped = ['product' => [], 'lang' => [], 'special' => [], 'features' => []];

        foreach ($this->mappings as $mapping) {
            $column = $mapping->csv_column;
            $raw = array_key_exists($column, $row) ? $row[$column] : null;
            if ($raw === null || trim((string) $raw) === '') {
                $raw = $mapping->default_value;
            }
            $value = CsvSyncTransformer::apply($mapping->transform, $raw);
            if ($value === '' || $value === null) {
                continue;
            }

            if (CsvSyncFields::isFeature($mapping->ps_field)) {
                $mapped['features'][CsvSyncFields::featureName($mapping->ps_field)] = (string) $value;
                continue;
            }

            switch (CsvSyncFields::kind($mapping->ps_field)) {
                case CsvSyncFields::KIND_PRODUCT:
                    $mapped['product'][$mapping->ps_field] = $value;
                    break;
                case CsvSyncFields::KIND_LANG:
                    $mapped['lang'][$mapping->ps_field] = $value;
                    break;
                case CsvSyncFields::KIND_SPECIAL:
                    $mapped['special'][$mapping->ps_field] = $value;
                    break;
            }
        }

        if (isset($mapped['product']['price'])) {
            $mapped['product']['price'] = $this->applyPriceRules($mapped['product']['price'], $mapped);
        }

        return $mapped;
    }

    /**
     * The feed's price is the supplier's; the shop's is that price put through
     * the source's markup, un-taxed if the feed quoted it with tax, and rounded.
     *
     * @return float
     */
    public function applyPriceRules($value, array $mapped)
    {
        $price = (float) CsvSyncTransformer::toNumber($value);
        $price *= (float) ($this->source->price_multiplier ?: 1);

        if ($this->source->price_tax_included) {
            $id_group = (int) (isset($mapped['product']['id_tax_rules_group'])
                ? $mapped['product']['id_tax_rules_group']
                : $this->source->id_tax_rules_group);
            if ($id_group) {
                $group = new TaxRulesGroup($id_group);
                $rate = Validate::isLoadedObject($group) ? (float) $group->getRate() : 0.0;
                if ($rate > 0) {
                    $price /= 1 + ($rate / 100);
                }
            }
        }

        return round($price, (int) $this->source->price_round);
    }

    /**
     * The value that identifies this row across runs.
     *
     * @return string
     */
    public function key(array $mapped)
    {
        $field = $this->source->match_by;
        if ($field === 'external_id') {
            return isset($mapped['special']['external_id']) ? trim((string) $mapped['special']['external_id']) : '';
        }
        if ($field === 'name') {
            return isset($mapped['lang']['name']) ? trim((string) $mapped['lang']['name']) : '';
        }

        return isset($mapped['product'][$field]) ? trim((string) $mapped['product'][$field]) : '';
    }

    /**
     * The category values of a row, one per branch the feed lists.
     *
     * @return string[]
     */
    public function categoryValues(array $mapped)
    {
        if (!isset($mapped['special']['categories'])) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode('|', (string) $mapped['special']['categories'])),
            'strlen'
        ));
    }

    /**
     * Images are excluded: re-downloading them is expensive enough that it
     * should not ride along with a price change.
     *
     * @return string
     */
    public function hash(array $mapped)
    {
        $payload = $mapped;
        unset($payload['special']['images']);

        return sha1(json_encode($payload));
    }

    /**
     * The mapping has to carry whatever match_by points at, or every row of a
     * run would fail the same way, one line at a time.
     *
     * @return string[] problems, empty when the mapping is usable
     */
    public function validate()
    {
        $problems = [];
        if (!$this->mappings) {
            $problems[] = 'This source has no field mapping yet.';

            return $problems;
        }

        $targets = $this->getTargets();
        if (!in_array($this->source->match_by, $targets, true)) {
            $problems[] = sprintf(
                'The matching field "%s" is not mapped to any CSV column.',
                CsvSyncFields::label($this->source->match_by)
            );
        }
        if ($this->source->create_missing_products && !in_array('name', $targets, true)) {
            $problems[] = 'Creating products needs the Name field to be mapped.';
        }
        if ($this->source->category_mode === CsvSyncSource::CATEGORY_MAP && !in_array('categories', $targets, true)) {
            $problems[] = 'Category mapping is switched on, but no CSV column is mapped to Categories.';
        }

        return $problems;
    }
}
