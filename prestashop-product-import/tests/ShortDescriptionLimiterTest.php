<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ShortDescriptionLimiter;

class ShortDescriptionLimiterTest extends TestCase
{
    public function testShortPlainTextAndTagsAreReturnedWithoutTruncation(): void
    {
        self::assertSame('Small board', ShortDescriptionLimiter::limit('<p>Small board</p>', 20));
    }

    public function testMarkupLengthDoesNotCountTowardLimit(): void
    {
        $value = str_repeat('<strong>', 20) . 'Board' . str_repeat('</strong>', 20);
        self::assertSame('Board', ShortDescriptionLimiter::limit($value, 5));
    }

    public function testLongPlainTextIsTruncatedToCharacterLimit(): void
    {
        $result = ShortDescriptionLimiter::limit('<p>Long description</p>', 8);
        self::assertSame('Long des', $result);
        self::assertSame(8, iconv_strlen($result));
    }

    public function testMultibyteCharactersCountAsSingleCharacters(): void
    {
        $result = ShortDescriptionLimiter::limit('<p>Čolni水上</p>', 4);
        self::assertSame('Čoln', $result);
        self::assertSame(4, iconv_strlen($result));
    }
}
