<?php

namespace ProductImport\Service;

class ImageFingerprint
{
    public function fromFile(string $path): ?string
    {
        if (!extension_loaded('gd') || !is_readable($path)) {
            return null;
        }
        try {
            $info = @getimagesize($path);
            if ($info === false) {
                return null;
            }
            $decoders = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_GIF => 'imagecreatefromgif', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
            $decoder = $decoders[$info[2]] ?? null;
            if ($decoder === null || !function_exists($decoder)) {
                return null;
            }
            $source = @$decoder($path);
            if (!$source instanceof \GdImage) {
                return null;
            }
            try {
                $small = imagecreatetruecolor(9, 8);
                if ($small === false) {
                    return null;
                }
                try {
                    imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
                    $bits = '';
                    for ($y = 0; $y < 8; ++$y) {
                        for ($x = 0; $x < 8; ++$x) {
                            $bits .= $this->gray(imagecolorat($small, $x, $y)) > $this->gray(imagecolorat($small, $x + 1, $y)) ? '1' : '0';
                        }
                    }
                    $hex = '';
                    foreach (str_split($bits, 4) as $nibble) {
                        $hex .= dechex(bindec($nibble));
                    }
                    return $hex;
                } finally {
                    unset($small);
                }
            } finally {
                unset($source);
            }
        } catch (\Throwable $error) {
            return null;
        }
    }

    public function distance(string $a, string $b): int
    {
        $distance = 0;
        for ($i = 0; $i < 16; ++$i) {
            $distance += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        }
        return $distance;
    }

    /** At most three differing bits allows minor encoding changes. */
    public function isSame(string $a, string $b): bool
    {
        return $this->distance($a, $b) <= 3;
    }

    private function gray(int $color): float
    {
        return 0.299 * (($color >> 16) & 255) + 0.587 * (($color >> 8) & 255) + 0.114 * ($color & 255);
    }
}
