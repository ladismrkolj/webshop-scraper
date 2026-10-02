<?php

namespace ProductImport\Service;

class ShortDescriptionLimiter
{
    public static function limit(string $value, int $limit): string
    {
        $plainText = strip_tags($value);

        return iconv_strlen($plainText) > $limit ? mb_substr($plainText, 0, $limit) : $plainText;
    }
}
