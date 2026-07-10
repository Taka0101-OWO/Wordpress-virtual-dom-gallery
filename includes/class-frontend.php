<?php

namespace Taka\VirtualGallery;

final class Frontend
{
    private static bool $config_printed = false;

    public function __construct(private Database $database)
    {
    }

    public function register(): void
    {
        add_shortcode('taka_gallery', [$this, 'shortcode']);
        add_action('wp_head', static function (): void {
            echo '<meta name="referrer" content="same-origin">';
        }, 1);
    }

    public function shortcode(array $attributes = []): string
    {
        $attributes = shortcode_atts([
            'gallery' => '',
            'navigation' => 'yes',
            'mobile_columns' => '2',
            'tablet_columns' => '3',
            'desktop_columns' => '3',
            'gap' => '2',
        ], $attributes, 'taka_gallery');
        return self::render_mount($attributes);
    }

    public static function render_mount(array $settings): string
    {
        Assets::enqueue_style();
        Assets::enqueue_module('frontend');
        self::print_config();
        $data = [
            'gallery' => sanitize_title((string) ($settings['gallery'] ?? '')),
            'navigation' => ($settings['navigation'] ?? 'yes') === 'yes',
            'mobileColumns' => self::bounded($settings['mobile_columns'] ?? 2, 1, 4),
            'tabletColumns' => self::bounded($settings['tablet_columns'] ?? 3, 1, 6),
            'desktopColumns' => self::bounded($settings['desktop_columns'] ?? 3, 1, 8),
            'gap' => self::bounded($settings['gap'] ?? 2, 0, 40),
        ];
        return '<div class="taka-gallery-root" data-config="' . esc_attr(wp_json_encode($data)) . '"></div>';
    }

    private static function print_config(): void
    {
        if (self::$config_printed) {
            return;
        }
        self::$config_printed = true;
        $config = ['restUrl' => esc_url_raw(rest_url('taka-gallery/v1/')), 'version' => TAKA_GALLERY_VERSION];
        add_action('wp_footer', static function () use ($config): void {
            echo '<script>window.TakaGalleryFrontend=window.TakaGalleryFrontend||' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
        }, 1);
    }

    private static function bounded(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
