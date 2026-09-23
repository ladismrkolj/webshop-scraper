<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\JsonFetcher;

class JsonFetcherTest extends TestCase
{
    private $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'pi_json_');
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    public function testReadsRepresentativeProducts(): void
    {
        $json = file_get_contents(__DIR__ . '/fixtures/products.json');
        file_put_contents($this->file, $json);
        $items = (new JsonFetcher())->fetch(null, $this->file);
        self::assertSame(json_decode($json, true), $items);
        self::assertCount(3, $items);
        self::assertSame(249.5, $items[0]['price']);
    }

    public function testEmptyUrlUsesFileAndEmptyListIsValid(): void
    {
        file_put_contents($this->file, '[]');
        self::assertSame([], (new JsonFetcher())->fetch('  ', $this->file));
    }

    #[DataProvider('invalidJson')]
    public function testRejectsInvalidJsonOrNonList(string $body, string $message): void
    {
        file_put_contents($this->file, $body);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        (new JsonFetcher())->fetch(null, $this->file);
    }

    public static function invalidJson(): array
    {
        return [
            ['{broken', 'Invalid JSON'], ['', 'Invalid JSON'],
            ['null', 'top-level array'], ['42', 'top-level array'],
            ['"text"', 'top-level array'], ['false', 'top-level array'],
            ['{}', 'top-level array'], ['{"0":{"name":"item"}}', 'top-level array'],
        ];
    }

    public function testMissingFileThrows(): void
    {
        unlink($this->file);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a readable regular file');
        (new JsonFetcher())->fetch(null, $this->file);
    }

    public function testDirectoryThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new JsonFetcher())->fetch(null, dirname($this->file));
    }

    #[DataProvider('missingSources')]
    public function testMissingSourceThrows(?string $url, ?string $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new JsonFetcher())->fetch($url, $file);
    }

    public static function missingSources(): array
    {
        return [[null, null], ['', ''], [' ', ' ']];
    }
}
