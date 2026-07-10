<?php

namespace Taka\VirtualGallery;

final class Assets
{
    private static bool $style_enqueued = false;

    public static function enqueue_style(): void
    {
        if (self::$style_enqueued) {
            return;
        }
        $path = TAKA_GALLERY_DIR . 'assets/dist/style.css';
        if (is_readable($path)) {
            wp_enqueue_style('taka-virtual-gallery', TAKA_GALLERY_URL . 'assets/dist/style.css', [], TAKA_GALLERY_VERSION);
            self::$style_enqueued = true;
        }
    }

    public static function enqueue_module(string $entry): void
    {
        $path = TAKA_GALLERY_DIR . 'assets/dist/' . $entry . '.js';
        if (!is_readable($path)) {
            return;
        }
        $handle = 'taka-gallery-' . $entry;
        if (function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module($handle, TAKA_GALLERY_URL . 'assets/dist/' . $entry . '.js', [], TAKA_GALLERY_VERSION);
            return;
        }
        wp_enqueue_script($handle, TAKA_GALLERY_URL . 'assets/dist/' . $entry . '.js', [], TAKA_GALLERY_VERSION, true);
        add_filter('script_loader_tag', static function (string $tag, string $script_handle) use ($handle): string {
            return $script_handle === $handle ? str_replace('<script ', '<script type="module" ', $tag) : $tag;
        }, 10, 2);
    }

    public static function built(): bool
    {
        return is_readable(TAKA_GALLERY_DIR . 'assets/dist/admin.js')
            && is_readable(TAKA_GALLERY_DIR . 'assets/dist/frontend.js')
            && is_readable(TAKA_GALLERY_DIR . 'assets/dist/style.css');
    }
}
