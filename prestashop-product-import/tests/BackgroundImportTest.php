<?php

use PHPUnit\Framework\TestCase;
use ProductImport\Service\BackgroundImport;

class BackgroundImportTest extends TestCase
{
    public function testCommandQuotesArgumentsAndRedirectsLog(): void
    {
        self::assertSame("nohup '/path/php cli' '/module/bin/import.php' 'all' 'cron-background' > '/tmp/run log' 2>&1 &", BackgroundImport::buildCommand('/path/php cli', '/module/bin/import.php', 'all', 'cron-background', '/tmp/run log'));
        self::assertSame("nohup nice -n 10 '/php' '/script' '7' 'admin-background' > '/log' 2>&1 &", BackgroundImport::buildCommand('/php', '/script', 7, 'admin-background', '/log', true));
    }

    public function testSelectionValidation(): void
    {
        self::assertSame('all', BackgroundImport::selectionArgument('all'));
        self::assertSame('12', BackgroundImport::selectionArgument(12));
        $this->expectException(InvalidArgumentException::class);
        BackgroundImport::selectionArgument('12');
    }
}
