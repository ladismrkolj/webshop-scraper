<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ImportLauncher;

class ImportLauncherTest extends TestCase
{
    public function testSelectionIncludesOnlyActiveForAllAndInactiveForSpecificId(): void
    {
        $sources = [
            ['id_source' => 1, 'active' => 1],
            ['id_source' => 2, 'active' => 0],
        ];
        self::assertSame([$sources[0]], ImportLauncher::selectSources($sources, 'all'));
        self::assertSame([$sources[1]], ImportLauncher::selectSources($sources, 2));
        $this->expectException(InvalidArgumentException::class);
        ImportLauncher::selectSources($sources, 3);
    }
}
