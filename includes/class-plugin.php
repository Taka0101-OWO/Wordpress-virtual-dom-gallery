<?php

namespace Taka\VirtualGallery;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        load_plugin_textdomain('taka-virtual-gallery', false, dirname(plugin_basename(TAKA_GALLERY_FILE)) . '/languages');

        $database = new Database();
        $database->maybe_upgrade();
        $signer = new Signer();
        $media_session = new MediaSession();
        $processor = new ImageProcessor($database);
        $scanner = new Scanner($database);
        $scan_job = new ScanJob($scanner);
        $scan_job->register();

        (new RestApi($database, $scan_job, $processor, $signer, $media_session))->register();
        (new MediaEndpoint($database, $signer, $media_session))->register();
        (new Admin($database))->register();
        (new Frontend($database))->register();
        (new ElementorIntegration())->register();
        (new Cron($scan_job, $processor))->register();

        if (defined('WP_CLI') && WP_CLI) {
            (new Cli($scanner, $processor))->register();
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Cron::SCAN_HOOK);
        wp_clear_scheduled_hook(Cron::PROCESS_HOOK);
        flush_rewrite_rules();
    }
}
