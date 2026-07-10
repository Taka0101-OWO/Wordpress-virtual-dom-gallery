<?php

namespace Taka\VirtualGallery;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if (!class_exists(Widget_Base::class)) {
    return;
}

final class ElementorWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'taka_virtual_gallery';
    }

    public function get_title(): string
    {
        return __('Taka Virtual Gallery', 'taka-virtual-gallery');
    }

    public function get_icon(): string
    {
        return 'eicon-gallery-grid';
    }

    public function get_categories(): array
    {
        return ['taka-gallery'];
    }

    protected function register_controls(): void
    {
        $database = new Database();
        $options = ['' => __('All published galleries', 'taka-virtual-gallery')];
        foreach ($database->get_galleries(true) as $gallery) {
            $options[$gallery['slug']] = $gallery['name'];
        }

        $this->start_controls_section('content', ['label' => __('Gallery', 'taka-virtual-gallery')]);
        $this->add_control('gallery', [
            'label' => __('Initial gallery', 'taka-virtual-gallery'), 'type' => Controls_Manager::SELECT,
            'options' => $options, 'default' => '',
        ]);
        $this->add_control('navigation', [
            'label' => __('Show gallery navigation', 'taka-virtual-gallery'), 'type' => Controls_Manager::SWITCHER,
            'label_on' => __('Yes', 'taka-virtual-gallery'), 'label_off' => __('No', 'taka-virtual-gallery'), 'return_value' => 'yes', 'default' => 'yes',
        ]);
        foreach ([
            'mobile_columns' => [__('Mobile columns', 'taka-virtual-gallery'), 2, 1, 4],
            'tablet_columns' => [__('Tablet columns', 'taka-virtual-gallery'), 3, 1, 6],
            'desktop_columns' => [__('Desktop columns', 'taka-virtual-gallery'), 3, 1, 8],
            'gap' => [__('Gap (px)', 'taka-virtual-gallery'), 2, 0, 40],
        ] as $name => [$label, $default, $min, $max]) {
            $this->add_control($name, [
                'label' => $label, 'type' => Controls_Manager::NUMBER, 'default' => $default, 'min' => $min, 'max' => $max,
            ]);
        }
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $editing = class_exists('Elementor\\Plugin') && \Elementor\Plugin::$instance->editor->is_edit_mode();
        if ($editing) {
            $name = $settings['gallery'] ?: __('All published galleries', 'taka-virtual-gallery');
            echo '<div class="taka-elementor-placeholder"><strong>' . esc_html__('Taka Virtual Gallery', 'taka-virtual-gallery') . '</strong><span>' . esc_html($name) . '</span></div>';
            return;
        }
        echo Frontend::render_mount($settings);
    }
}
