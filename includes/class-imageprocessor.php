<?php

namespace Taka\VirtualGallery;

use Imagick;

final class ImageProcessor
{
    public function __construct(private Database $database)
    {
    }

    public function process_queue(): array
    {
        if (!$this->acquire_lock()) {
            return ['processed' => 0, 'errors' => [], 'locked' => true];
        }
        try {
            return $this->process_queue_unlocked();
        } finally {
            delete_option('taka_gallery_process_lock');
        }
    }

    private function process_queue_unlocked(): array
    {
        $settings = $this->database->settings();
        $limit = max(1, min(20, (int) $settings['process_batch']));
        $wpdb = $this->database->wpdb();
        $assets = $this->database->table('assets');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$assets} WHERE status = 'queued' ORDER BY id ASC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
        $result = ['processed' => 0, 'errors' => []];

        foreach ($rows as $asset) {
            try {
                $this->process_asset($asset);
                $result['processed']++;
            } catch (\Throwable $error) {
                $wpdb->update($assets, [
                    'status' => 'error',
                    'error_message' => mb_substr($error->getMessage(), 0, 2000),
                    'updated_at' => current_time('mysql', true),
                ], ['id' => (int) $asset['id']]);
                $result['errors'][] = ['assetId' => (int) $asset['id'], 'message' => $error->getMessage()];
            }
        }
        return $result;
    }

    private function acquire_lock(): bool
    {
        $key = 'taka_gallery_process_lock';
        $locked_at = (int) get_option($key, 0);
        if ($locked_at && time() - $locked_at < 300) {
            return false;
        }
        if ($locked_at) {
            delete_option($key);
        }
        return add_option($key, time(), '', false);
    }

    public function process_asset(array $asset): void
    {
        if (!class_exists(Imagick::class)) {
            throw new \RuntimeException('Imagick is required to generate private gallery derivatives.');
        }

        $settings = $this->database->settings();
        $source_root = $this->root((string) $settings['originals_path'], false);
        $destination_root = $this->root((string) $settings['derivatives_path'], true);
        $source = $source_root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $asset['relative_path']);
        $real_source = realpath($source);
        if ($real_source === false || !str_starts_with($real_source, $source_root . DIRECTORY_SEPARATOR) || !is_readable($real_source)) {
            throw new \RuntimeException('Source image is missing or unreadable.');
        }

        $target_dir = $destination_root . DIRECTORY_SEPARATOR . $asset['public_id'];
        if (!wp_mkdir_p($target_dir) || !is_writable($target_dir)) {
            throw new \RuntimeException('Derivative directory is not writable.');
        }

        $image = new Imagick($real_source);
        $image->setIteratorIndex(0);
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } else {
            $image->autoOrientImage();
        }
        $image->setImagePage(0, 0, 0, 0);
        $image->stripImage();
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        if ($width < 1 || $height < 1) {
            throw new \RuntimeException('Source image has invalid dimensions.');
        }
        $placeholder = $this->placeholder($image);

        foreach (Signer::WIDTHS as $target_width) {
            $variant = clone $image;
            if ($variant->getImageWidth() > $target_width) {
                $variant->thumbnailImage($target_width, 0, true, true);
            }
            $variant->stripImage();
            $variant->setImageFormat('webp');
            $variant->setImageCompressionQuality(82);
            $variant->setOption('webp:method', '5');
            $destination = $target_dir . DIRECTORY_SEPARATOR . $target_width . '.webp';
            $temporary = $destination . '.tmp-' . bin2hex(random_bytes(4));
            if (!$variant->writeImage($temporary) || !rename($temporary, $destination)) {
                @unlink($temporary);
                throw new \RuntimeException('Unable to write a derivative image.');
            }
            @chmod($destination, 0640);
            $variant->clear();
            $variant->destroy();
        }
        $image->clear();
        $image->destroy();

        $this->database->wpdb()->update($this->database->table('assets'), [
            'content_hash' => hash_file('sha256', $real_source),
            'width' => $width,
            'height' => $height,
            'placeholder' => $placeholder,
            'status' => 'pending_review',
            'error_message' => null,
            'updated_at' => current_time('mysql', true),
        ], ['id' => (int) $asset['id']]);
    }

    public function retry(int $asset_id): bool
    {
        return false !== $this->database->wpdb()->update(
            $this->database->table('assets'),
            ['status' => 'queued', 'error_message' => null, 'updated_at' => current_time('mysql', true)],
            ['id' => $asset_id]
        );
    }

    private function placeholder(Imagick $image): string
    {
        $sample = clone $image;
        $sample->resizeImage(1, 1, Imagick::FILTER_BOX, 1);
        $color = $sample->getImagePixelColor(0, 0)->getColor();
        $sample->clear();
        $sample->destroy();
        return sprintf('#%02x%02x%02x', $color['r'] ?? 17, $color['g'] ?? 17, $color['b'] ?? 17);
    }

    private function root(string $path, bool $writable): string
    {
        if ($writable && !is_dir($path)) {
            wp_mkdir_p($path);
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real) || !is_readable($real) || ($writable && !is_writable($real))) {
            throw new \RuntimeException($writable ? 'Derivative root is unavailable or read-only.' : 'Originals root is unavailable.');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }
}
