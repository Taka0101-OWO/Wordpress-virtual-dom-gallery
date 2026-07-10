<?php

namespace Taka\VirtualGallery;

final class ElementorIntegration
{
    public function register(): void
    {
        add_action('elementor/widgets/register', static function ($widgets_manager): void {
            if (class_exists('Elementor\\Widget_Base')) {
                $widgets_manager->register(new ElementorWidget());
            }
        });
        add_action('elementor/elements/categories_registered', static function ($elements_manager): void {
            $elements_manager->add_category('taka-gallery', [
                'title' => __('Taka Gallery', 'taka-virtual-gallery'),
                'icon' => 'fa fa-plug',
            ]);
        });
    }
}
