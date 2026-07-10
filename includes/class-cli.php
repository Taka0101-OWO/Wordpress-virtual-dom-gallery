<?php

namespace Taka\VirtualGallery;

final class Cli
{
    public function __construct(private Scanner $scanner, private ImageProcessor $processor)
    {
    }

    public function register(): void
    {
        \WP_CLI::add_command('taka-gallery scan', function (): void {
            \WP_CLI::log(wp_json_encode($this->scanner->scan_all(), JSON_PRETTY_PRINT));
        });
        \WP_CLI::add_command('taka-gallery process', function (): void {
            \WP_CLI::log(wp_json_encode($this->processor->process_queue(), JSON_PRETTY_PRINT));
        });
    }
}
