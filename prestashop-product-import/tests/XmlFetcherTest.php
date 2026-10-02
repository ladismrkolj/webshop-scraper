<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\XmlFetcher;

class XmlFetcherTest extends TestCase
{
    public function testConvertsLeafTextAndAttributes(): void
    {
        $xml = '<catalog><product><dobava id="1">Na zalogi</dobava><empty id="2"></empty><category id="407"><name>Vsi izdelki</name></category><brand>UNIFIBER</brand></product></catalog>';

        self::assertSame([[
            'dobava' => ['@attributes' => ['id' => '1'], '@value' => 'Na zalogi'],
            'empty' => ['@attributes' => ['id' => '2'], '@value' => ''],
            'category' => ['@attributes' => ['id' => '407'], 'name' => 'Vsi izdelki'],
            'brand' => 'UNIFIBER',
        ]], (new XmlFetcher())->parse($xml, 'product'));
    }

    public function testConvertsItemsAndResolvesSiblings(): void
    {
        $xml = '<catalog><products><product id="A1"><name> Board </name><tag>one</tag><tag>two</tag><detail><color>blue</color></detail></product><product id="A2"><name>Sail</name></product></products></catalog>';
        $items = (new XmlFetcher())->parse($xml, 'products/product');
        self::assertSame([
            ['@attributes' => ['id' => 'A1'], 'name' => 'Board', 'tag' => ['one', 'two'], 'detail' => ['color' => 'blue']],
            ['@attributes' => ['id' => 'A2'], 'name' => 'Sail'],
        ], $items);
    }

    public function testWrongPathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("No items found at path 'products/missing'");
        (new XmlFetcher())->parse('<catalog><products><product /></products></catalog>', 'products/missing');
    }

    public function testMalformedXmlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid XML:');
        (new XmlFetcher())->parse('<catalog>', 'product');
    }
}
