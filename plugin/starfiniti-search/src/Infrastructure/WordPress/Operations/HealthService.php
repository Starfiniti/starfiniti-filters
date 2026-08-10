<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Operations;

use Starfiniti\Search\Domain\Provider\SearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\Migration\LegacyFiboFiltersReader;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\IntegrationRegistry;
use wpdb;

final class HealthService
{
    public function __construct(
        private readonly wpdb $db,
        private readonly SearchProvider $provider,
        private readonly GenerationManager $generations,
        private readonly LegacyFiboFiltersReader $legacy,
        private readonly ConfigurationRepository $configuration,
        private readonly AnalyticsRepository $analytics,
        private readonly ?IntegrationRegistry $integrations = null,
        private readonly ?SetupReadinessService $setup = null
    ) {
    }

    /** @return array<string, mixed> */
    public function report(bool $deep = false): array
    {
        $active = (int) get_option('starfiniti_search_active_generation');
        $documents = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d", $active));
        $pending = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE status IN ('pending','processing')");
        $failed = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE status='failed'");
        $quarantined = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}sfs_quarantine");
        $oldestPending = $this->db->get_var("SELECT MIN(created_at) FROM {$this->db->prefix}sfs_sync_outbox WHERE status IN ('pending','processing')");
        $dead = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE status='dead'");
        $ready = $active > 0 && $failed === 0;

        $report = [
            'contract_version' => '1.0',
            'correlation_id' => wp_generate_uuid4(),
            'liveness' => ['status' => 'live'],
            'readiness' => [
                'status' => $ready ? ($pending > 0 ? 'degraded' : 'ready') : 'unavailable',
                'active_generation' => $active,
                'documents' => $documents,
                'outbox_pending' => $pending,
                'outbox_failed' => $failed,
                'outbox_dead' => $dead,
                'oldest_pending_age_seconds' => is_string($oldestPending) ? max(0, time() - (int) strtotime($oldestPending)) : 0,
                'quarantined' => $quarantined,
            ],
            'provider' => [
                'id' => $this->provider->id(),
                'capabilities' => $this->provider->capabilities(),
            ],
            'plugin_version' => STARFINITI_SEARCH_VERSION,
            'schema_version' => (int) get_option('starfiniti_search_schema_version'),
            'reconciliation' => get_option('starfiniti_search_reconciliation_status', null),
        ];
        if ($deep) {
            $report['generations'] = $this->generations->list();
            $report['environment'] = [
                'wordpress' => get_bloginfo('version'),
                'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null,
                'php' => PHP_VERSION,
                'multisite' => is_multisite(),
            ];
            $report['legacy_migration_source'] = $this->legacy->report();
            $currentConfiguration = $this->configuration->current();
            $report['configuration'] = $currentConfiguration !== null ? [
                'revision' => $currentConfiguration->revision(),
                'checksum' => $currentConfiguration->checksum(),
                'active_read_provider' => $currentConfiguration->toArray()['active_read_provider'],
                'write_targets' => $currentConfiguration->toArray()['write_targets'],
            ] : null;
            $report['configuration_history'] = $this->configuration->history(10);
            if ($this->integrations !== null) {
                $report['storefront_integrations'] = [
                    'detected' => $this->integrations->detected(),
                    'registry' => $this->integrations->entries(),
                ];
            }
            if ($this->setup !== null) {
                $report['setup'] = $this->setup->report();
            }
        }
        return $report;
    }

    /** @return array<string,mixed> */
    public function analyticsReport(int $days = 30): array
    {
        return $this->analytics->report($days);
    }
}
