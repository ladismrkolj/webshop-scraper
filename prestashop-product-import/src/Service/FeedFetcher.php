<?php

namespace ProductImport\Service;

class FeedFetcher
{
    public static function fetch(array $source, ?JsonFetcher $json = null, ?XmlFetcher $xml = null): array
    {
        if (($source['source_format'] ?? 'json') === 'xml') {
            return ($xml ?? new XmlFetcher())->fetch($source['json_url'] ?? null, $source['json_file_path'] ?? null, $source['xml_item_path'] ?? null);
        }

        return ($json ?? new JsonFetcher())->fetch($source['json_url'] ?? null, $source['json_file_path'] ?? null);
    }
}
