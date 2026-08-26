<?php
/**
 * Streams a CSV feed row by row, from disk or over HTTP.
 *
 * Feeds are read a row at a time and never held in memory whole: a full-shop
 * scrape can run to tens of thousands of rows, which is exactly the size that
 * kills an import on shared hosting.
 */
class CsvSyncReader
{
    /** @var resource|null */
    private $handle;

    /** @var string|null temporary copy of a downloaded feed, removed on close */
    private $temp_file;

    /** @var CsvSyncSource */
    private $source;

    /** @var string[] column names, or the string indexes 0..n for headerless feeds */
    private $header = [];

    /** @var int */
    private $line = 0;

    public function __construct(CsvSyncSource $source)
    {
        $this->source = $source;
    }

    /**
     * @throws Exception when the feed cannot be reached or is empty
     */
    public function open()
    {
        $path = self::resolve($this->source->location);
        if (self::isRemote($this->source->location)) {
            $this->temp_file = self::download($this->source->location);
            $path = $this->temp_file;
        }

        if (!is_readable($path)) {
            throw new Exception(sprintf('CSV not readable: %s', $path));
        }

        $this->handle = fopen($path, 'r');
        if (!$this->handle) {
            throw new Exception(sprintf('Could not open CSV: %s', $path));
        }

        // A UTF-8 BOM would otherwise end up glued to the first column name,
        // and no mapping would ever match it.
        $bom = fread($this->handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($this->handle);
        }

        $first = $this->readRaw();
        if ($first === null) {
            throw new Exception('CSV is empty');
        }

        if ($this->source->has_header) {
            $this->header = array_map(function ($name) {
                return trim($this->toUtf8($name));
            }, $first);
        } else {
            $this->header = array_map('strval', array_keys($first));
            // The first row is data, so hand it back on the first next() call.
            $this->pushback = $first;
        }
    }

    /** @var array|null a row read ahead of its turn */
    private $pushback;

    /**
     * @return array|null column name => value, or null at end of file
     */
    public function next()
    {
        if ($this->pushback !== null) {
            $row = $this->pushback;
            $this->pushback = null;
        } else {
            $row = $this->readRaw();
        }
        if ($row === null) {
            return null;
        }

        $values = [];
        foreach ($this->header as $index => $name) {
            $values[$name] = isset($row[$index]) ? $this->toUtf8($row[$index]) : '';
        }

        return $values;
    }

    /**
     * @return string[]
     */
    public function getHeader()
    {
        return $this->header;
    }

    public function getLineNumber()
    {
        return $this->line;
    }

    public function close()
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
        if ($this->temp_file && file_exists($this->temp_file)) {
            @unlink($this->temp_file);
            $this->temp_file = null;
        }
    }

    /**
     * Header plus a couple of data rows, for the mapping screen's preview.
     *
     * @return array ['header' => string[], 'rows' => array[]]
     */
    public static function peek(CsvSyncSource $source, $rows = 3)
    {
        $reader = new self($source);
        try {
            $reader->open();
            $sample = [];
            while (count($sample) < $rows && ($row = $reader->next()) !== null) {
                $sample[] = $row;
            }

            return ['header' => $reader->getHeader(), 'rows' => $sample];
        } finally {
            $reader->close();
        }
    }

    /**
     * A relative location is taken as relative to the shop root, so a feed
     * dropped in the shop's own upload folder needs no absolute path.
     */
    public static function resolve($location)
    {
        $location = trim((string) $location);
        if (self::isRemote($location) || $location === '') {
            return $location;
        }
        if ($location[0] === '/') {
            return $location;
        }

        return rtrim(_PS_ROOT_DIR_, '/') . '/' . ltrim($location, '/');
    }

    public static function isRemote($location)
    {
        return (bool) preg_match('#^https?://#i', trim((string) $location));
    }

    /**
     * @return string path of the downloaded copy
     *
     * @throws Exception
     */
    private static function download($url)
    {
        $temp = tempnam(sys_get_temp_dir(), 'csvsync_');
        if (!$temp) {
            throw new Exception('Could not create a temporary file for the download');
        }

        $out = fopen($temp, 'w');
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => 'PrestaShop csvproductsync',
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($out);

        if (!$ok || ($status && $status >= 400)) {
            @unlink($temp);
            throw new Exception(sprintf('Download failed (HTTP %d) %s', $status, $error));
        }

        return $temp;
    }

    /**
     * @return array|null
     */
    private function readRaw()
    {
        $delimiter = $this->source->delimiter !== '' ? substr($this->source->delimiter, 0, 1) : ',';
        $enclosure = $this->source->enclosure !== '' ? substr($this->source->enclosure, 0, 1) : '"';

        while (($row = fgetcsv($this->handle, 0, $delimiter, $enclosure)) !== false) {
            ++$this->line;
            // fgetcsv reports a blank line as [null]; those are not rows.
            if ($row === [null] || $row === []) {
                continue;
            }

            return $row;
        }

        return null;
    }

    private function toUtf8($value)
    {
        $encoding = Tools::strtoupper(trim((string) $this->source->encoding));
        if ($encoding === '' || $encoding === 'UTF-8' || $encoding === 'UTF8') {
            return (string) $value;
        }
        $converted = @iconv($encoding, 'UTF-8//TRANSLIT', (string) $value);

        return $converted === false ? (string) $value : $converted;
    }
}
