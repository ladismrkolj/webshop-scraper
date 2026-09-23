<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\CategoryPathNormalizer;

class CategoryPathNormalizerTest extends TestCase
{
    #[DataProvider('paths')]
    public function testNormalizesPaths($raw, array $expected): void
    {
        $before = $raw;
        $normalizer = new CategoryPathNormalizer();
        self::assertSame($expected, $normalizer->normalize($raw));
        self::assertSame($before, $raw, 'Normalization must not mutate the input.');
        self::assertSame($expected, $normalizer->normalize($expected), 'Normalization must be idempotent.');
    }

    public static function paths(): array
    {
        return [
            'null' => [null, []],
            'empty' => [[], []],
            'string' => ['Boards', []],
            'number' => [42, []],
            'boolean' => [false, []],
            'object' => [(object) ['title' => 'Boards'], []],
            'unwrapped dictionary is not a path' => [['title' => 'Boards'], []],
            'named outer map is not a list' => [['trail' => ['Boards']], []],
            'single segment' => [[' Boards '], [['Boards']]],
            'flat strings' => [['Water sports', 'Boards'], [['Water sports', 'Boards']]],
            'wrapped strings' => [[['Water sports', 'Boards']], [['Water sports', 'Boards']]],
            'multiple paths' => [[['Boards', 'SUP'], ['Sails', 'Freeride']], [['Boards', 'SUP'], ['Sails', 'Freeride']]],
            'flat dictionaries' => [[['title' => ' Boards ', 'url' => 'https://example.com'], ['title' => 'SUP']], [['Boards', 'SUP']]],
            'wrapped dictionaries' => [[[['title' => 'Boards'], ['title' => 'SUP']]], [['Boards', 'SUP']]],
            'multiple dictionary paths' => [[[['title' => 'Boards']], [['title' => 'Sails']]], [['Boards'], ['Sails']]],
            'mixed segment types' => [['Boards', ['title' => 'SUP'], false, 4, null, [], ['url' => 'no title']], [['Boards', 'SUP']]],
            'unusable titles' => [[['title' => null], ['title' => false], ['title' => 42], ['title' => []], ['title' => (object) []]], []],
            'empty segments' => [['', " \t\n", ['title' => ' ']], []],
            'trim segments' => [[" \tBoards\n", ['title' => " SUP\r\n"]], [['Boards', 'SUP']]],
            'drop empty paths' => [[[], [' '], ['Boards'], []], [['Boards']]],
            'only empty arrays' => [[[], []], []],
            'outer junk alongside paths' => [[null, 'ignored', ['title' => 'ignored'], ['Boards'], 42, ['Sails']], [['Boards'], ['Sails']]],
            'junk inside multiple paths' => [[[false, 'Boards', ['title' => 'SUP'], ['url' => 'x']], [null, 'Sails']], [['Boards', 'SUP'], ['Sails']]],
            'deeper nesting not flattened' => [[[[['title' => 'Boards']]]], []],
            'duplicates preserve order' => [[['Boards'], ['Boards']], [['Boards'], ['Boards']]],
            'duplicate segments retained' => [['Boards', 'Boards'], [['Boards', 'Boards']]],
            'sparse flat keys' => [[2 => 'Boards', 9 => ['title' => 'SUP']], [['Boards', 'SUP']]],
            'sparse outer and inner keys' => [[5 => [2 => 'Boards'], 9 => [6 => 'Sails']], [['Boards'], ['Sails']]],
            'numeric string title retained' => [[['title' => '0']], [['0']]],
            'unicode retained' => [[' Čolni ', '水上'], [['Čolni', '水上']]],
        ];
    }

    public function testFixtureBreadcrumbsAndStringsNormalizeIdentically(): void
    {
        $normalizer = new CategoryPathNormalizer();
        $products = json_decode(file_get_contents(__DIR__ . '/fixtures/products.json'), true);
        $dictPath = $normalizer->normalize($products[0]['breadcrumbs'])[0];
        $stringPath = $normalizer->normalize([[' Windsurf ', ' Sails ']])[0];
        self::assertSame($stringPath, $dictPath);
        self::assertSame(sha1(json_encode(['Windsurf', 'Sails'])), $normalizer->hash($dictPath));
        self::assertSame($normalizer->hash($stringPath), $normalizer->hash($dictPath));
        self::assertSame($normalizer->hash($dictPath), $normalizer->hash($dictPath));
    }

    public function testHashPreservesSegmentBoundariesAndOrder(): void
    {
        $normalizer = new CategoryPathNormalizer();
        self::assertNotSame($normalizer->hash(['A', 'B']), $normalizer->hash(['B', 'A']));
        self::assertNotSame($normalizer->hash(['A/B']), $normalizer->hash(['A', 'B']));
        self::assertSame(sha1('[]'), $normalizer->hash([]));
        self::assertSame(40, strlen($normalizer->hash(['Čolni'])));
    }

    public function testHashRejectsInvalidUtf8InsteadOfCollidingWithEmptyEncoding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CategoryPathNormalizer())->hash(["\xB1\x31"]);
    }
}
