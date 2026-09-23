<?php

namespace ProductImport\Service;

class ProductImageAttacher
{
    public function attach(int $idProduct, string $url): int
    {
        $temporary = tempnam(_PS_TMP_IMG_DIR_, 'pi_');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to allocate image temporary file.');
        }
        $image = null;
        try {
            $this->downloadImage($url, $temporary);
            $image = new \Image();
            $image->id_product = $idProduct;
            $image->position = (int) \Image::getHighestPosition($idProduct) + 1;
            $image->cover = \Image::getCover($idProduct) ? null : true;
            if (!$image->add()) {
                throw new \RuntimeException('Unable to create product image record.');
            }
            $image->associateTo([(int) \Context::getContext()->shop->id]);
            $path = $image->getPathForCreation();
            if (!$path || !\ImageManager::resize($temporary, $path . '.jpg')) {
                throw new \RuntimeException('Unable to write product image.');
            }
            foreach (\ImageType::getImagesTypes('products') as $type) {
                if (!\ImageManager::resize($temporary, $path . '-' . stripslashes($type['name']) . '.jpg', (int) $type['width'], (int) $type['height'])) {
                    throw new \RuntimeException('Unable to generate product image thumbnail.');
                }
            }
            return (int) $image->id;
        } catch (\Throwable $error) {
            if ($image !== null && $image->id) {
                $image->delete();
            }
            throw $error;
        } finally {
            unlink($temporary);
        }
    }

    private function downloadImage(string $url, string $destination): void
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \RuntimeException('Image URL must use HTTP or HTTPS.');
        }
        $file = fopen($destination, 'wb');
        if ($file === false) {
            throw new \RuntimeException('Unable to open temporary image file.');
        }
        $handle = curl_init($url);
        try {
            if ($handle === false) {
                throw new \RuntimeException('Unable to initialize image download.');
            }
            $protocolOption = defined('CURLOPT_PROTOCOLS_STR') ? constant('CURLOPT_PROTOCOLS_STR') : CURLOPT_PROTOCOLS;
            $protocolValue = defined('CURLOPT_PROTOCOLS_STR') ? 'http,https' : CURLPROTO_HTTP | CURLPROTO_HTTPS;
            curl_setopt_array($handle, [
                CURLOPT_FILE => $file,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_TIMEOUT => 120,
                $protocolOption => $protocolValue,
            ]);
            if (curl_exec($handle) === false) {
                throw new \RuntimeException('Image download failed: ' . curl_error($handle));
            }
            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('Image download returned HTTP ' . $status . '.');
            }
        } finally {
            fclose($file);
            if (is_resource($handle)) {
                curl_close($handle);
            }
        }
    }
}
