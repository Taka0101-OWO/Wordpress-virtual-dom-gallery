<?php

namespace Taka\VirtualGallery;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestApi
{
    private const NS = 'taka-gallery/v1';

    public function __construct(
        private Database $database,
        private ScanJob $scan_job,
        private ImageProcessor $processor,
        private Signer $signer,
        private MediaSession $media_session
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route(self::NS, '/galleries', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_galleries'], 'permission_callback' => '__return_true'],
                ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create_gallery'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/galleries/(?P<id>\d+)', [
                ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'update_gallery'], 'permission_callback' => [$this, 'can_manage']],
                ['methods' => WP_REST_Server::DELETABLE, 'callback' => [$this, 'delete_gallery'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/galleries/(?P<slug>[a-z0-9-]+)/items', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_public_items'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route(self::NS, '/folders', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_folders'], 'permission_callback' => [$this, 'can_manage']],
                ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'create_folder'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/folders/(?P<id>\d+)', [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_folder'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets', [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets/publish', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'publish_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets/unpublish', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'unpublish_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets/retry', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'retry_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets/exclude', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'exclude_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets/restore', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'restore_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/assets/assign', [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'assign_assets'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/jobs/scan', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_scan_job'], 'permission_callback' => [$this, 'can_manage']],
                ['methods' => WP_REST_Server::CREATABLE, 'callback' => [$this, 'run_scan'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/jobs/process', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'run_process'],
                'permission_callback' => [$this, 'can_manage'],
            ]);
            register_rest_route(self::NS, '/settings', [
                ['methods' => WP_REST_Server::READABLE, 'callback' => [$this, 'get_settings'], 'permission_callback' => [$this, 'can_manage']],
                ['methods' => WP_REST_Server::EDITABLE, 'callback' => [$this, 'update_settings'], 'permission_callback' => [$this, 'can_manage']],
            ]);
            register_rest_route(self::NS, '/media/refresh', [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'refresh_media'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_taka_galleries');
    }

    public function get_galleries(WP_REST_Request $request): WP_REST_Response
    {
        $admin = $this->can_manage() && $request->get_param('context') === 'edit';
        $rows = $this->database->get_galleries(!$admin);
        return new WP_REST_Response(array_map(static function (array $row) use ($admin): array {
            $gallery = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'status' => $row['status'],
                'menuOrder' => (int) $row['menu_order'],
                'publishedCount' => (int) $row['published_count'],
            ];
            if ($admin) {
                $gallery['pendingCount'] = (int) $row['pending_count'];
            }
            return $gallery;
        }, $rows));
    }

    public function create_gallery(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $name = sanitize_text_field((string) $request->get_param('name'));
        $slug = sanitize_title((string) ($request->get_param('slug') ?: $name));
        if ($name === '' || $slug === '') {
            return new WP_Error('taka_invalid_gallery', 'Gallery name and slug are required.', ['status' => 400]);
        }
        $wpdb = $this->database->wpdb();
        $ok = $wpdb->insert($this->database->table('galleries'), [
            'name' => $name,
            'slug' => $slug,
            'status' => $request->get_param('status') === 'draft' ? 'draft' : 'publish',
            'menu_order' => (int) $request->get_param('menuOrder'),
            'created_at' => current_time('mysql', true),
            'updated_at' => current_time('mysql', true),
        ]);
        if (!$ok) {
            return new WP_Error('taka_gallery_exists', 'A gallery with this slug already exists.', ['status' => 409]);
        }
        return new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201);
    }

    public function update_gallery(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request['id'];
        $data = [];
        foreach (['name', 'slug', 'status', 'menuOrder'] as $field) {
            if (!$request->has_param($field)) {
                continue;
            }
            $data[match ($field) {
                'menuOrder' => 'menu_order',
                default => $field,
            }] = match ($field) {
                'slug' => sanitize_title((string) $request[$field]),
                'status' => $request[$field] === 'draft' ? 'draft' : 'publish',
                'menuOrder' => (int) $request[$field],
                default => sanitize_text_field((string) $request[$field]),
            };
        }
        $data['updated_at'] = current_time('mysql', true);
        $result = $this->database->wpdb()->update($this->database->table('galleries'), $data, ['id' => $id]);
        return $result === false
            ? new WP_Error('taka_gallery_update_failed', 'Gallery could not be updated.', ['status' => 400])
            : new WP_REST_Response(['updated' => true]);
    }

    public function delete_gallery(WP_REST_Request $request): WP_REST_Response
    {
        $id = (int) $request['id'];
        $wpdb = $this->database->wpdb();
        $wpdb->delete($this->database->table('gallery_folders'), ['gallery_id' => $id]);
        $wpdb->delete($this->database->table('gallery_items'), ['gallery_id' => $id]);
        $wpdb->delete($this->database->table('galleries'), ['id' => $id]);
        return new WP_REST_Response(['deleted' => true]);
    }

    public function get_folders(): WP_REST_Response
    {
        $wpdb = $this->database->wpdb();
        $rows = $wpdb->get_results(
            "SELECT f.*, g.name AS gallery_name FROM {$this->database->table('gallery_folders')} f
             INNER JOIN {$this->database->table('galleries')} g ON g.id = f.gallery_id ORDER BY f.id ASC",
            ARRAY_A
        ) ?: [];
        return new WP_REST_Response(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'galleryId' => (int) $row['gallery_id'], 'galleryName' => $row['gallery_name'],
            'relativePath' => $row['relative_path'], 'enabled' => (bool) $row['enabled'],
            'lastScanAt' => $row['last_scan_at'], 'lastError' => $row['last_error'], 'scanCursor' => $row['scan_cursor'],
        ], $rows));
    }

