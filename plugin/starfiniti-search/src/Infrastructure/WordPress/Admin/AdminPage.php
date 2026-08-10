<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Admin;

use JsonException;
use Starfiniti\Search\Domain\Provider\SearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\Operations\HealthService;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationExecutor;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities;

final class AdminPage
{
    public function __construct(
        private readonly HealthService $health,
        private readonly GenerationManager $generations,
        private readonly OperationRepository $operations,
        private readonly OperationExecutor $executor,
        private readonly ConfigurationRepository $configuration,
        private readonly SearchProvider $provider
    ) {
    }

    public function register(): void
    {
        add_submenu_page(
            'woocommerce',
            __('Starfiniti Search', 'starfiniti-search'),
            __('Starfiniti Search', 'starfiniti-search'),
            Capabilities::VIEW_HEALTH,
            'starfiniti-search',
            [$this, 'render']
        );
    }

    public function handleAction(): void
    {
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $requiredCapability = match ($operation) {
            'purge_analytics' => Capabilities::PURGE_ANALYTICS,
            'save_settings' => Capabilities::MANAGE_CONFIGURATION,
            default => Capabilities::MANAGE_INDEX,
        };
        if (!current_user_can($requiredCapability)) {
            wp_die(esc_html__('You are not allowed to manage this search operation.', 'starfiniti-search'), 403);
        }
        check_admin_referer('starfiniti_search_operation');
        try {
            $type = match ($operation) {
                'build' => 'index.build',
                'activate' => 'index.activate',
                'rollback' => 'index.rollback',
                'reconcile' => 'reconciliation.start',
                'purge_analytics' => 'analytics.purge',
                'save_settings' => 'configuration.apply',
                default => throw new \RuntimeException('Unknown operation.'),
            };
            $desired = $type === 'index.activate' ? ['target_generation' => absint($_POST['generation'] ?? 0)] : [];
            if ($type === 'configuration.apply') {
                $current = $this->configuration->current();
                if ($current === null) {
                    throw new \RuntimeException('Configuration is unavailable.');
                }
                $draft = $current->toArray();
                $draft['operations']['batch_size'] = max(1, min(500, absint($_POST['batch_size'] ?? 50)));
                $draft['analytics'] = [
                    'enabled' => isset($_POST['analytics_enabled']),
                    'retention_days' => max(1, min(365, absint($_POST['retention_days'] ?? 30))),
                ];
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded JSON is decoded and strictly validated by the immutable domain configuration.
                $synonymsJson = wp_unslash((string) ($_POST['synonyms_json'] ?? '[]'));
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded JSON is decoded and strictly validated by the immutable domain configuration.
                $stopWordsJson = wp_unslash((string) ($_POST['stop_words_json'] ?? '{}'));
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded JSON is decoded and strictly validated by the immutable domain configuration.
                $curationsJson = wp_unslash((string) ($_POST['curations_json'] ?? '[]'));
                if (strlen($synonymsJson) > 65536 || strlen($stopWordsJson) > 65536 || strlen($curationsJson) > 65536) {
                    throw new \RuntimeException('Relevance configuration exceeds the 64 KiB field limit.');
                }
                try {
                    $synonyms = json_decode($synonymsJson, true, 32, JSON_THROW_ON_ERROR);
                    $stopWords = json_decode($stopWordsJson, true, 32, JSON_THROW_ON_ERROR);
                    $curations = json_decode($curationsJson, true, 32, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new \RuntimeException('Synonyms, stop words, and curations must be valid JSON.');
                }
                if (!is_array($synonyms) || !is_array($stopWords) || !is_array($curations)) {
                    throw new \RuntimeException('Synonyms, stop words, and curations must decode to arrays.');
                }
                $draft['ranking']['synonyms'] = $synonyms;
                $draft['ranking']['stop_words'] = $stopWords;
                $draft['ranking']['curations'] = $curations;
                $desired = ['configuration' => $draft];
            }
            $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_wpnonce'] ?? '')));
            $reason = mb_substr(sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? 'Administrator initiated operation'))), 0, 500);
            if ($reason === '') {
                throw new \RuntimeException('A bounded reason is required.');
            }
            $keyMaterial = wp_json_encode($desired, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $key = 'admin:' . get_current_user_id() . ':' . $operation . ':' . substr(hash('sha256', $nonce . ':' . $keyMaterial), 0, 32);
            $plan = $this->operations->create($type, $key, $desired, $reason, get_current_user_id());
            if (($plan['plan']['approval_required'] ?? false) === true) {
                $plan = $this->operations->approve($plan['operation_id'], $plan['plan_hash'], get_current_user_id());
            }
            $this->executor->execute($plan['operation_id'], $plan['plan_hash'], get_current_user_id());
            $notice = 'success';
        } catch (\Throwable) {
            $notice = 'error';
            set_transient('sfs_admin_error_' . get_current_user_id(), __('The operation failed safely. Review the Starfiniti Search structured log for details.', 'starfiniti-search'), 60);
        }
        wp_safe_redirect(add_query_arg(['page' => 'starfiniti-search', 'sfs_notice' => $notice], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(Capabilities::VIEW_HEALTH)) {
            return;
        }
        $report = $this->health->report(true);
        if (current_user_can(Capabilities::VIEW_ANALYTICS)) {
            $report['analytics'] = $this->health->analyticsReport(30);
        }
        $generations = is_array($report['generations'] ?? null) ? $report['generations'] : [];
        $canManageIndex = current_user_can(Capabilities::MANAGE_INDEX);
        $recentOperations = $this->operations->list(10);
        $currentConfiguration = $this->configuration->current()?->toArray();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, capability-protected preview; no state is changed.
        $previewQuery = current_user_can(Capabilities::VIEW_HEALTH) ? mb_substr(sanitize_text_field(wp_unslash((string) ($_GET['sfs_preview_query'] ?? ''))), 0, 512) : '';
        $preview = null;
        if ($previewQuery !== '') {
            try {
                $preview = $this->provider->search([
                    'query' => $previewQuery,
                    'context' => ['locale' => determine_locale(), 'channel' => 'storefront', 'customer_scope' => ['public']],
                    'page' => ['number' => 1, 'size' => 10],
                    'filters' => null,
                    'facets' => [],
                    'sort' => [],
                    'options' => ['suggestion_mode' => 'admin_test', 'include_explanation' => true, 'highlight' => true],
                ]);
            } catch (\Throwable) {
                $preview = ['hits' => [], 'warnings' => ['preview_failed']];
            }
        }
        $adminError = get_transient('sfs_admin_error_' . get_current_user_id());
        if (is_string($adminError)) {
            delete_transient('sfs_admin_error_' . get_current_user_id());
        }
        $setup = is_array($report['setup'] ?? null) ? $report['setup'] : ['status' => 'blocked', 'steps' => []];
        /* translators: %s is the current setup readiness status. */
        $setupStatusText = sprintf(__('Setup status: %s. This assessment validates current evidence and never represents release certification.', 'starfiniti-search'), (string) ($setup['status'] ?? 'blocked'));
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Starfiniti Search operations', 'starfiniti-search'); ?></h1>
            <?php if (is_string($adminError) && $adminError !== '') : ?><div class="notice notice-error"><p><?php echo esc_html($adminError); ?></p></div><?php endif; ?>
            <p><?php echo esc_html__('Live status, versioned index builds, activation, and rollback. No credentials are displayed here.', 'starfiniti-search'); ?></p>
            <h2><?php echo esc_html__('Setup readiness assessment', 'starfiniti-search'); ?></h2>
            <p><?php echo esc_html($setupStatusText); ?></p>
            <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Step', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Status', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Evidence', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Next action', 'starfiniti-search'); ?></th></tr></thead><tbody>
            <?php foreach (($setup['steps'] ?? []) as $step) : ?>
                <tr><td><?php echo esc_html((string) ($step['label'] ?? '')); ?></td><td><strong><?php echo esc_html((string) ($step['status'] ?? 'blocked')); ?></strong></td><td><code><?php echo esc_html((string) wp_json_encode($step['evidence'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></code></td><td><?php echo esc_html((string) ($step['next_action'] ?? __('No action required.', 'starfiniti-search'))); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <table class="widefat striped"><tbody>
                <tr><th><?php echo esc_html__('Readiness', 'starfiniti-search'); ?></th><td><?php echo esc_html((string) $report['readiness']['status']); ?></td></tr>
                <tr><th><?php echo esc_html__('Provider', 'starfiniti-search'); ?></th><td><?php echo esc_html((string) $report['provider']['id']); ?></td></tr>
                <tr><th><?php echo esc_html__('Active generation', 'starfiniti-search'); ?></th><td><?php echo esc_html((string) $report['readiness']['active_generation']); ?></td></tr>
                <tr><th><?php echo esc_html__('Documents', 'starfiniti-search'); ?></th><td><?php echo esc_html((string) $report['readiness']['documents']); ?></td></tr>
                <tr><th><?php echo esc_html__('Pending / failed', 'starfiniti-search'); ?></th><td><?php echo esc_html($report['readiness']['outbox_pending'] . ' / ' . $report['readiness']['outbox_failed']); ?></td></tr>
            </tbody></table>
            <h2><?php echo esc_html__('Index generations', 'starfiniti-search'); ?></h2>
            <table class="widefat striped"><thead><tr><th>ID</th><th><?php echo esc_html__('State', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Documents / cursor', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Attempts / lease expiry', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Last build update', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Created', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Action', 'starfiniti-search'); ?></th></tr></thead><tbody>
            <?php foreach ($generations as $generation) : ?>
                <tr><td><?php echo esc_html((string) $generation['generation_id']); ?></td><td><?php echo esc_html((string) $generation['state']); ?></td><td><?php echo esc_html($generation['document_count'] . ' / ' . $generation['build_cursor']); ?></td><td><?php echo esc_html($generation['build_attempts'] . ' / ' . ($generation['build_leased_until'] ?? __('none', 'starfiniti-search'))); ?></td><td><?php echo esc_html((string) ($generation['build_updated_at'] ?? '')); ?></td><td><?php echo esc_html((string) $generation['created_at']); ?></td><td>
                <?php if ($canManageIndex && $generation['state'] === 'ready') : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('starfiniti_search_operation'); ?><input type="hidden" name="action" value="starfiniti_search_operation"><input type="hidden" name="operation" value="activate"><input type="hidden" name="generation" value="<?php echo esc_attr((string) $generation['generation_id']); ?>"><button class="button"><?php echo esc_html__('Activate', 'starfiniti-search'); ?></button></form>
                <?php endif; ?>
                </td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if ($canManageIndex) : ?>
                <div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('starfiniti_search_operation'); ?><input type="hidden" name="action" value="starfiniti_search_operation"><input type="hidden" name="operation" value="build"><button class="button button-primary"><?php echo esc_html__('Build shadow generation', 'starfiniti-search'); ?></button></form></div>
                <div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('starfiniti_search_operation'); ?><input type="hidden" name="action" value="starfiniti_search_operation"><input type="hidden" name="operation" value="rollback"><button class="button"><?php echo esc_html__('Rollback to previous generation', 'starfiniti-search'); ?></button></form></div>
                <div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('starfiniti_search_operation'); ?><input type="hidden" name="action" value="starfiniti_search_operation"><input type="hidden" name="operation" value="reconcile"><button class="button"><?php echo esc_html__('Run bounded reconciliation', 'starfiniti-search'); ?></button></form></div>
            <?php endif; ?>
            <h2><?php echo esc_html__('Recent controlled operations', 'starfiniti-search'); ?></h2>
            <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Operation', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Type', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Status', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Created', 'starfiniti-search'); ?></th></tr></thead><tbody>
            <?php foreach ($recentOperations as $controlledOperation) : ?>
                <tr><td><code><?php echo esc_html((string) $controlledOperation['operation_id']); ?></code></td><td><?php echo esc_html((string) $controlledOperation['type']); ?></td><td><?php echo esc_html((string) $controlledOperation['status']); ?></td><td><?php echo esc_html((string) $controlledOperation['created_at']); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>
            <?php if (current_user_can(Capabilities::MANAGE_CONFIGURATION) && is_array($currentConfiguration)) : ?>
                <h2><?php echo esc_html__('Safe configuration', 'starfiniti-search'); ?></h2>
                <p><?php echo esc_html__('Saving creates an immutable, approved revision. Provider credentials are external references and are never accepted by this form.', 'starfiniti-search'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('starfiniti_search_operation'); ?><input type="hidden" name="action" value="starfiniti_search_operation"><input type="hidden" name="operation" value="save_settings">
                    <table class="form-table"><tbody>
                        <tr><th><label for="sfs_batch_size"><?php echo esc_html__('Index batch size', 'starfiniti-search'); ?></label></th><td><input id="sfs_batch_size" name="batch_size" type="number" min="1" max="500" value="<?php echo esc_attr((string) ($currentConfiguration['operations']['batch_size'] ?? 50)); ?>"></td></tr>
                        <tr><th><?php echo esc_html__('Aggregate analytics', 'starfiniti-search'); ?></th><td><label><input name="analytics_enabled" type="checkbox" value="1" <?php checked(!empty($currentConfiguration['analytics']['enabled'])); ?>> <?php echo esc_html__('Enable privacy-preserving daily aggregates', 'starfiniti-search'); ?></label></td></tr>
                        <tr><th><label for="sfs_retention_days"><?php echo esc_html__('Analytics retention days', 'starfiniti-search'); ?></label></th><td><input id="sfs_retention_days" name="retention_days" type="number" min="1" max="365" value="<?php echo esc_attr((string) ($currentConfiguration['analytics']['retention_days'] ?? 30)); ?>"></td></tr>
                        <tr><th><label for="sfs_synonyms_json"><?php echo esc_html__('Synonym rules (JSON)', 'starfiniti-search'); ?></label></th><td><textarea id="sfs_synonyms_json" name="synonyms_json" rows="12" cols="100" spellcheck="false"><?php echo esc_textarea((string) wp_json_encode($currentConfiguration['ranking']['synonyms'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea><p class="description"><?php echo esc_html__('Equivalent or directional rules; locale, channel, effective dates, cycles, and expansion limits are validated before a revision can activate.', 'starfiniti-search'); ?></p></td></tr>
                        <tr><th><label for="sfs_stop_words_json"><?php echo esc_html__('Stop words by locale (JSON)', 'starfiniti-search'); ?></label></th><td><textarea id="sfs_stop_words_json" name="stop_words_json" rows="8" cols="100" spellcheck="false"><?php echo esc_textarea((string) wp_json_encode($currentConfiguration['ranking']['stop_words'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea><p class="description"><?php echo esc_html__('Object keyed by locale. Queries containing only stop words return a safe empty response.', 'starfiniti-search'); ?></p></td></tr>
                        <tr><th><label for="sfs_curations_json"><?php echo esc_html__('Curations and redirects (JSON)', 'starfiniti-search'); ?></label></th><td><textarea id="sfs_curations_json" name="curations_json" rows="14" cols="100" spellcheck="false"><?php echo esc_textarea((string) wp_json_encode($currentConfiguration['ranking']['curations'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea><p class="description"><?php echo esc_html__('Exact-query rules support prioritized pin, boost, bury, hide, and same-origin path redirects. Visibility and active storefront filters always remain mandatory.', 'starfiniti-search'); ?></p></td></tr>
                        <tr><th><label for="sfs_change_reason"><?php echo esc_html__('Change reason', 'starfiniti-search'); ?></label></th><td><input id="sfs_change_reason" name="reason" type="text" maxlength="500" required class="regular-text"></td></tr>
                    </tbody></table>
                    <button class="button button-primary"><?php echo esc_html__('Create and apply configuration revision', 'starfiniti-search'); ?></button>
                </form>
                <h2><?php echo esc_html__('Relevance laboratory', 'starfiniti-search'); ?></h2>
                <p><?php echo esc_html__('Preview the active immutable revision with safe score explanations. Preview requests are not recorded as raw-query analytics.', 'starfiniti-search'); ?></p>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"><input type="hidden" name="page" value="starfiniti-search"><label for="sfs_preview_query"><?php echo esc_html__('Query', 'starfiniti-search'); ?></label> <input id="sfs_preview_query" name="sfs_preview_query" type="search" maxlength="512" value="<?php echo esc_attr($previewQuery); ?>"> <button class="button"><?php echo esc_html__('Preview active revision', 'starfiniti-search'); ?></button></form>
                <?php if (is_array($preview)) : ?>
                    <?php
                    /* translators: %s is a comma-separated list of safe relevance warning codes. */
                    $previewWarningsText = sprintf(__('Warnings: %s', 'starfiniti-search'), implode(', ', array_map('strval', $preview['warnings'] ?? [])) ?: __('none', 'starfiniti-search'));
                    ?>
                    <p><?php echo esc_html($previewWarningsText); ?></p>
                    <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Rank', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Product', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Score', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Matched fields', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Safe explanation', 'starfiniti-search'); ?></th></tr></thead><tbody>
                    <?php foreach (($preview['hits'] ?? []) as $hit) : ?><tr><td><?php echo esc_html((string) ($hit['rank'] ?? '')); ?></td><td><?php echo esc_html((string) ($hit['projection']['identity']['title'] ?? '')); ?></td><td><?php echo esc_html((string) ($hit['score'] ?? '')); ?></td><td><?php echo esc_html(implode(', ', array_map('strval', $hit['matched_fields'] ?? []))); ?></td><td><code><?php echo esc_html((string) wp_json_encode($hit['explanation'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></code></td></tr><?php endforeach; ?>
                    </tbody></table>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (current_user_can(Capabilities::VIEW_ANALYTICS)) :
                $analytics = $report['analytics'] ?? [];
                /* translators: 1: analytics enabled status, 2: retention days. */
                $analyticsPolicyText = sprintf(__('Enabled: %1$s. Retention: %2$d days. Raw queries and user identifiers are never stored.', 'starfiniti-search'), !empty($analytics['policy']['enabled']) ? __('yes', 'starfiniti-search') : __('no', 'starfiniti-search'), (int) ($analytics['policy']['retention_days'] ?? 0));
                /* translators: 1: UTC reporting window start, 2: UTC reporting window end. */
                $analyticsWindowText = sprintf(__('Window: %1$s through %2$s UTC. No deliberate sampling; failed aggregate writes can undercount requests.', 'starfiniti-search'), (string) ($analytics['window']['start_utc'] ?? ''), (string) ($analytics['window']['end_utc'] ?? ''));
                ?>
                <h2><?php echo esc_html__('Privacy-preserving analytics', 'starfiniti-search'); ?></h2>
                <p><?php echo esc_html($analyticsPolicyText); ?></p>
                <p><?php echo esc_html($analyticsWindowText); ?></p>
                <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Date', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Provider / revision / generation', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Searches', 'starfiniti-search'); ?></th><th><?php echo esc_html__('No-result rate', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Error rate', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Average / maximum latency (ms)', 'starfiniti-search'); ?></th><th><?php echo esc_html__('Latency coverage', 'starfiniti-search'); ?></th></tr></thead><tbody>
                <?php foreach (($analytics['series'] ?? []) as $metric) : ?>
                    <tr><td><?php echo esc_html((string) $metric['metric_date']); ?></td><td><?php echo esc_html($metric['provider'] . ' / ' . $metric['configuration_revision'] . ' / ' . $metric['index_generation']); ?></td><td><?php echo esc_html((string) $metric['searches']); ?></td><td><?php echo esc_html($metric['no_result_rate'] === null ? __('n/a', 'starfiniti-search') : number_format_i18n(100 * (float) $metric['no_result_rate'], 2) . '%'); ?></td><td><?php echo esc_html($metric['error_rate'] === null ? __('n/a', 'starfiniti-search') : number_format_i18n(100 * (float) $metric['error_rate'], 2) . '%'); ?></td><td><?php echo esc_html(($metric['average_latency_ms'] === null ? __('n/a', 'starfiniti-search') : number_format_i18n((float) $metric['average_latency_ms'], 3)) . ' / ' . ($metric['maximum_latency_ms'] === null ? __('n/a', 'starfiniti-search') : number_format_i18n((float) $metric['maximum_latency_ms'], 3))); ?></td><td><?php echo esc_html($metric['latency_coverage_rate'] === null ? __('n/a', 'starfiniti-search') : number_format_i18n(100 * (float) $metric['latency_coverage_rate'], 2) . '%'); ?></td></tr>
                <?php endforeach; ?>
                </tbody></table>
                <details><summary><?php echo esc_html__('Metric definitions and unavailable reports', 'starfiniti-search'); ?></summary><dl>
                    <?php foreach (($analytics['definitions'] ?? []) as $name => $definition) : ?><dt><code><?php echo esc_html((string) $name); ?></code></dt><dd><?php echo esc_html((string) ($definition['definition'] ?? '')); ?></dd><?php endforeach; ?>
                    <?php foreach (($analytics['unavailable_metrics'] ?? []) as $unavailable) : ?><dt><code><?php echo esc_html((string) ($unavailable['metric'] ?? '')); ?></code></dt><dd><?php echo esc_html((string) ($unavailable['reason'] ?? '')); ?></dd><?php endforeach; ?>
                </dl></details>
                <?php if (current_user_can(Capabilities::PURGE_ANALYTICS)) : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Permanently purge all aggregate analytics?', 'starfiniti-search')); ?>');"><?php wp_nonce_field('starfiniti_search_operation'); ?><input type="hidden" name="action" value="starfiniti_search_operation"><input type="hidden" name="operation" value="purge_analytics"><button class="button button-secondary"><?php echo esc_html__('Purge aggregate analytics', 'starfiniti-search'); ?></button></form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
