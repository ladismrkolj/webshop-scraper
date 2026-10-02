<?php

namespace ProductImport\Service;

class XmlFetcher
{
    public function fetch(?string $url, ?string $filePath, ?string $itemPath): array
    {
        if ($url !== null && trim($url) !== '') {
            $body = $this->fetchUrl($url);
        } elseif ($filePath !== null && trim($filePath) !== '') {
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('XML file is not a readable regular file: ' . $filePath);
            }
            $body = @file_get_contents($filePath);
            if ($body === false) {
                throw new \RuntimeException('Unable to read XML file: ' . $filePath);
            }
        } else {
            throw new \InvalidArgumentException('Provide an XML URL or file path.');
        }

        return $this->parse($body, $itemPath);
    }

    public function parse(string $body, ?string $itemPath): array
    {
        if ($itemPath === null || trim($itemPath) === '') {
            throw new \InvalidArgumentException('Provide an XML item path.');
        }
        $path = trim($itemPath);
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $segment)) {
                throw new \InvalidArgumentException('Invalid XML item path: ' . $path);
            }
        }
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
            if ($xml === false) {
                $errors = array_map(static fn ($error) => trim($error->message), libxml_get_errors());
                throw new \RuntimeException('Invalid XML: ' . implode('; ', array_unique($errors)));
            }
            $nodes = [$xml];
            foreach ($segments as $segment) {
                $next = [];
                foreach ($nodes as $node) {
                    foreach ($node->children() as $child) {
                        if ($child->getName() === $segment) {
                            $next[] = $child;
                        }
                    }
                }
                $nodes = $next;
            }
            if ($nodes === []) {
                throw new \RuntimeException("No items found at path '" . $path . "'");
            }
            $items = array_map(fn ($node) => $this->elementToArray($node), $nodes);
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('XML items must contain child elements or attributes.');
                }
            }
            return $items;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @attributes and @value are synthetic keys for an element's own XML attributes
     * and its text when it has attributes; XML child names cannot start with @.
     */
    private function elementToArray(\SimpleXMLElement $element): array|string
    {
        $result = [];
        $attributes = [];
        foreach ($element->attributes() as $name => $value) {
            $attributes[$name] = (string) $value;
        }
        if ($attributes !== []) {
            $result['@attributes'] = $attributes;
        }
        $seen = [];
        $hasChildren = false;
        foreach ($element->children() as $child) {
            $hasChildren = true;
            $name = $child->getName();
            $value = $this->elementToArray($child);
            if (!isset($seen[$name])) {
                $seen[$name] = 1;
                $result[$name] = $value;
            } elseif ($seen[$name] > 1) {
                ++$seen[$name];
                $result[$name][] = $value;
            } else {
                ++$seen[$name];
                $result[$name] = [$result[$name], $value];
            }
        }
        if ($attributes !== [] && !$hasChildren) {
            $result['@value'] = trim((string) $element);
        }
        return $result === [] ? trim((string) $element) : $result;
    }

    private function fetchUrl(string $url): string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }
        try {
            $protocolOption = defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS;
            $protocolValue = defined('CURLOPT_PROTOCOLS_STR') ? 'http,https' : CURLPROTO_HTTP | CURLPROTO_HTTPS;
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 120,
                $protocolOption => $protocolValue,
            ]);
            $body = curl_exec($handle);
            if ($body === false) {
                throw new \RuntimeException('XML request failed: ' . curl_error($handle));
            }
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('XML request returned HTTP ' . $status . '.');
            }
            return $body;
        } finally {
            if (is_resource($handle)) {
                curl_close($handle);
            }
        }
    }
}
