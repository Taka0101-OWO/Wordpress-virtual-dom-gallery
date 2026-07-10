<?php

namespace Taka\VirtualGallery;

final class Signer
{
    public const WIDTHS = [480, 960, 1440];

    public function sign(string $public_id, int $width, string $session_id, ?int $expires = null): array
    {
        if (!in_array($width, self::WIDTHS, true) || !preg_match('/^[a-f0-9]{32}$/', $session_id)) {
            throw new \InvalidArgumentException('Unsupported media signature parameters.');
        }

        $settings = wp_parse_args(get_option('taka_gallery_settings', []), ['url_ttl' => 600]);
        $expires ??= time() + max(60, min(3600, (int) $settings['url_ttl']));
        $signature = hash_hmac('sha256', $this->payload($public_id, $width, $expires, $session_id), $this->key());

        return [
            'url' => add_query_arg(
                ['exp' => $expires, 'sig' => $signature],
                home_url('/taka-media/v1/' . rawurlencode($public_id) . '/' . $width . '.webp')
            ),
            'expiresAt' => $expires,
        ];
    }

    public function verify(string $public_id, int $width, int $expires, string $signature, string $session_id): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $public_id) || !in_array($width, self::WIDTHS, true) || !preg_match('/^[a-f0-9]{32}$/', $session_id)) {
            return false;
        }
        if ($expires < time() || $expires > time() + 3660 || !preg_match('/^[a-f0-9]{64}$/', $signature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $this->payload($public_id, $width, $expires, $session_id), $this->key());
        return hash_equals($expected, $signature);
    }

    private function payload(string $public_id, int $width, int $expires, string $session_id): string
    {
        return $public_id . '|' . $width . '|' . $expires . '|' . $session_id;
    }

    private function key(): string
    {
        return wp_salt('auth') . '|taka-gallery-media-v1';
    }
}
