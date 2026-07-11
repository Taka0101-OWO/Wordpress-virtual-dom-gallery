<?php
/**
 * Plugin Name: Taka Virtual Gallery
 * Description: Private NAS-backed galleries with an Elementor widget and a virtualized masonry frontend.
 * Version: 0.1.7
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Taka
 * Text Domain: taka-virtual-gallery
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TAKA_GALLERY_VERSION', '0.1.7');
define('TAKA_GALLERY_FILE', __FILE__);
define('TAKA_GALLERY_DIR', plugin_dir_path(__FILE__));
define('TAKA_GALLERY_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'Taka\\VirtualGallery\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = TAKA_GALLERY_DIR . 'includes/class-' . strtolower(str_replace(['\\', '_'], ['-', '-'], $relative)) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, ['Taka\\VirtualGallery\\Database', 'activate']);
register_deactivation_hook(__FILE__, ['Taka\\VirtualGallery\\Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Taka\VirtualGallery\Plugin::instance()->boot();
});
