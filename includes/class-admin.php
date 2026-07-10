<?php

namespace Taka\VirtualGallery;

final class Admin
{
    public function __construct(private Database $database)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_menu_page(
                __('Taka Gallery', 'taka-virtual-gallery'),
                __('Taka Gallery', 'taka-virtual-gallery'),
                'manage_taka_galleries',
                'taka-gallery',
                [$this, 'render'],
                'dashicons-format-gallery',
                25
            );
        });
        add_action('admin_enqueue_scripts', function (string $hook): void {
            if ($hook !== 'toplevel_page_taka-gallery') {
                return;
            }
            Assets::enqueue_style();
            Assets::enqueue_module('admin');
        });
        add_action('admin_notices', static function (): void {
            if (current_user_can('manage_taka_galleries') && !Assets::built()) {
                echo '<div class="notice notice-error"><p>' . esc_html__('Taka Virtual Gallery frontend assets are missing. Install the packaged release or run the asset build.', 'taka-virtual-gallery') . '</p></div>';
            }
        });
    }

    public function render(): void
    {
        if (!current_user_can('manage_taka_galleries')) {
            wp_die(esc_html__('You do not have permission to manage galleries.', 'taka-virtual-gallery'));
        }
        $config = [
            'restUrl' => esc_url_raw(rest_url('taka-gallery/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => TAKA_GALLERY_VERSION,
        ];
        echo '<div class="wrap taka-admin-shell"><div id="taka-gallery-admin"></div></div>';
        echo '<script>window.TakaGalleryAdmin=' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
    }
}
