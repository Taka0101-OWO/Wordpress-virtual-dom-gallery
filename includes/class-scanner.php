<?php

namespace Taka\VirtualGallery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Scanner
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif'];

    public function __construct(private Database $database, private ImageProcessor $processor)
    {
    }

    public function scan_all(): array
    {
        if (!$this->acquire_lock()) {
            return ['folders' => 0, 'discovered' => 0, 'queued' => 0, 'errors' => [], 'locked' => true];
        }
        try {
            return $this->scan_all_unlocked();
        } finally {
            delete_option('taka_gallery_scan_lock');
        }
    }

    private function scan_all_unlocked(): array
    {
        $wpdb = $this->database->wpdb();
        $folders = $wpdb->get_results(
            "SELECT * FROM {$this->database->table('gallery_folders')} WHERE enabled = 1 ORDER BY id ASC",
            ARRAY_A
        ) ?: [];
        $result = ['folders' => 0, 'discovered' => 0, 'queued' => 0, 'errors' => []];

        foreach ($folders as $folder) {
            try {
                $scan = $this->scan_folder($folder);
                $result['folders']++;
                $result['discovered'] += $scan['discovered'];
                $result['queued'] += $scan['queued'];
            } catch (\Throwable $error) {
                $result['errors'][] = ['folderId' => (int) $folder['id'], 'message' => $error->getMessage()];
                $wpdb->update(
                    $this->database->table('gallery_folders'),
                    ['last_error' => $error->getMessage()],
                    ['id' => (int) $folder['id']],
                    ['%s'],
                    ['%d']
                );
            }
        }
        return $result;
    }

    private function acquire_lock(): bool
    {
        $key = 'taka_gallery_scan_lock';
        $locked_at = (int) get_option($key, 0);
        if ($locked_at && time() - $locked_at < 300) {
            return false;
        }
        if ($locked_at) {
            delete_option($key);
        }
        return add_option($key, time(), '', false);
    }

    public function scan_folder(array $folder): array
    {
        $settings = $this->database->settings();
        $root = $this->canonical_root((string) $settings['originals_path']);
        $relative_folder = $this->sanitize_relative_path((string) $folder['relative_path']);
        $absolute_folder = $root . ($relative_folder !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_folder) : '');
        $real_folder = realpath($absolute_folder);

        if ($real_folder === false || !$this->is_inside($real_folder, $root) || !is_dir($real_folder) || !is_readable($real_folder)) {
            throw new \RuntimeException('Mapped NAS folder is missing or unreadable.');
        }

        $wpdb = $this->database->wpdb();
        $folder_table = $this->database->table('gallery_folders');
        $batch_size = max(10, min(1000, (int) $settings['scan_batch']));
        $started_at = $folder['scan_started_at'] ?: current_time('mysql', true);
        if (!$folder['scan_started_at']) {
            $wpdb->update($folder_table, ['scan_started_at' => $started_at, 'last_error' => null], ['id' => (int) $folder['id']]);
        }

        $paths = $this->list_images($real_folder, $root);
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);
        $cursor = (string) ($folder['scan_cursor'] ?? '');
        if ($cursor !== '') {
            $paths = array_values(array_filter($paths, static fn (string $path): bool => strcmp($path, $cursor) > 0));
        }
        $batch = array_slice($paths, 0, $batch_size);
        $stats = ['discovered' => 0, 'queued' => 0];
        foreach ($batch as $relative_path) {
            $change = $this->observe_file((int) $folder['gallery_id'], $relative_path, $root);
            $stats[$change]++;
        }

        if (count($paths) > count($batch)) {
            $wpdb->update($folder_table, ['scan_cursor' => end($batch), 'last_error' => null], ['id' => (int) $folder['id']]);
        } else {
            $this->mark_missing($relative_folder, $started_at);
            $wpdb->update(
                $folder_table,
                ['scan_cursor' => null, 'scan_started_at' => null, 'last_scan_at' => current_time('mysql', true), 'last_error' => null],
                ['id' => (int) $folder['id']]
            );
        }
        return $stats;
    }

    private function observe_file(int $gallery_id, string $relative_path, string $root): string
    {
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        $size = filesize($absolute);
        $mtime = filemtime($absolute);
        if ($size === false || $mtime === false) {
            throw new \RuntimeException('Unable to stat NAS file: ' . $relative_path);
        }

        $wpdb = $this->database->wpdb();
        $assets = $this->database->table('assets');
        $items = $this->database->table('gallery_items');
        $path_hash = hash('sha256', $relative_path);
        $asset = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$assets} WHERE path_hash = %s", $path_hash), ARRAY_A);
        $now = time();
        $now_mysql = current_time('mysql', true);
        $state = 'discovered';

        if (!$asset) {
            $wpdb->insert($assets, [
                'public_id' => bin2hex(random_bytes(16)),
                'relative_path' => $relative_path,
                'path_hash' => $path_hash,
                'file_size' => $size,
                'file_mtime' => $mtime,
                'stable_since' => $now,
                'last_seen_at' => $now_mysql,
                'status' => 'discovered',
                'created_at' => $now_mysql,
                'updated_at' => $now_mysql,
            ]);
            $asset_id = (int) $wpdb->insert_id;
        } else {
            $asset_id = (int) $asset['id'];
            $changed = (int) $asset['file_size'] !== (int) $size || (int) $asset['file_mtime'] !== (int) $mtime;
            $stable_since = $changed ? $now : (int) $asset['stable_since'];
            $state = (!$changed && $now - $stable_since >= 30 && in_array($asset['status'], ['discovered', 'missing', 'error'], true)) ? 'queued' : (string) $asset['status'];
            $wpdb->update($assets, [
                'relative_path' => $relative_path,
                'file_size' => $size,
                'file_mtime' => $mtime,
                'stable_since' => $stable_since,
                'last_seen_at' => $now_mysql,
                'status' => $state,
                'error_message' => null,
                'updated_at' => $now_mysql,
            ], ['id' => $asset_id]);
        }

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$items} (gallery_id, asset_id, position, status)
             VALUES (%d, %d, 0, 'pending')
             ON DUPLICATE KEY UPDATE gallery_id = VALUES(gallery_id)",
            $gallery_id,
            $asset_id
        ));
        return $state === 'queued' ? 'queued' : 'discovered';
    }

    private function mark_missing(string $folder, string $scan_started_at): void
    {
        $wpdb = $this->database->wpdb();
        $assets = $this->database->table('assets');
        $prefix = $folder === '' ? '%' : $wpdb->esc_like($folder . '/') . '%';
        $wpdb->query($wpdb->prepare(
            "UPDATE {$assets} SET status = CASE WHEN status = 'published' THEN status ELSE 'missing' END,
             error_message = 'Source file is missing from NAS', updated_at = %s
             WHERE relative_path LIKE %s AND (last_seen_at IS NULL OR last_seen_at < %s)
             AND status <> 'missing'",
            current_time('mysql', true),
            $prefix,
            $scan_started_at
        ));
    }

    private function list_images(string $folder, string $root): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO)
        );
        $paths = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!in_array($extension, self::EXTENSIONS, true)) {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false || !$this->is_inside($real, $root)) {
                continue;
            }
            $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
        }
        return $paths;
    }

    private function canonical_root(string $path): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException('The configured originals directory is unavailable.');
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    private function sanitize_relative_path(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || (!str_contains($path, '..') && !str_starts_with($path, '/'))) {
            return $path;
        }
        throw new \InvalidArgumentException('Invalid relative folder path.');
    }

    private function is_inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}
