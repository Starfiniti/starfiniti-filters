<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Analytics;

final class PrivacyHooks
{
    public function __construct(private readonly AnalyticsRepository $analytics)
    {
    }

    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'erasers']);
        add_action('starfiniti_search_purge_analytics', [$this->analytics, 'purgeExpired']);
        if (!wp_next_scheduled('starfiniti_search_purge_analytics')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'starfiniti_search_purge_analytics');
        }
    }

    public function exporters(array $exporters): array
    {
        $exporters['starfiniti-search'] = [
            'exporter_friendly_name' => __('Starfiniti Search aggregate analytics', 'starfiniti-search'),
            'callback' => static fn (string $email, int $page = 1): array => ['data' => [], 'done' => true],
        ];
        return $exporters;
    }

    public function erasers(array $erasers): array
    {
        $erasers['starfiniti-search'] = [
            'eraser_friendly_name' => __('Starfiniti Search aggregate analytics', 'starfiniti-search'),
            'callback' => static fn (string $email, int $page = 1): array => [
                'items_removed' => false,
                'items_retained' => false,
                'messages' => [__('Starfiniti Search stores no user-linked analytics.', 'starfiniti-search')],
                'done' => true,
            ],
        ];
        return $erasers;
    }
}
