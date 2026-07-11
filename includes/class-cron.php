<?php

namespace Taka\VirtualGallery;

final class Cron
{
    public const SCAN_HOOK = 'taka_gallery_scan_folders';
    public const PROCESS_HOOK = 'taka_gallery_process_assets';

    public function __construct(private ScanJob $scan_job, private ImageProcessor $processor)
    {
    }

    public function register(): void
    {
        add_filter('cron_schedules', static function (array $schedules): array {
            $schedules['taka_gallery_minute'] = ['interval' => 60, 'display' => 'Every minute'];
            return $schedules;
        });
        add_action(self::SCAN_HOOK, fn (): array => $this->scan_job->start(true));
        add_action(self::PROCESS_HOOK, [$this->processor, 'process_queue']);

        add_action('init', static function (): void {
            if (!wp_next_scheduled(self::SCAN_HOOK)) {
                wp_schedule_event(time() + 30, 'taka_gallery_minute', self::SCAN_HOOK);
            }
            if (!wp_next_scheduled(self::PROCESS_HOOK)) {
                wp_schedule_event(time() + 45, 'taka_gallery_minute', self::PROCESS_HOOK);
            }
        });
    }
}
