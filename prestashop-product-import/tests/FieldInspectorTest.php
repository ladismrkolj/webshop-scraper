<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProductImport\Service\FieldInspector;

class FieldInspectorTest extends TestCase
{
    public function testNestedObjectsAndArrays(): void
    {
        $item = json_decode('{"name":"Board","breadcrumbs":[{"title":"Windsurf"}],"details":{"weight":2.5},"active":false,"missing":null}');
        $result = (new FieldInspector())->inspect($item);
        self::assertSame(['name', 'breadcrumbs[0].title', 'details.weight', 'active', 'missing'], array_column($result['fields'], 'key'));
        self::assertSame(['Board', 'Windsurf', 2.5, false, null], array_column($result['fields'], 'value'));
        self::assertFalse($result['truncated']);
        self::assertEquals($item, json_decode($result['json']));
        self::assertStringContainsString("\n", $result['json']);
    }

    public function testAssociativeArraysAndSpecialKeys(): void
    {
        $result = (new FieldInspector())->inspect(['a.b' => ['quoted"key' => 'Čolni'], 'items' => [1, 2]]);
        self::assertSame(['["a.b"]["quoted\\"key"]', 'items[0]', 'items[1]'], array_column($result['fields'], 'key'));
        self::assertStringContainsString('Čolni', $result['json']);
    }

    public function testArrayEntryCapDoesNotHideOtherFields(): void
    {
        $result = (new FieldInspector())->inspect(['variants' => range(0, 9), 'name' => 'Board']);
        self::assertSame(['variants[0]', 'variants[1]', 'variants[2]', 'name'], array_column($result['fields'], 'key'));
        self::assertTrue($result['truncated']);
        self::assertCount(10, json_decode($result['json'], true)['variants']);
    }

    public function testDepthCapSummarizesContainer(): void
    {
        $result = (new FieldInspector())->inspect(['a' => ['b' => ['c' => ['d' => ['e' => 'hidden']]]]]);
        self::assertSame([['key' => 'a.b.c.d', 'value' => '[container: 1 entries]']], $result['fields']);
        self::assertTrue($result['truncated']);
    }

    public function testScalarAtExactDepthIsNotTruncated(): void
    {
        $result = (new FieldInspector())->inspect(['a' => ['b' => ['c' => ['d' => 'visible']]]]);
        self::assertSame('visible', $result['fields'][0]['value']);
        self::assertFalse($result['truncated']);
    }

    public function testTotalRowCap(): void
    {
        $item = [];
        for ($i = 0; $i < 101; ++$i) {
            $item['field' . $i] = $i;
        }
        $result = (new FieldInspector())->inspect($item);
        self::assertCount(100, $result['fields']);
        self::assertTrue($result['truncated']);
        unset($item['field100']);
        self::assertFalse((new FieldInspector())->inspect($item)['truncated']);
    }

    public function testEmptyObjectsAndContainers(): void
    {
        $inspector = new FieldInspector();
        self::assertSame(['json' => '{}', 'fields' => [], 'truncated' => false], $inspector->inspect(new \stdClass()));
        self::assertSame('{}', $inspector->inspect([])['json']);
        $result = $inspector->inspect(['list' => [], 'object' => new \stdClass()]);
        self::assertSame(['list', 'object'], array_column($result['fields'], 'key'));
        self::assertFalse($result['truncated']);
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsNonObjects(mixed $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FieldInspector())->inspect($input);
    }

    public static function invalidInputs(): array
    {
        return [[null], [false], [42], ['text'], [[1, 2]], [new \DateTimeImmutable()]];
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(\JsonException::class);
        (new FieldInspector())->inspect(['bad' => "\xB1"]);
    }
}
