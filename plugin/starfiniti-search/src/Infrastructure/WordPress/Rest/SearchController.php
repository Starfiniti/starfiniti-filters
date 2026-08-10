<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Rest;

use Starfiniti\Search\Domain\Provider\SearchProvider;
use Starfiniti\Search\Infrastructure\WordPress\Operations\HealthService;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities;
use Starfiniti\Search\Infrastructure\WordPress\Operations\StructuredLogger;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SearchController
{
    public function __construct(
        private readonly SearchProvider $provider,
        private readonly ?HealthService $health = null,
        private readonly ?AnalyticsRepository $analytics = null,
        private readonly ?StructuredLogger $logger = null
    )
    {
    }

    public function register(): void
    {
        register_rest_route(
            'starfiniti-search/v1',
            '/search',
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'search'],
                'permission_callback' => '__return_true',
                'args' => [
                    'q' => ['type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field'],
                ],
            ]
        );

        register_rest_route(
            'starfiniti-search/v1',
            '/status',
            [
                'methods' => 'GET',
                'callback' => [$this, 'status'],
                'permission_callback' => static fn (): bool => current_user_can(Capabilities::VIEW_HEALTH),
            ]
        );
    }

    public function search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (!$this->allowRequest()) {
            return new WP_Error('starfiniti_rate_limited', __('Too many search requests.', 'starfiniti-search'), ['status' => 429]);
        }

        $json = $request->get_json_params();
        $query = is_array($json) && isset($json['query']) ? (string) $json['query'] : (string) $request->get_param('q');
        $page = is_array($json) ? (int) ($json['page']['number'] ?? 1) : (int) $request->get_param('page');
        $size = is_array($json) ? (int) ($json['page']['size'] ?? 10) : (int) $request->get_param('size');
        if (mb_strlen($query) > 512) {
            return new WP_Error('starfiniti_invalid_query', __('Search query is too long.', 'starfiniti-search'), ['status' => 400]);
        }

        $providerStartedAt = hrtime(true);
        try {
            $facets = is_array($json) && isset($json['facets']) && is_array($json['facets']) ? array_slice($json['facets'], 0, 16) : [];
            $sort = is_array($json) && isset($json['sort']) && is_array($json['sort']) ? array_slice($json['sort'], 0, 8) : [];
            $filters = is_array($json) ? ($json['filters'] ?? null) : null;
            $mode = is_array($json) ? (string) ($json['options']['suggestion_mode'] ?? 'full_results') : 'autocomplete';
            if (!in_array($mode, ['autocomplete', 'full_results', 'admin_test'], true)) {
                $mode = 'full_results';
            }
            if ($mode === 'admin_test' && !current_user_can(Capabilities::VIEW_HEALTH)) {
                $mode = 'full_results';
            }
            $highlight = is_array($json) && ($json['options']['highlight'] ?? false) === true;
            $includeExplanation = $mode === 'admin_test' && current_user_can(Capabilities::VIEW_HEALTH) && is_array($json) && ($json['options']['include_explanation'] ?? false) === true;
            $result = $this->provider->search([
                'contract_version' => '1.0',
                'query' => $query,
                'context' => [
                    'site_id' => (string) get_option('starfiniti_search_installation_uuid'),
                    'blog_id' => get_current_blog_id(),
                    'locale' => determine_locale(),
                    'currency' => get_woocommerce_currency(),
                    'customer_scope' => ['public'],
                    'channel' => 'storefront',
                ],
                'fields' => ['identity.title', 'identity.sku', 'content.description_text'],
                'filters' => $filters,
                'facets' => $facets,
                'sort' => $sort,
                'page' => ['number' => max(1, min(10000, $page)), 'size' => max(1, min(100, $size))],
                'options' => ['highlight' => $highlight, 'include_explanation' => $includeExplanation, 'suggestion_mode' => $mode],
            ]);
            $this->recordAnalytics($query, $result);
            $response = new WP_REST_Response($result, 200);
            $response->header('Cache-Control', 'private, max-age=15');
            $response->header('X-Content-Type-Options', 'nosniff');
            return $response;
        } catch (\InvalidArgumentException) {
            return new WP_Error('starfiniti_invalid_request', __('The search request is invalid or too complex.', 'starfiniti-search'), ['status' => 400]);
        } catch (\Throwable $exception) {
            $this->recordAnalytics($query, ['provider' => $this->provider->id(), 'total' => 0, 'timing' => ['total_ms' => (hrtime(true) - $providerStartedAt) / 1_000_000]], true);
            $correlationId = $this->logger?->log('error', 'search.provider_failed', 'Search provider request failed.', [
                'provider' => $this->provider->id(),
                'retryable' => true,
                'safe_context' => ['error_class' => $exception::class],
            ]) ?? wp_generate_uuid4();
            return new WP_Error('starfiniti_search_unavailable', __('Search is temporarily unavailable.', 'starfiniti-search'), ['status' => 503, 'correlation_id' => $correlationId]);
        }
    }

    public function status(): WP_REST_Response
    {
        if ($this->health !== null) {
            return new WP_REST_Response($this->health->report(true));
        }
        global $wpdb;
        $generation = (int) get_option('starfiniti_search_active_generation');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Health fallback reports authoritative plugin-index state and must not serve stale cached counts.
        $documents = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $generation));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Health fallback reports authoritative queue state and must not serve stale cached counts.
        $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_sync_outbox WHERE status IN ('pending','processing')");
        return new WP_REST_Response([
            'status' => $pending > 0 ? 'degraded' : 'ready',
            'provider' => $this->provider->id(),
            'generation' => $generation,
            'documents' => $documents,
            'outbox_pending' => $pending,
            'capabilities' => $this->provider->capabilities(),
            'version' => STARFINITI_SEARCH_VERSION,
        ]);
    }

    private function allowRequest(): bool
    {
        $address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $window = (int) floor(time() / 60);
        $key = 'sfs_rl_' . substr(hash_hmac('sha256', $address . ':' . $window, wp_salt('nonce')), 0, 32);
        $count = (int) get_transient($key);
        if ($count >= 120) {
            return false;
        }
        set_transient($key, $count + 1, 70);
        return true;
    }

    /** @param array<string,mixed> $result */
    private function recordAnalytics(string $query, array $result, bool $error = false): void
    {
        if ($this->analytics === null) {
            return;
        }
        try {
            $this->analytics->record($query, $result, $error);
        } catch (\Throwable) {
            // Analytics is strictly non-critical and must never break search.
        }
    }
}
