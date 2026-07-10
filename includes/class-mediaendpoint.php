<?php

namespace Taka\VirtualGallery;

final class MediaEndpoint
{
    public function __construct(private Database $database, private Signer $signer, private MediaSession $media_session)
    {
    }

    public function register(): void
    {
        add_action('init', function (): void {
            add_rewrite_rule('^taka-media/v1/([a-f0-9]{32})/(480|960|1440)\.webp$', 'index.php?taka_media_id=$matches[1]&taka_media_width=$matches[2]', 'top');
        }, 5);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'taka_media_id';
            $vars[] = 'taka_media_width';
            return $vars;
        });
        add_action('template_redirect', [$this, 'serve'], 0);
        add_action('init', static function (): void {
            if (get_option('taka_gallery_flush_rewrite')) {
                flush_rewrite_rules(false);
                delete_option('taka_gallery_flush_rewrite');
            }
        }, 99);
    }

    public function serve(): void
    {
        $public_id = (string) get_query_var('taka_media_id');
        if ($public_id === '') {
            return;
        }
        $width = (int) get_query_var('taka_media_width');
        $expires = isset($_GET['exp']) ? (int) $_GET['exp'] : 0;
        $signature = isset($_GET['sig']) ? sanitize_key(wp_unslash($_GET['sig'])) : '';
        $session_id = $this->media_session->current();
        if ($session_id === null || !$this->valid_request_context() || !$this->signer->verify($public_id, $width, $expires, $signature, $session_id)) {
            $this->fail(403, 'Expired or invalid media URL.');
        }

        $root = realpath((string) $this->database->settings()['derivatives_path']);
        if ($root === false) {
            $this->fail(503, 'Media storage is unavailable.');
        }
        $path = $root . DIRECTORY_SEPARATOR . $public_id . DIRECTORY_SEPARATOR . $width . '.webp';
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || !is_readable($real)) {
            $this->fail(404, 'Derivative not found.');
        }

        status_header(200);
        header('Content-Type: image/webp');
        header('Content-Length: ' . filesize($real));
        header('Content-Disposition: inline; filename="' . $public_id . '-' . $width . '.webp"');
        header('Cache-Control: private, max-age=' . max(0, min(600, $expires - time())));
        header('Vary: Cookie, Sec-Fetch-Dest, Sec-Fetch-Site, Referer');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Accept-Ranges: none');
        header('X-Sendfile: ' . $real);
        exit;
    }

    private function valid_request_context(): bool
    {
        $destination = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '')));
        $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($destination !== '') {
            return $destination === 'image' && ($site === '' || in_array($site, ['same-origin', 'same-site'], true));
        }

        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $expected = wp_parse_url(home_url('/'));
        $actual = wp_parse_url($referer);
        if (!is_array($expected) || !is_array($actual)) {
            return false;
        }

        return strtolower((string) ($expected['scheme'] ?? '')) === strtolower((string) ($actual['scheme'] ?? ''))
            && strtolower((string) ($expected['host'] ?? '')) === strtolower((string) ($actual['host'] ?? ''))
            && (int) ($expected['port'] ?? 0) === (int) ($actual['port'] ?? 0);
    }

    private function fail(int $status, string $message): never
    {
        status_header($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo esc_html($message);
        exit;
    }
}
