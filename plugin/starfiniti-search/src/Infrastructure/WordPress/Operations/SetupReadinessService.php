<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Operations;

use Starfiniti\Search\Domain\Provider\SearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Storefront\IntegrationRegistry;
use wpdb;

final class SetupReadinessService
{
    public function __construct(
        private readonly wpdb $db,
        private readonly SearchProvider $provider,
        private readonly ConfigurationRepository $configuration,
        private readonly IntegrationRegistry $integrations
    ) {
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $active = (int) get_option('starfiniti_search_active_generation');
        $configuration = $this->configuration->current()?->toArray();
        $eligible = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending')");
        $variations = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type='product_variation' AND post_status IN ('publish','private','draft','pending')");
        $documents = $active > 0 ? (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d", $active)) : 0;
        $public = $active > 0 ? (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d AND searchable=1", $active)) : 0;
        $restricted = max(0, $documents - $public);
        $generation = $active > 0 ? $this->db->get_row($this->db->prepare("SELECT state,schema_version,analyzer_revision,ranking_profile,document_count FROM {$this->db->prefix}sfs_index_generations WHERE generation_id=%d", $active), ARRAY_A) : null;
        $failed = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}sfs_sync_outbox WHERE status='failed'");
        $quarantined = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->db->prefix}sfs_quarantine");
        $charset = strtolower((string) $this->db->get_var('SELECT @@character_set_database'));
        $tablePattern = $this->db->esc_like($this->db->prefix . 'sfs_') . '%';
        $storageBytes = (int) $this->db->get_var($this->db->prepare('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE %s', $tablePattern));
        $detected = $this->integrations->detected();
        $placements = $this->placementCount();
        $capabilities = $this->provider->capabilities();
        $activePlugins = array_map(static fn (string $plugin): string => dirname($plugin), (array) get_option('active_plugins', []));
        $pricingPlugins = array_values(array_intersect($activePlugins, ['b2bking', 'woocommerce-currency-switcher', 'woocommerce-multilingual', 'wholesale-suite', 'role-based-pricing-for-woocommerce']));
        $catalog = is_array($configuration['catalog'] ?? null) ? $configuration['catalog'] : [];
        $transport = is_array($configuration['transport_policy'] ?? null) ? $configuration['transport_policy'] : [];
        $operations = is_array($configuration['operations'] ?? null) ? $configuration['operations'] : [];
        $providerCertified = $this->provider->id() === 'local' || (($capabilities['capabilities']['service_certification']['state'] ?? 'unsupported') === 'native');
        $unsafeDirectPricing = $pricingPlugins !== [] && ($transport['mode'] ?? '') === 'typesense_direct_public';
        $visibilitySafe = $restricted === 0 || in_array($catalog['visibility_policy'] ?? '', ['scope_tokens', 'server_revalidate', 'separate_indexes'], true);
        $generationReady = is_array($generation) && $generation['state'] === 'active' && (int) $generation['document_count'] === $eligible && $documents === $eligible && $failed === 0 && $quarantined === 0;
        $integrationQualified = array_filter($detected, static fn (array $entry): bool => ($entry['status'] ?? '') === 'automated_qualification') !== [];
        $smoke = $this->smoke($active);

        $steps = [
            $this->step('environment', 'Environment and compatibility preflight', version_compare(PHP_VERSION, '8.2.0', '>=') && defined('WC_VERSION') && version_compare(WC_VERSION, '9.0', '>=') && version_compare(get_bloginfo('version'), '6.7', '>=') && $charset === 'utf8mb4' ? 'pass' : 'blocked', ['php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'woocommerce' => defined('WC_VERSION') ? WC_VERSION : null, 'database_charset' => $charset, 'required_php' => '8.2', 'required_wordpress' => '6.7', 'required_woocommerce' => '9.0'], 'Upgrade unsupported components or correct the database character set.'),
            $this->step('catalog_analysis', 'Catalog analysis', $eligible > 0 ? 'pass' : 'attention', ['eligible_entities' => $eligible, 'variations' => $variations, 'active_documents' => $documents, 'public_documents' => $public, 'restricted_documents' => $restricted], 'Add eligible WooCommerce products before building the initial generation.'),
            $this->step('catalog_policy', 'Public and restricted catalog detection', $visibilitySafe ? 'pass' : 'blocked', ['restricted_documents' => $restricted, 'visibility_policy' => $catalog['visibility_policy'] ?? null], 'Select a server-enforced scope or revalidation policy before activation.'),
            $this->step('provider', 'Provider selection and certification', $providerCertified ? 'pass' : 'blocked', ['active_provider' => $this->provider->id(), 'certified' => $providerCertified], 'Use the certified local provider or complete real-service provider certification.'),
            $this->step('storage', 'Local storage estimate or external connection', $this->provider->id() === 'local' && $storageBytes > 0 ? 'pass' : ($providerCertified ? 'attention' : 'blocked'), ['current_index_bytes' => $storageBytes, 'estimated_catalog_bytes' => $eligible * 16384], 'Verify storage capacity or the external provider schema and credentials.'),
            $this->step('language_variations', 'Language and variation strategy', ($catalog['locales'] ?? []) !== [] && isset($catalog['variation_strategy']) ? 'pass' : 'blocked', ['locales' => $catalog['locales'] ?? [], 'variation_strategy' => $catalog['variation_strategy'] ?? null, 'variation_count' => $variations], 'Choose at least one locale and an explicit variation strategy.'),
            $this->step('fields_identifiers', 'Searchable fields and identifiers', (int) ($generation['schema_version'] ?? 0) >= 3 && $public > 0 ? 'pass' : 'attention', ['index_schema' => (int) ($generation['schema_version'] ?? 0), 'analyzer_revision' => (int) ($generation['analyzer_revision'] ?? 0), 'public_documents' => $public], 'Build and activate a schema-v3 generation with public catalog documents.'),
            $this->step('price_stock_visibility', 'Price, stock, and visibility policy', $unsafeDirectPricing || !$visibilitySafe ? 'blocked' : 'pass', ['price_policy' => $catalog['price_policy'] ?? null, 'visibility_policy' => $catalog['visibility_policy'] ?? null, 'pricing_plugins' => $pricingPlugins, 'transport_mode' => $transport['mode'] ?? null], 'Disable direct-browser projection or choose a safe server-hydrated price policy.'),
            $this->step('index_plan', 'Initial index plan', is_array($configuration) && ($configuration['write_targets'] ?? []) !== [] && (int) ($operations['batch_size'] ?? 0) > 0 ? 'pass' : 'blocked', ['configuration_revision' => $configuration['revision'] ?? null, 'write_targets' => $configuration['write_targets'] ?? [], 'batch_size' => (int) ($operations['batch_size'] ?? 0)], 'Create an immutable configuration and bounded initial build plan.'),
            $this->step('build_verification', 'Build and verification', $generationReady ? 'pass' : 'blocked', ['active_generation' => $active, 'generation_state' => $generation['state'] ?? null, 'expected_documents' => $eligible, 'indexed_documents' => $documents, 'failed_outbox' => $failed, 'quarantined' => $quarantined], 'Build a shadow generation and resolve count, queue, or quarantine failures before activation.'),
            $this->step('storefront_placement', 'Storefront placement', $placements > 0 && $integrationQualified ? 'pass' : 'attention', ['detected_integrations' => array_values(array_map(static fn (array $entry): string => (string) $entry['integration_id'], $detected)), 'qualified_integration' => $integrationQualified, 'published_placements' => $placements], 'Place the shared block, shortcode, widget, or template API in a qualified storefront surface.'),
            $this->step('smoke_activation', 'Smoke test and activation', $smoke['passed'] ? 'pass' : 'blocked', $smoke, 'Resolve the exact identifier/title smoke failure before declaring setup ready.'),
        ];
        $statuses = array_column($steps, 'status');
        $status = in_array('blocked', $statuses, true) ? 'blocked' : (in_array('attention', $statuses, true) ? 'attention' : 'ready');

        return ['contract_version' => '1.0', 'status' => $status, 'release_certified' => false, 'steps' => $steps];
    }

    /** @return array<string,mixed> */
    private function smoke(int $generation): array
    {
        if ($generation < 1) {
            return ['passed' => false, 'reason' => 'no_active_generation'];
        }
        $row = $this->db->get_row($this->db->prepare("SELECT entity_id,sku,title FROM {$this->db->prefix}sfs_documents WHERE generation_id=%d AND searchable=1 ORDER BY entity_id ASC LIMIT 1", $generation), ARRAY_A);
        if (!is_array($row)) {
            return ['passed' => false, 'reason' => 'no_public_smoke_document'];
        }
        $query = trim((string) ($row['sku'] ?? '')) ?: trim((string) ($row['title'] ?? ''));
        if ($query === '') {
            return ['passed' => false, 'reason' => 'no_safe_smoke_query'];
        }
        try {
            $result = $this->provider->search(['query' => $query, 'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']], 'filters' => null, 'facets' => [], 'sort' => [], 'page' => ['number' => 1, 'size' => 10], 'options' => ['suggestion_mode' => 'admin_test', 'highlight' => false, 'include_explanation' => false]]);
            $matched = array_filter($result['hits'] ?? [], static fn (array $hit): bool => (int) ($hit['entity_id'] ?? 0) === (int) $row['entity_id']) !== [];
            return ['passed' => $matched, 'entity_id' => (int) $row['entity_id'], 'query_type' => trim((string) ($row['sku'] ?? '')) !== '' ? 'sku' : 'title', 'provider' => $this->provider->id(), 'total' => (int) ($result['total'] ?? 0)];
        } catch (\Throwable) {
            return ['passed' => false, 'reason' => 'provider_smoke_failed', 'provider' => $this->provider->id()];
        }
    }

    private function placementCount(): int
    {
        $patterns = ['%[starfiniti_search%', '%[starfiniti_discovery%', '%wp:starfiniti/search%', '%wp:starfiniti/discovery%', '%wp:starfiniti/navigation-search%'];
        $conditions = implode(' OR ', array_fill(0, count($patterns), 'post_content LIKE %s'));
        return (int) $this->db->get_var($this->db->prepare("SELECT COUNT(*) FROM {$this->db->posts} WHERE post_status='publish' AND post_type IN ('page','wp_template','wp_template_part') AND ({$conditions})", ...$patterns));
    }

    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    private function step(string $id, string $label, string $status, array $evidence, string $nextAction): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'evidence' => $evidence, 'next_action' => $status === 'pass' ? null : $nextAction];
    }
}
