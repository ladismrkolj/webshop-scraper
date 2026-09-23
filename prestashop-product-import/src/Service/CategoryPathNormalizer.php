<?php

namespace ProductImport\Service;

class CategoryPathNormalizer
{
    /**
     * @return list<list<string>>
     * A nonempty integer-keyed child array identifies an outer list of paths.
     * Otherwise the input is one path (strings/title dictionaries plus junk).
     * In multi-path mode, outer scalars/dictionaries are junk, not extra paths.
     * Sparse integer keys are accepted; arbitrary deeper nesting is not flattened.
     */
    public function normalize($rawCategoryPaths): array
    {
        if (!is_array($rawCategoryPaths) || !$this->isList($rawCategoryPaths)) {
            return [];
        }
        $multiple = false;
        foreach ($rawCategoryPaths as $entry) {
            if (is_array($entry) && $entry !== [] && $this->isList($entry)) {
                $multiple = true;
                break;
            }
        }
        $paths = $multiple ? $rawCategoryPaths : [$rawCategoryPaths];
        $result = [];
        foreach ($paths as $path) {
            if (!is_array($path) || !$this->isList($path)) {
                continue;
            }
            $segments = [];
            foreach ($path as $entry) {
                $title = is_array($entry) ? ($entry['title'] ?? null) : $entry;
                if (is_string($title) && trim($title) !== '') {
                    $segments[] = trim($title);
                }
            }
            if ($segments !== []) {
                $result[] = $segments;
            }
        }

        return $result;
    }

    public function hash(array $normalizedPath): string
    {
        $json = json_encode($normalizedPath);
        if ($json === false) {
            throw new \InvalidArgumentException('Cannot encode category path: ' . json_last_error_msg());
        }

        return sha1($json);
    }

    private function isList(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_int($key)) {
                return false;
            }
        }

        return true;
    }
}
