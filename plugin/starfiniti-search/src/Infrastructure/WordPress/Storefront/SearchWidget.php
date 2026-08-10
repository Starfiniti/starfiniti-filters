<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Storefront;

final class SearchWidget extends \WP_Widget
{
    public function __construct()
    {
        parent::__construct('starfiniti_search_widget', __('Starfiniti Search', 'starfiniti-search'), [
            'description' => __('Accessible product search or faceted discovery using the shared Starfiniti component.', 'starfiniti-search'),
        ]);
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $instance */
    public function widget($args, $instance): void
    {
        $mode = ($instance['mode'] ?? 'search') === 'discovery' ? 'discovery' : 'search';
        $title = mb_substr(sanitize_text_field((string) ($instance['title'] ?? '')), 0, 120);
        echo $args['before_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned wrapper.
        if ($title !== '') {
            echo ($args['before_title'] ?? '') . esc_html($title) . ($args['after_title'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme wrappers; title escaped.
        }
        $renderer = new SearchShortcode();
        echo $mode === 'discovery' ? $renderer->renderDiscovery(['title' => $title ?: __('Product discovery', 'starfiniti-search')]) : $renderer->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes values.
        echo $args['after_widget'] ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Theme-owned wrapper.
    }

    /** @param array<string,mixed> $newInstance @param array<string,mixed> $oldInstance @return array<string,string> */
    public function update($newInstance, $oldInstance): array
    {
        return [
            'title' => mb_substr(sanitize_text_field((string) ($newInstance['title'] ?? '')), 0, 120),
            'mode' => ($newInstance['mode'] ?? 'search') === 'discovery' ? 'discovery' : 'search',
        ];
    }

    /** @param array<string,mixed> $instance */
    public function form($instance): void
    {
        $title = (string) ($instance['title'] ?? '');
        $mode = ($instance['mode'] ?? 'search') === 'discovery' ? 'discovery' : 'search';
        ?>
        <p><label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php echo esc_html__('Title', 'starfiniti-search'); ?></label><input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" maxlength="120" value="<?php echo esc_attr($title); ?>"></p>
        <p><label for="<?php echo esc_attr($this->get_field_id('mode')); ?>"><?php echo esc_html__('Surface', 'starfiniti-search'); ?></label><select class="widefat" id="<?php echo esc_attr($this->get_field_id('mode')); ?>" name="<?php echo esc_attr($this->get_field_name('mode')); ?>"><option value="search" <?php selected($mode, 'search'); ?>><?php echo esc_html__('Autocomplete search', 'starfiniti-search'); ?></option><option value="discovery" <?php selected($mode, 'discovery'); ?>><?php echo esc_html__('Faceted discovery', 'starfiniti-search'); ?></option></select></p>
        <?php
    }
}
