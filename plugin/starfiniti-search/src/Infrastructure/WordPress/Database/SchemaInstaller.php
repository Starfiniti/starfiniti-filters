<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Database;

use Starfiniti\Search\Domain\Search\RankingProfile;

final class SchemaInstaller
{
    public const SCHEMA_VERSION = 10;

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix;
        $sql = [];

        $sql[] = "CREATE TABLE {$prefix}sfs_sync_outbox (
            event_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            aggregate_type varchar(32) NOT NULL,
            aggregate_id bigint(20) unsigned NOT NULL,
            target_generation bigint(20) unsigned NOT NULL,
            locale varchar(32) NOT NULL DEFAULT '',
            operation varchar(16) NOT NULL,
            priority smallint(5) unsigned NOT NULL DEFAULT 100,
            status varchar(16) NOT NULL DEFAULT 'pending',
            dedupe_key char(64) NOT NULL,
            attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            available_at datetime(6) NOT NULL,
            leased_until datetime(6) DEFAULT NULL,
            lease_token char(36) DEFAULT NULL,
            last_error_code varchar(64) DEFAULT NULL,
            created_at datetime(6) NOT NULL,
            updated_at datetime(6) NOT NULL,
            PRIMARY KEY  (event_id),
            UNIQUE KEY dedupe_key (dedupe_key),
            KEY dequeue (status,available_at,priority,event_id),
            KEY aggregate (aggregate_type,aggregate_id,locale),
            KEY target_generation (target_generation,status)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_index_generations (
            generation_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL,
            state varchar(16) NOT NULL,
            schema_version int(10) unsigned NOT NULL,
            schema_hash char(64) NOT NULL,
            analyzer_revision int(10) unsigned NOT NULL DEFAULT 0,
            ranking_profile varchar(64) NOT NULL DEFAULT '',
            document_count bigint(20) unsigned NOT NULL DEFAULT 0,
            build_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
            build_attempts int(10) unsigned NOT NULL DEFAULT 0,
            build_lease_token char(36) DEFAULT NULL,
            build_leased_until datetime(6) DEFAULT NULL,
            build_updated_at datetime(6) DEFAULT NULL,
            created_at datetime(6) NOT NULL,
            activated_at datetime(6) DEFAULT NULL,
            PRIMARY KEY  (generation_id),
            KEY provider_state (provider,state)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_documents (
            generation_id bigint(20) unsigned NOT NULL,
            document_id varchar(191) NOT NULL,
            entity_type varchar(32) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned DEFAULT NULL,
            locale varchar(32) NOT NULL,
            channel varchar(64) NOT NULL,
            searchable tinyint(1) unsigned NOT NULL,
            catalog_visible tinyint(1) unsigned NOT NULL,
            password_protected tinyint(1) unsigned NOT NULL,
            scope_hash char(64) NOT NULL,
            sku varchar(191) DEFAULT NULL,
            sku_normalized varchar(191) DEFAULT NULL,
            title text NOT NULL,
            title_normalized varchar(512) NOT NULL DEFAULT '',
            search_text longtext NOT NULL,
            price_minor bigint(20) unsigned DEFAULT NULL,
            checksum char(64) NOT NULL,
            document_json longtext NOT NULL,
            updated_at datetime(6) NOT NULL,
            PRIMARY KEY  (generation_id,document_id),
            KEY entity (generation_id,entity_type,entity_id,locale),
            KEY visible_locale (generation_id,searchable,locale,channel),
            KEY sku (generation_id,sku_normalized)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_terms (
            term_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            generation_id bigint(20) unsigned NOT NULL,
            locale varchar(32) NOT NULL,
            term varchar(191) NOT NULL,
            term_hash binary(32) NOT NULL,
            document_frequency bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (term_id),
            UNIQUE KEY generation_term (generation_id,locale,term_hash),
            KEY prefix_search (generation_id,locale,term)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_postings (
            generation_id bigint(20) unsigned NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            document_id varchar(191) NOT NULL,
            field_code tinyint(3) unsigned NOT NULL,
            term_frequency smallint(5) unsigned NOT NULL,
            weight decimal(12,4) unsigned NOT NULL,
            field_length smallint(5) unsigned NOT NULL DEFAULT 0,
            positions_blob varbinary(2048) NOT NULL DEFAULT '',
            PRIMARY KEY  (generation_id,term_id,document_id,field_code),
            KEY document (generation_id,document_id),
            KEY ranking (generation_id,term_id,weight)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_term_ngrams (
            generation_id bigint(20) unsigned NOT NULL,
            gram varchar(16) NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (generation_id,gram,term_id),
            KEY term (generation_id,term_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_document_terms (
            generation_id bigint(20) unsigned NOT NULL,
            document_id varchar(191) NOT NULL,
            term_id bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (generation_id,document_id,term_id),
            KEY term (generation_id,term_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_facet_values (
            generation_id bigint(20) unsigned NOT NULL,
            document_id varchar(191) NOT NULL,
            facet_key varchar(191) NOT NULL,
            facet_value varchar(191) NOT NULL,
            facet_normalized varchar(191) NOT NULL,
            numeric_value decimal(24,6) DEFAULT NULL,
            PRIMARY KEY  (generation_id,document_id,facet_key,facet_normalized),
            KEY facet_lookup (generation_id,facet_key,facet_normalized,document_id),
            KEY facet_numeric (generation_id,facet_key,numeric_value,document_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_quarantine (
            quarantine_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entity_type varchar(32) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            locale varchar(32) NOT NULL,
            error_code varchar(64) NOT NULL,
            error_path varchar(255) DEFAULT NULL,
            error_message text NOT NULL,
            payload_checksum char(64) DEFAULT NULL,
            first_seen_at datetime(6) NOT NULL,
            last_seen_at datetime(6) NOT NULL,
            occurrences int(10) unsigned NOT NULL DEFAULT 1,
            PRIMARY KEY  (quarantine_id),
            UNIQUE KEY entity_error (entity_type,entity_id,locale,error_code)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_configuration_revisions (
            revision_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            parent_revision_id bigint(20) unsigned DEFAULT NULL,
            contract_version varchar(16) NOT NULL,
            state varchar(16) NOT NULL,
            checksum char(64) NOT NULL,
            configuration_json longtext NOT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            reason varchar(191) NOT NULL,
            created_at datetime(6) NOT NULL,
            activated_at datetime(6) DEFAULT NULL,
            PRIMARY KEY  (revision_id),
            UNIQUE KEY checksum (checksum),
            KEY state_revision (state,revision_id)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_analytics_daily (
            metric_date date NOT NULL,
            provider varchar(32) NOT NULL,
            configuration_revision bigint(20) unsigned NOT NULL,
            index_generation bigint(20) unsigned NOT NULL,
            query_length_bucket varchar(16) NOT NULL,
            result_bucket varchar(16) NOT NULL,
            searches bigint(20) unsigned NOT NULL DEFAULT 0,
            no_result_searches bigint(20) unsigned NOT NULL DEFAULT 0,
            errors bigint(20) unsigned NOT NULL DEFAULT 0,
            latency_sum_ms decimal(20,3) unsigned NOT NULL DEFAULT 0,
            latency_max_ms decimal(14,3) unsigned NOT NULL DEFAULT 0,
            latency_observations bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (metric_date,provider,configuration_revision,index_generation,query_length_bucket,result_bucket)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_operation_plans (
            operation_id char(36) NOT NULL,
            plan_hash char(64) NOT NULL,
            operation_type varchar(128) NOT NULL,
            idempotency_key varchar(191) NOT NULL,
            request_hash char(64) NOT NULL,
            status varchar(16) NOT NULL,
            plan_json longtext NOT NULL,
            result_json longtext DEFAULT NULL,
            actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            approver_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime(6) NOT NULL,
            expires_at datetime(6) NOT NULL,
            approved_at datetime(6) DEFAULT NULL,
            executed_at datetime(6) DEFAULT NULL,
            PRIMARY KEY  (operation_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY status_expiry (status,expires_at),
            KEY operation_type (operation_type,created_at)
        ) {$charset};";

        $sql[] = "CREATE TABLE {$prefix}sfs_operation_audit (
            audit_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            occurred_at datetime(6) NOT NULL,
            caller_id bigint(20) unsigned NOT NULL DEFAULT 0,
            action varchar(64) NOT NULL,
            operation_id char(36) DEFAULT NULL,
            plan_hash char(64) DEFAULT NULL,
            arguments_hash char(64) NOT NULL,
            result_code varchar(64) NOT NULL,
            approval_identity bigint(20) unsigned DEFAULT NULL,
            correlation_id char(36) NOT NULL,
            duration_ms decimal(14,3) unsigned NOT NULL DEFAULT 0,
            safe_summary varchar(191) NOT NULL,
            PRIMARY KEY  (audit_id),
            KEY operation (operation_id,audit_id),
            KEY occurred_at (occurred_at)
        ) {$charset};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        $now = gmdate('Y-m-d H:i:s.u');
        $generationTable = $prefix . 'sfs_index_generations';
        self::ensureGenerationRecoveryColumns($generationTable);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation requires the authoritative live generation row; this plugin-owned control table must not be cached.
        $generation = (int) $wpdb->get_var($wpdb->prepare("SELECT generation_id FROM %i WHERE provider='local' AND state='active' ORDER BY generation_id DESC LIMIT 1", $generationTable));
        if ($generation === 0) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Writes to the plugin-owned generation control table have no WordPress metadata API equivalent.
            $wpdb->insert(
                $generationTable,
                [
                    'provider' => 'local',
                    'state' => 'active',
                    'schema_version' => 3,
                    'schema_hash' => hash('sha256', 'starfiniti-local-schema-v3'),
                    'analyzer_revision' => RankingProfile::ANALYZER_REVISION,
                    'ranking_profile' => RankingProfile::VERSION,
                    'created_at' => $now,
                    'activated_at' => $now,
                ],
                ['%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s']
            );
            $generation = (int) $wpdb->insert_id;
            update_option('starfiniti_search_active_index_schema', 3, false);
            update_option('starfiniti_search_active_analyzer_revision', RankingProfile::ANALYZER_REVISION, false);
            update_option('starfiniti_search_active_ranking_profile', RankingProfile::VERSION, false);
        }

        update_option('starfiniti_search_schema_version', self::SCHEMA_VERSION, false);
        update_option('starfiniti_search_active_generation', $generation, false);
        if (!get_option('starfiniti_search_installation_uuid')) {
            update_option('starfiniti_search_installation_uuid', wp_generate_uuid4(), false);
        }
    }

    /** dbDelta can apply only a prefix of adjacent fractional-datetime additions on some MariaDB versions. */
    private static function ensureGenerationRecoveryColumns(string $table): void
    {
        global $wpdb;
        $columns = ['build_attempts', 'build_lease_token', 'build_leased_until', 'build_updated_at'];
        foreach ($columns as $column) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema installation must inspect authoritative information_schema state.
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s',
                $table,
                $column
            ));
            if ($exists === 1) {
                continue;
            }
            switch ($column) {
                case 'build_attempts':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Literal guarded schema fallback.
                    $result = $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN build_attempts int(10) unsigned NOT NULL DEFAULT 0 AFTER build_cursor', $table));
                    break;
                case 'build_lease_token':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Literal guarded schema fallback.
                    $result = $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN build_lease_token char(36) DEFAULT NULL AFTER build_attempts', $table));
                    break;
                case 'build_leased_until':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Literal guarded schema fallback.
                    $result = $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN build_leased_until datetime(6) DEFAULT NULL AFTER build_lease_token', $table));
                    break;
                case 'build_updated_at':
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Literal guarded schema fallback.
                    $result = $wpdb->query($wpdb->prepare('ALTER TABLE %i ADD COLUMN build_updated_at datetime(6) DEFAULT NULL AFTER build_leased_until', $table));
                    break;
                default:
                    throw new \RuntimeException('Unsupported search generation recovery schema column.');
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify the schema mutation before recording the schema version.
            $verified = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s',
                $table,
                $column
            ));
            if ($result === false || $verified !== 1) {
                throw new \RuntimeException('Search generation recovery schema could not be installed.');
            }
        }
    }
}
