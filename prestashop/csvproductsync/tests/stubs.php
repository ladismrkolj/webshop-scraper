<?php
/**
 * Just enough PrestaShop to exercise the parsing classes outside a shop.
 *
 * Only the handful of Tools helpers the CSV layer calls are stubbed; anything
 * that needs a database belongs in a real installation, not here.
 */
define('_PS_VERSION_', '9.0.0');
define('_PS_ROOT_DIR_', '/var/www/html');

class Tools
{
    public static function strtolower($string)
    {
        return mb_strtolower((string) $string, 'UTF-8');
    }

    public static function strtoupper($string)
    {
        return mb_strtoupper((string) $string, 'UTF-8');
    }

    public static function ucfirst($string)
    {
        return mb_convert_case(mb_substr((string) $string, 0, 1, 'UTF-8'), MB_CASE_UPPER, 'UTF-8')
            . mb_substr((string) $string, 1, null, 'UTF-8');
    }

    public static function substr($string, $start, $length = null)
    {
        return mb_substr((string) $string, $start, $length, 'UTF-8');
    }
}

/**
 * The reader only ever reads a handful of scalar settings off a source, so a
 * stand-in with those properties is enough to exercise it without a database.
 */
class CsvSyncSource
{
    public $location;
    public $delimiter = ',';
    public $enclosure = '"';
    public $encoding = 'UTF-8';
    public $has_header = true;
}
