<?php
/**
 * The small conversions that stand between a scraped cell and a usable value.
 *
 * The feeds in this repository are written by Scrapy's CSV exporter, so prices
 * arrive as "129,00 €", booleans as "True", and image lists as the Python
 * repr "['https://a.jpg', 'https://b.jpg']". Each of those has a transform
 * here rather than a special case in the importer.
 */
class CsvSyncTransformer
{
    /**
     * @return array transform key => human label
     */
    public static function all()
    {
        $t = function ($string) {
            return Context::getContext()->getTranslator()->trans($string, [], 'Modules.Csvproductsync.Admin');
        };

        return [
            'none' => $t('None'),
            'trim' => $t('Trim whitespace'),
            'upper' => $t('UPPERCASE'),
            'lower' => $t('lowercase'),
            'ucfirst' => $t('Capitalise first letter'),
            'strip_tags' => $t('Strip HTML tags'),
            'nl2br' => $t('Newlines to <br>'),
            'number' => $t('Number (accepts "1.299,00 €")'),
            'integer' => $t('Whole number'),
            'boolean' => $t('Yes/no (True, 1, yes, InStock...)'),
            'schema_availability' => $t('schema.org availability to yes/no'),
            'python_list' => $t('Python list ["a", "b"] to a | b'),
            'json_list' => $t('JSON list or object to a | b'),
            'first_of_list' => $t('First entry of a list'),
            'breadcrumb_to_path' => $t('Breadcrumb list to "A > B > C" category path'),
            'absolute_url' => $t('Make URLs absolute (needs the feed to carry a host)'),
        ];
    }

    /**
     * @param string $transform
     * @param string $value raw cell
     *
     * @return string|float|int|null
     */
    public static function apply($transform, $value)
    {
        $value = $value === null ? '' : (string) $value;

        switch ($transform) {
            case 'trim':
                return trim($value);
            case 'upper':
                return Tools::strtoupper(trim($value));
            case 'lower':
                return Tools::strtolower(trim($value));
            case 'ucfirst':
                return Tools::ucfirst(Tools::strtolower(trim($value)));
            case 'strip_tags':
                return trim(strip_tags($value));
            case 'nl2br':
                return nl2br(trim($value));
            case 'number':
                return self::toNumber($value);
            case 'integer':
                return (int) round((float) self::toNumber($value));
            case 'boolean':
                return self::toBool($value) ? 1 : 0;
            case 'schema_availability':
                return self::availabilityToBool($value) ? 1 : 0;
            case 'python_list':
                return self::joinList(self::parsePythonList($value));
            case 'json_list':
                return self::joinList(self::parseJsonList($value));
            case 'first_of_list':
                $list = self::parseAnyList($value);

                return $list ? reset($list) : '';
            case 'breadcrumb_to_path':
                $list = self::parseAnyList($value);

                return implode(' > ', array_map('trim', $list));
            case 'absolute_url':
                return self::joinList(array_map([__CLASS__, 'absolutise'], self::parseAnyList($value)));
            case 'none':
            default:
                return $value;
        }
    }

    /**
     * "1.299,00 €" and "1,299.00" both mean the same thing; the last
     * separator in the string is the decimal one.
     *
     * @return float
     */
    public static function toNumber($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0.0;
        }

        // Keep digits, separators and a leading sign; drop currency and spaces.
        $clean = preg_replace('/[^0-9,.\-]/', '', $value);
        $clean = preg_replace('/(?<=.)-/', '', $clean);
        if ($clean === '' || $clean === '-') {
            return 0.0;
        }

        $last_comma = strrpos($clean, ',');
        $last_dot = strrpos($clean, '.');
        if ($last_comma !== false && $last_dot !== false) {
            // Whichever comes last is the decimal point; the other groups digits.
            if ($last_comma > $last_dot) {
                $clean = str_replace('.', '', $clean);
                $clean = str_replace(',', '.', $clean);
            } else {
                $clean = str_replace(',', '', $clean);
            }
        } elseif ($last_comma !== false) {
            // A lone comma with exactly three digits behind it is a thousands
            // separator ("1,299"); anything else is a decimal comma.
            $decimals = strlen($clean) - $last_comma - 1;
            $clean = $decimals === 3 ? str_replace(',', '', $clean) : str_replace(',', '.', $clean);
        }

        return (float) $clean;
    }

    public static function toBool($value)
    {
        $value = Tools::strtolower(trim((string) $value));
        if ($value === '') {
            return false;
        }
        if (in_array($value, ['1', 'true', 'yes', 'y', 'da', 'ja', 'on', 'available', 'in stock', 'instock'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'n', 'ne', 'off', 'out of stock', 'outofstock', 'none'], true)) {
            return false;
        }

        return self::availabilityToBool($value);
    }

    /**
     * schema.org availability URLs, as the scrapers pull them out of JSON-LD.
     */
    public static function availabilityToBool($value)
    {
        $value = Tools::strtolower(trim((string) $value));
        if ($value === '') {
            return false;
        }
        $tail = Tools::strtolower(basename(str_replace('\\', '/', $value)));

        return in_array($tail, ['instock', 'limitedavailability', 'onlineonly', 'instoreonly', 'presale', 'preorder'], true);
    }

    /**
     * Scrapy writes a list field as its Python repr. Parsed leniently: a
     * malformed cell yields nothing rather than derailing the row.
     *
     * @return string[]
     */
    public static function parsePythonList($value)
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '[]') {
            return [];
        }
        if ($value[0] !== '[' && $value[0] !== '(') {
            return [$value];
        }

        // Single-quoted Python strings are not valid JSON, so pick the
        // quoted runs out directly instead of trying to repair the syntax.
        if (preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/s', $value, $matches)) {
            $items = [];
            foreach ($matches[2] as $item) {
                $item = stripcslashes($item);
                if (trim($item) !== '') {
                    $items[] = trim($item);
                }
            }

            return $items;
        }

        // A list of bare numbers: [1, 2, 3]
        $inner = trim($value, "[]() \t\n\r");
        if ($inner === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $inner)), 'strlen'));
    }

    /**
     * @return string[]
     */
    public static function parseJsonList($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if ($decoded === null) {
            return [$value];
        }
        if (!is_array($decoded)) {
            return [(string) $decoded];
        }

        $items = [];
        array_walk_recursive($decoded, function ($item) use (&$items) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $items[] = trim((string) $item);
            }
        });

        return $items;
    }

    /**
     * Accepts whatever the cell happens to hold: a JSON list, a Python list,
     * a pipe-separated string or a single value.
     *
     * @return string[]
     */
    public static function parseAnyList($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }
        if ($value[0] === '[' || $value[0] === '{') {
            $items = self::parseJsonList($value);
            if ($items && $items !== [$value]) {
                return $items;
            }

            return self::parsePythonList($value);
        }
        if (strpos($value, '|') !== false) {
            return array_values(array_filter(array_map('trim', explode('|', $value)), 'strlen'));
        }

        return [$value];
    }

    public static function joinList(array $items)
    {
        return implode(' | ', array_filter(array_map('trim', $items), 'strlen'));
    }

    /**
     * Protocol-relative and root-relative URLs are common in scraped image
     * lists; both are useless to PrestaShop's image fetcher as they stand.
     */
    public static function absolutise($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        return $url;
    }
}
