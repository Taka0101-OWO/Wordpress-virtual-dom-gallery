<?php

namespace Taka\VirtualGallery;

final class MediaSession
{
    public const COOKIE_NAME = 'taka_gallery_media';

    public function establish(\WP_REST_Request $request): ?string
    {
        $session_id = strtolower((string) $request->get_header('x-taka-session'));
        if (!preg_match('/^[a-f0-9]{32}$/', $session_id)) {
            return null;
        }

        $value = $session_id . '.' . $this->mac($session_id);
        if (!hash_equals((string) ($_COOKIE[self::COOKIE_NAME] ?? ''), $value)) {
            setcookie(self::COOKIE_NAME, $value, [
                'expires' => 0,
                'path' => '/',
                'secure' => is_ssl(),
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            $_COOKIE[self::COOKIE_NAME] = $value;
        }

        return $session_id;
    }

    public function current(): ?string
    {
        $value = strtolower((string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        if (!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $value, $matches)) {
            return null;
        }

        return hash_equals($this->mac($matches[1]), $matches[2]) ? $matches[1] : null;
    }

    private function mac(string $session_id): string
    {
        return hash_hmac('sha256', $session_id, wp_salt('auth') . '|taka-gallery-session-v1');
    }
}