    public function create_folder(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $gallery_id = (int) $request->get_param('galleryId');
        $path = trim(str_replace('\\', '/', sanitize_text_field((string) $request->get_param('relativePath'))), '/');
        if ($gallery_id < 1 || str_contains($path, '..')) {
            return new WP_Error('taka_invalid_folder', 'A valid gallery and relative NAS path are required.', ['status' => 400]);
        }
        $wpdb = $this->database->wpdb();
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->database->table('galleries')} WHERE id = %d", $gallery_id));
        if (!$exists) {
            return new WP_Error('taka_gallery_not_found', 'Gallery not found.', ['status' => 404]);
        }
        $result = $wpdb->insert($this->database->table('gallery_folders'), [
            'gallery_id' => $gallery_id,
            'relative_path' => $path,
            'path_hash' => hash('sha256', strtolower($path)),
            'enabled' => 1,
        ]);
        return $result
            ? new WP_REST_Response(['id' => (int) $wpdb->insert_id], 201)
            : new WP_Error('taka_folder_exists', 'This NAS folder is already mapped.', ['status' => 409]);
    }

    public function delete_folder(WP_REST_Request $request): WP_REST_Response
    {
        $this->database->wpdb()->delete($this->database->table('gallery_folders'), ['id' => (int) $request['id']]);
        return new WP_REST_Response(['deleted' => true]);
    }

    public function get_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session_id = $this->media_session->establish($request);
        if ($session_id === null) {
            return new WP_Error('taka_invalid_session', 'A valid gallery session is required.', ['status' => 403]);
        }
        $status = sanitize_key((string) ($request->get_param('status') ?: 'pending_review'));
        $gallery_id = (int) $request->get_param('galleryId');
        $page = max(1, (int) $request->get_param('page'));
        $per_page = max(12, min(120, (int) ($request->get_param('perPage') ?: 120)));
        $offset = ($page - 1) * $per_page;
        $wpdb = $this->database->wpdb();
        $assets = $this->database->table('assets');
        $items = $this->database->table('gallery_items');
        $galleries = $this->database->table('galleries');
        $where = ['a.status = %s'];
        $args = [$status];
        if ($gallery_id > 0) {
            $where[] = 'i.gallery_id = %d';
            $args[] = $gallery_id;
        }
        $where_sql = implode(' AND ', $where);
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT a.id) FROM {$assets} a LEFT JOIN {$items} i ON i.asset_id = a.id WHERE {$where_sql}",
            ...$args
        ));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, i.gallery_id, i.status AS item_status, g.name AS gallery_name
             FROM {$assets} a LEFT JOIN {$items} i ON i.asset_id = a.id LEFT JOIN {$galleries} g ON g.id = i.gallery_id
             WHERE {$where_sql} ORDER BY a.updated_at DESC, a.id DESC LIMIT %d OFFSET %d",
            ...array_merge($args, [$per_page, $offset])
        ), ARRAY_A) ?: [];
        $expires = time() + 600;
        return $this->private_media_response([
            'items' => array_map(fn (array $row): array => $this->admin_asset($row, $expires, $session_id), $rows),
            'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $per_page),
        ]);
    }

    public function publish_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ids = $this->id_list($request->get_param('assetIds'));
        if (!$ids) {
            return new WP_Error('taka_no_assets', 'Select at least one asset.', ['status' => 400]);
        }
        $wpdb = $this->database->wpdb();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $now = current_time('mysql', true);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->database->table('assets')} SET status = 'published', updated_at = %s
             WHERE id IN ({$placeholders}) AND status IN ('pending_review', 'published')",
            ...array_merge([$now], $ids)
        ));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->database->table('gallery_items')} SET status = 'publish', published_at = %s
             WHERE asset_id IN ({$placeholders})",
            ...array_merge([$now], $ids)
        ));
        return new WP_REST_Response(['published' => count($ids)]);
    }

    public function retry_assets(WP_REST_Request $request): WP_REST_Response
    {
        $ids = $this->id_list($request->get_param('assetIds'));
        foreach ($ids as $id) {
            $this->processor->retry($id);
        }
        return new WP_REST_Response(['queued' => count($ids)]);
    }

    public function unpublish_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->transition_assets($request, 'published', 'pending_review', 'publish', 'pending', 'unpublished');
    }

    public function exclude_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->transition_assets($request, 'pending_review', 'excluded', 'pending', 'excluded', 'excluded');
    }

    public function restore_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->transition_assets($request, 'excluded', 'pending_review', 'excluded', 'pending', 'restored');
    }

    public function assign_assets(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ids = $this->id_list($request->get_param('assetIds'));
        $gallery_id = (int) $request->get_param('galleryId');
        if (!$ids || $gallery_id < 1) {
            return new WP_Error('taka_invalid_assignment', 'Select assets and a destination gallery.', ['status' => 400]);
        }
        $wpdb = $this->database->wpdb();
        $gallery_exists = (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$this->database->table('galleries')} WHERE id = %d", $gallery_id));
        if (!$gallery_exists) {
            return new WP_Error('taka_gallery_not_found', 'Destination gallery not found.', ['status' => 404]);
        }
        $items = $this->database->table('gallery_items');
        foreach ($ids as $asset_id) {
            $status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$items} WHERE asset_id = %d LIMIT 1", $asset_id));
            $wpdb->delete($items, ['asset_id' => $asset_id]);
            $wpdb->insert($items, ['gallery_id' => $gallery_id, 'asset_id' => $asset_id, 'position' => 0, 'status' => $status === 'publish' ? 'publish' : 'pending']);
        }
        return new WP_REST_Response(['assigned' => count($ids)]);
    }

    public function run_scan(): WP_REST_Response
    {
        return new WP_REST_Response($this->scan_job->start(), 202);
    }

    public function get_scan_job(): WP_REST_Response
    {
        return new WP_REST_Response($this->scan_job->status());
    }

    public function run_process(): WP_REST_Response
    {
        return new WP_REST_Response($this->processor->process_queue());
    }

    public function get_settings(): WP_REST_Response
    {
        $settings = $this->database->settings();
        $settings['originalsReadable'] = is_readable((string) $settings['originals_path']);
        $settings['derivativesWritable'] = is_writable((string) $settings['derivatives_path']);
        $settings['imagickAvailable'] = class_exists('Imagick');
        $settings['xSendfileReady'] = MediaEndpoint::xsendfile_ready();
        return new WP_REST_Response($settings);
    }

    public function update_settings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $settings = $this->database->settings();
        foreach (['originals_path', 'derivatives_path'] as $field) {
            if ($request->has_param($field)) {
                $value = sanitize_text_field((string) $request[$field]);
                if ($value === '' || !str_starts_with($value, '/')) {
                    return new WP_Error('taka_invalid_path', 'Storage paths must be absolute.', ['status' => 400]);
                }
                $settings[$field] = rtrim($value, '/');
            }
        }
        if ($request->has_param('url_ttl')) {
            $settings['url_ttl'] = max(60, min(3600, (int) $request['url_ttl']));
        }
        if ($request->has_param('scan_batch')) {
            $settings['scan_batch'] = max(10, min(1000, (int) $request['scan_batch']));
        }
        if ($request->has_param('process_batch')) {
            $settings['process_batch'] = max(1, min(20, (int) $request['process_batch']));
        }
        update_option('taka_gallery_settings', $settings, false);
        return $this->get_settings();
    }

    public function get_public_items(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session_id = $this->media_session->establish($request);
        if ($session_id === null) {
            return new WP_Error('taka_invalid_session', 'A valid gallery session is required.', ['status' => 403]);
        }
        if (!$this->allow_public_request($request, 180)) {
            return new WP_Error('taka_rate_limited', 'Too many gallery requests.', ['status' => 429]);
        }
        $gallery = $this->database->get_gallery_by_slug((string) $request['slug']);
        if (!$gallery || $gallery['status'] !== 'publish') {
            return new WP_Error('taka_gallery_not_found', 'Gallery not found.', ['status' => 404]);
        }
        $seed = strtolower((string) $request->get_param('shuffle_seed'));
        if (!preg_match('/^[a-f0-9]{16}$/', $seed)) {
            $seed = bin2hex(random_bytes(8));
        }
        $limit = max(1, min(60, (int) ($request->get_param('limit') ?: 30)));
        [$cursor_rank, $cursor_id] = $this->decode_cursor((string) $request->get_param('cursor'));
        $wpdb = $this->database->wpdb();
        $assets = $this->database->table('assets');
        $items = $this->database->table('gallery_items');
        $rank_sql = 'CRC32(CONCAT(a.public_id, %s))';
        $cursor_sql = '';
        $args = [$seed, (int) $gallery['id']];
        if ($cursor_rank !== null) {
            $cursor_sql = " AND ({$rank_sql} > %d OR ({$rank_sql} = %d AND a.id > %d))";
            array_push($args, $seed, $cursor_rank, $seed, $cursor_rank, $cursor_id);
        }
        $args[] = $limit + 1;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, {$rank_sql} AS shuffle_rank FROM {$assets} a
             INNER JOIN {$items} i ON i.asset_id = a.id
             WHERE i.gallery_id = %d AND i.status = 'publish' AND a.status = 'published' {$cursor_sql}
             ORDER BY shuffle_rank ASC, a.id ASC LIMIT %d",
            ...$args
        ), ARRAY_A) ?: [];
        $has_more = count($rows) > $limit;
        if ($has_more) {
            array_pop($rows);
        }
        $expires = time() + (int) $this->database->settings()['url_ttl'];
        $items_response = array_map(fn (array $row): array => $this->public_asset($row, $expires, $session_id), $rows);
        $last = end($rows);
        return $this->private_media_response([
            'items' => $items_response,
            'shuffleSeed' => $seed,
            'nextCursor' => $has_more && $last ? $this->encode_cursor((int) $last['shuffle_rank'], (int) $last['id']) : null,
        ]);
    }

    public function refresh_media(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session_id = $this->media_session->establish($request);
        if ($session_id === null) {
            return new WP_Error('taka_invalid_session', 'A valid gallery session is required.', ['status' => 403]);
        }
        if (!$this->allow_public_request($request, 120)) {
            return new WP_Error('taka_rate_limited', 'Too many media refresh requests.', ['status' => 429]);
        }
        $public_ids = array_slice(array_values(array_filter(array_map('sanitize_key', (array) $request->get_param('publicIds')), static fn ($id) => preg_match('/^[a-f0-9]{32}$/', $id))), 0, 60);
        if (!$public_ids) {
            return $this->private_media_response(['items' => []]);
        }
        $wpdb = $this->database->wpdb();
        $marks = implode(',', array_fill(0, count($public_ids), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT a.* FROM {$this->database->table('assets')} a
             INNER JOIN {$this->database->table('gallery_items')} i ON i.asset_id = a.id
             WHERE a.public_id IN ({$marks}) AND a.status = 'published' AND i.status = 'publish'",
            ...$public_ids
        ), ARRAY_A) ?: [];
        $expires = time() + (int) $this->database->settings()['url_ttl'];
        return $this->private_media_response(['items' => array_map(fn ($row) => $this->public_asset($row, $expires, $session_id), $rows)]);
    }

    private function private_media_response(array $data): WP_REST_Response
    {
        $response = new WP_REST_Response($data);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'X-Taka-Session, Cookie');
        return $response;
    }

    private function public_asset(array $row, int $expires, string $session_id): array
    {
        return [
            'id' => $row['public_id'], 'width' => (int) $row['width'], 'height' => (int) $row['height'],
            'alt' => $row['alt_text'], 'placeholder' => $row['placeholder'],
            'sources' => array_map(fn (int $width): array => ['width' => $width] + $this->signer->sign($row['public_id'], $width, $session_id, $expires), Signer::WIDTHS),
        ];
    }

    private function admin_asset(array $row, int $expires, string $session_id): array
    {
        $asset = (int) $row['width'] > 0 && (int) $row['height'] > 0
            ? $this->public_asset($row, $expires, $session_id)
            : ['id' => $row['public_id'], 'width' => 0, 'height' => 0, 'alt' => '', 'placeholder' => '#111111', 'sources' => []];
        return $asset + [
            'assetId' => (int) $row['id'], 'status' => $row['status'], 'galleryId' => isset($row['gallery_id']) ? (int) $row['gallery_id'] : null,
            'galleryName' => $row['gallery_name'] ?? null, 'relativePath' => $row['relative_path'],
            'error' => $row['error_message'], 'updatedAt' => $row['updated_at'],
        ];
    }

    private function id_list(mixed $input): array
    {
        return array_values(array_unique(array_filter(array_map('absint', (array) $input))));
    }

    private function transition_assets(
        WP_REST_Request $request,
        string $asset_from,
        string $asset_to,
        string $item_from,
        string $item_to,
        string $response_key
    ): WP_REST_Response|WP_Error {
        $ids = $this->id_list($request->get_param('assetIds'));
        if (!$ids) {
            return new WP_Error('taka_no_assets', 'Select at least one asset.', ['status' => 400]);
        }

        $wpdb = $this->database->wpdb();
        $marks = implode(',', array_fill(0, count($ids), '%d'));
        $valid_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->database->table('assets')} WHERE id IN ({$marks}) AND status = %s",
            ...array_merge($ids, [$asset_from])
        )) ?: []);
        if (!$valid_ids) {
            return new WP_REST_Response([$response_key => 0]);
        }
        $marks = implode(',', array_fill(0, count($valid_ids), '%d'));
        $now = current_time('mysql', true);
        $wpdb->query('START TRANSACTION');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->database->table('assets')} SET status = %s, updated_at = %s
             WHERE id IN ({$marks}) AND status = %s",
            ...array_merge([$asset_to, $now], $valid_ids, [$asset_from])
        ));
        $items_updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->database->table('gallery_items')} SET status = %s, published_at = NULL
             WHERE asset_id IN ({$marks}) AND status = %s",
            ...array_merge([$item_to], $valid_ids, [$item_from])
        ));
        if ($updated === false || $items_updated === false) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('taka_asset_transition_failed', 'The selected assets could not be updated.', ['status' => 500]);
        }
        $wpdb->query('COMMIT');
        return new WP_REST_Response([$response_key => (int) $updated]);
    }

    private function encode_cursor(int $rank, int $id): string
    {
        return rtrim(strtr(base64_encode(wp_json_encode([$rank, $id])), '+/', '-_'), '=');
    }

    private function decode_cursor(string $cursor): array
    {
        if ($cursor === '') {
            return [null, 0];
        }
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        return is_array($decoded) && count($decoded) === 2 ? [(int) $decoded[0], (int) $decoded[1]] : [null, 0];
    }

    private function allow_public_request(WP_REST_Request $request, int $limit): bool
    {
        $session = strtolower((string) $request->get_header('x-taka-session'));
        if (!preg_match('/^[a-f0-9]{32}$/', $session)) {
            $session = hash('md5', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        }
        $bucket = 'taka_rl_' . substr(hash('sha256', $session . '|' . gmdate('YmdHi')), 0, 32);
        $count = (int) get_transient($bucket);
        if ($count >= $limit) {
            return false;
        }
        set_transient($bucket, $count + 1, 70);
        return true;
    }

}
