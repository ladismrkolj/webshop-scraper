<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ValueSampler;

class ValueSamplerTest extends TestCase
{
    public function testGroupsValuesAndIsolatesItemErrors(): void
    {
        $items = [
            ['availability' => 'in stock'],
            ['availability' => 'out of stock'],
            ['availability' => 'in stock'],
            ['other' => true],
            'invalid item',
        ];
        $result = (new ValueSampler())->sample($items, "path(fields, 'availability')");

        self::assertSame(5, $result['items_scanned']);
        self::assertSame(2, $result['distinct_total']);
        self::assertSame([['value' => 'in stock', 'count' => 2], ['value' => 'out of stock', 'count' => 1]], $result['values']);
        self::assertSame(1, $result['errors']['count']);
        self::assertCount(1, $result['errors']['messages']);
        self::assertFalse($result['truncated']);
    }

    public function testCapsDistinctResults(): void
    {
        $items = array_map(static function ($value) {
            return ['value' => $value];
        }, range(1, 51));
        $result = (new ValueSampler())->sample($items, "fields['value']");

        self::assertSame(51, $result['distinct_total']);
        self::assertCount(50, $result['values']);
        self::assertTrue($result['truncated']);
    }
}
