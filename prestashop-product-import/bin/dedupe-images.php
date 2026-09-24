<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__, 3) . '/config/config.inc.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use ProductImport\Repository\ExternalProductRepository;
use ProductImport\Service\ImageFingerprint;

try {
    $args = array_slice($argv, 1);
    $apply = in_array('--apply', $args, true);
    $args = array_values(array_diff($args, ['--apply']));
    if (count($args) !== 1 || ($args[0] !== 'all' && (!ctype_digit($args[0]) || (int) $args[0] < 1 || (string) (int) $args[0] !== $args[0]))) {
        throw new InvalidArgumentException('Usage: php bin/dedupe-images.php [all|<id_source>] [--apply]');
    }
    set_time_limit(0);
    $context = Context::getContext();
    if (!$context->shop) {
        $context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
    }
    if (!$context->language) {
        $context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
    }
    $fingerprinter = new ImageFingerprint();
    $scanned = 0;
    $duplicates = 0;
    $removed = 0;
    foreach ((new ExternalProductRepository())->findLinkedProductIds($args[0] === 'all' ? null : (int) $args[0]) as $idProduct) {
        ++$scanned;
        $rows = Image::getImages((int) $context->language->id, $idProduct);
        if (!is_array($rows)) {
            throw new RuntimeException('Unable to read images for product ' . $idProduct);
        }
        usort($rows, static function ($a, $b): int {
            return (int) $a['id_image'] <=> (int) $b['id_image'];
        });
        $kept = [];
        $duplicateIds = [];
        foreach ($rows as $row) {
            $idImage = (int) $row['id_image'];
            $path = _PS_PRODUCT_IMG_DIR_ . Image::getImgFolderStatic($idImage) . $idImage . '.jpg';
            $hash = $fingerprinter->fromFile($path);
            if ($hash === null) {
                continue;
            }
            foreach ($kept as $keptHash) {
                if ($fingerprinter->isSame($hash, $keptHash)) {
                    $duplicateIds[] = $idImage;
                    continue 2;
                }
            }
            $kept[$idImage] = $hash;
        }
        $duplicates += count($duplicateIds);
        echo 'Product ' . $idProduct . ': ' . count($rows) . ' images; duplicates ' . (implode(', ', $duplicateIds) ?: 'none') . PHP_EOL;
        if ($apply) {
            foreach ($duplicateIds as $idImage) {
                if (!(new Image($idImage))->delete()) {
                    throw new RuntimeException('Unable to delete image ' . $idImage);
                }
                ++$removed;
            }
            if ($kept && !Image::getCover($idProduct)) {
                $cover = new Image((int) array_key_first($kept));
                $cover->cover = true;
                if (!$cover->update()) {
                    throw new RuntimeException('Unable to restore cover for product ' . $idProduct);
                }
            }
        }
    }
    echo 'Summary: ' . $scanned . ' products scanned, ' . $duplicates . ' duplicate images found, ' . $removed . ' removed.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
