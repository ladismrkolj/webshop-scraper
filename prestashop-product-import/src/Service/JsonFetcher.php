<?php

namespace ProductImport\Service;

class JsonFetcher
{
    public function fetch(?string $url, ?string $filePath): array
    {
        // URL is the live source; the file is a fallback/manual-test path.
        if ($url !== null && trim($url) !== '') {
            $body = $this->fetchUrl($url);
        } elseif ($filePath !== null && trim($filePath) !== '') {
            if (!is_file($filePath) || !is_readable($filePath)) {
                throw new \RuntimeException('JSON file is not a readable regular file: ' . $filePath);
            }
            $body = @file_get_contents($filePath);
            if ($body === false) {
                throw new \RuntimeException('Unable to read JSON file: ' . $filePath);
            }
        } else {
            throw new \InvalidArgumentException('Provide a JSON URL or file path.');
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
        }
        // Associative decoding alone cannot distinguish a JSON object from a list.
        if (!is_array($decoded) || substr(ltrim($body), 0, 1) !== '[') {
            throw new \RuntimeException('JSON must contain a top-level array of products.');
        }

        return $decoded;
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
                throw new \RuntimeException('JSON request failed: ' . curl_error($handle));
            }
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('JSON request returned HTTP ' . $status . '.');
            }

            return $body;
        } finally {
            // PHP 8 releases CurlHandle automatically; PHP 7 still uses a resource.
            if (is_resource($handle)) {
                curl_close($handle);
            }
        }
    }
}
