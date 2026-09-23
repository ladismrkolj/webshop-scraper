<?php

namespace ProductImport\Service;

class FieldInspector
{
    public const MAX_DEPTH = 4;
    public const ARRAY_ENTRIES = 3;
    public const MAX_ROWS = 100;

    /** Decoded JSON objects may be stdClass or associative arrays; [] represents {} after associative decoding. */
    public function inspect(mixed $item): array
    {
        if (!$item instanceof \stdClass && (!is_array($item) || ($item !== [] && array_is_list($item)))) {
            throw new \InvalidArgumentException('Sample item must be a JSON object.');
        }
        $json = json_encode($item === [] ? (object) [] : $item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $rows = [];
        $truncated = false;
        $this->walk($item, '', 0, $rows, $truncated);
        return ['json' => $json, 'fields' => $rows, 'truncated' => $truncated];
    }

    private function walk(mixed $value, string $path, int $depth, array &$rows, bool &$truncated): void
    {
        if (count($rows) >= self::MAX_ROWS) {
            $truncated = true;
            return;
        }
        $container = is_array($value) || $value instanceof \stdClass;
        $children = $value instanceof \stdClass ? get_object_vars($value) : $value;
        if (!$container || $children === [] || $depth >= self::MAX_DEPTH) {
            if ($path !== '') {
                $limited = $container && $children !== [];
                $rows[] = ['key' => $path, 'value' => $limited ? '[container: ' . count($children) . ' entries]' : $value];
                $truncated = $truncated || $limited;
            }
            return;
        }
        $list = is_array($value) && array_is_list($value);
        $seen = 0;
        foreach ($children as $key => $child) {
            if (($list && $seen >= self::ARRAY_ENTRIES) || count($rows) >= self::MAX_ROWS) {
                $truncated = true;
                break;
            }
            ++$seen;
            $segment = $list ? '[' . $key . ']' : (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', (string) $key) ? ($path === '' ? '' : '.') . $key : '[' . json_encode((string) $key, JSON_THROW_ON_ERROR) . ']');
            $this->walk($child, $path . $segment, $depth + 1, $rows, $truncated);
        }
    }
}
