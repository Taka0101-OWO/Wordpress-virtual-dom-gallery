<?php

namespace Taka\VirtualGallery;

use wpdb;

final class Database
{
    public const DB_VERSION = '2';

    private wpdb $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $galleries = $wpdb->prefix . 'taka_galleries';
        $assets = $wpdb->prefix . 'taka_assets';
        $items = $wpdb->prefix . 'taka_gallery_items';
        $folders = $wpdb->prefix . 'taka_gallery_folders';

        dbDelta("CREATE TABLE {$galleries} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(190) NOT NULL,
            slug varchar(190) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'publish',
            menu_order int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status_order (status, menu_order)
        ) {$charset};");

        dbDelta("CREATE TABLE {$assets} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id char(32) NOT NULL,
            relative_path text NOT NULL,
            path_hash char(64) NOT NULL,
            content_hash char(64) DEFAULT NULL,
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            file_mtime bigint(20) unsigned NOT NULL DEFAULT 0,
            stable_since bigint(20) unsigned NOT NULL DEFAULT 0,
            last_seen_at datetime DEFAULT NULL,
            width int(10) unsigned NOT NULL DEFAULT 0,
            height int(10) unsigned NOT NULL DEFAULT 0,
            placeholder varchar(7) NOT NULL DEFAULT '#111111',
            alt_text varchar(500) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT 'discovered',
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            UNIQUE KEY path_hash (path_hash),
            KEY content_hash (content_hash),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            gallery_id bigint(20) unsigned NOT NULL,
            asset_id bigint(20) unsigned NOT NULL,
            position int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            published_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY gallery_asset (gallery_id, asset_id),
            KEY gallery_status (gallery_id, status, position),
            KEY asset_id (asset_id)
        ) {$charset};");

        dbDelta("CREATE TABLE {$folders} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            gallery_id bigint(20) unsigned NOT NULL,
            relative_path text NOT NULL,
            path_hash char(64) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            scan_cursor text DEFAULT NULL,
            scan_started_at datetime DEFAULT NULL,
            last_scan_at datetime DEFAULT NULL,
            last_error text DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY path_hash (path_hash),
            KEY gallery_id (gallery_id)
        ) {$charset};");

        update_option('taka_gallery_db_version', self::DB_VERSION, false);
        update_option('taka_gallery_flush_rewrite', 1, false);
        add_option('taka_gallery_settings', [
            'originals_path' => '',
            'derivatives_path' => '',
            'url_ttl' => 600,
            'scan_batch' => 100,
            'process_batch' => 5,
            'legacy_upload_prefix' => '',
        ]);

        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_taka_galleries');
        }

        if (!wp_next_scheduled(Cron::SCAN_HOOK)) {
            wp_schedule_event(time() + 60, 'taka_gallery_minute', Cron::SCAN_HOOK);
        }
        if (!wp_next_scheduled(Cron::PROCESS_HOOK)) {
            wp_schedule_event(time() + 90, 'taka_gallery_minute', Cron::PROCESS_HOOK);
        }

        flush_rewrite_rules();
    }

    public function table(string $name): string
    {
        return $this->wpdb->prefix . 'taka_' . $name;
    }

    public function maybe_upgrade(): void
    {
        if ((string) get_option('taka_gallery_db_version', '0') === self::DB_VERSION) {
            return;
        }
        $folders = $this->table('gallery_folders');
        $this->wpdb->query("UPDATE {$folders} SET scan_cursor = NULL, scan_started_at = NULL");
        delete_option('taka_gallery_scan_job');
        update_option('taka_gallery_db_version', self::DB_VERSION, false);
    }

    public function wpdb(): wpdb
    {
        return $this->wpdb;
    }

    public function settings(): array
    {
        return wp_parse_args(get_option('taka_gallery_settings', []), [
            'originals_path' => '',
            'derivatives_path' => '',
            'url_ttl' => 600,
            'scan_batch' => 100,
            'process_batch' => 5,
            'legacy_upload_prefix' => '',
        ]);
    }

    public function get_galleries(bool $published_only = false): array
    {
        $table = $this->table('galleries');
        $where = $published_only ? "WHERE g.status = 'publish'" : '';
        $items = $this->table('gallery_items');
        $assets = $this->table('assets');
        $sql = "SELECT g.*, COUNT(CASE WHEN i.status = 'publish' AND a.status = 'published' THEN 1 END) AS published_count,
                       COUNT(CASE WHEN i.status = 'pending' AND a.status = 'pending_review' THEN 1 END) AS pending_count
                FROM {$table} g LEFT JOIN {$items} i ON i.gallery_id = g.id
                LEFT JOIN {$assets} a ON a.id = i.asset_id
                {$where} GROUP BY g.id ORDER BY g.menu_order ASC, g.id ASC";
        return $this->wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function get_gallery_by_slug(string $slug): ?array
    {
        $table = $this->table('galleries');
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug), ARRAY_A);
        return $row ?: null;
    }

    public function get_asset_by_public_id(string $public_id): ?array
    {
        $table = $this->table('assets');
        $row = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$table} WHERE public_id = %s", $public_id), ARRAY_A);
        return $row ?: null;
    }
}
