<?php

namespace ProductImport\Tests;

use PHPUnit\Framework\TestCase;
use ProductImport\Service\ImageFingerprint;

class ImageFingerprintTest extends TestCase
{
    public function testImageMatching(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for image fingerprint tests.');
        }
        $files = [];
        try {
            foreach ([['gradient', 80, 80, 95], ['gradient', 160, 160, 50], ['other', 80, 80, 95]] as [$pattern, $width, $height, $quality]) {
                $file = tempnam(sys_get_temp_dir(), 'pi_hash_');
                $files[] = $file;
                $image = imagecreatetruecolor($width, $height);
                for ($y = 0; $y < $height; ++$y) {
                    for ($x = 0; $x < $width; ++$x) {
                        $v = (int) (255 * ($pattern === 'gradient' ? $x / $width : 1 - $x / $width));
                        imagesetpixel($image, $x, $y, imagecolorallocate($image, $v, $v, $v));
                    }
                }
                imagejpeg($image, $file, $quality);
                unset($image);
            }
            $fingerprinter = new ImageFingerprint();
            $first = $fingerprinter->fromFile($files[0]);
            $resized = $fingerprinter->fromFile($files[1]);
            $different = $fingerprinter->fromFile($files[2]);
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
            self::assertTrue($fingerprinter->isSame($first, $first));
            self::assertTrue($fingerprinter->isSame($first, $resized));
            self::assertFalse($fingerprinter->isSame($first, $different));
            file_put_contents($files[0], 'garbage');
            self::assertNull($fingerprinter->fromFile($files[0]));
        } finally {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }
}
