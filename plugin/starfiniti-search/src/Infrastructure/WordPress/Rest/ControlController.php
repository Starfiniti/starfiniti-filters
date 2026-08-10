<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Rest;

use RuntimeException;
use Starfiniti\Search\Domain\Provider\SearchProvider;
use Starfiniti\Search\Domain\Support\CanonicalJson;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Operations\HealthService;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationExecutor;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Security\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ControlController
{
    public function __construct(
        private readonly HealthService $health,
        private readonly ConfigurationRepository $configuration,
        private readonly OperationRepository $operations,
        private readonly OperationExecutor $executor,
        private readonly SearchProvider $provider
    ) {
    }

    public function register(): void
    {
        foreach ([
            '/control/status' => [$this, 'status'],
            '/control/capabilities' => [$this, 'capabilities'],
            '/control/configuration' => [$this, 'configuration'],
            '/control/operations' => [$this, 'operations'],
            '/control/audit' => [$this, 'audit'],
        ] as $route => $callback) {
            register_rest_route('starfiniti-search/v1', $route, [
                'methods' => 'GET',
                'callback' => $callback,
                'permission_callback' => [$this, 'canRead'],
            ]);
        }
        register_rest_route('starfiniti-search/v1', '/control/analytics', [
            'methods' => 'GET',
            'callback' => [$this, 'analytics'],
            'permission_callback' => static fn (): bool => current_user_can(Capabilities::VIEW_ANALYTICS),
            'args' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 30],
            ],
        ]);
        register_rest_route('starfiniti-search/v1', '/control/operations/(?P<operation_id>[0-9a-f-]{36})', [
            'methods' => 'GET',
            'callback' => [$this, 'operation'],
            'permission_callback' => [$this, 'canRead'],
        ]);
        register_rest_route('starfiniti-search/v1', '/control/operations/plan', [
            'methods' => 'POST',
            'callback' => [$this, 'plan'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('starfiniti-search/v1', '/control/relevance/preview', [
            'methods' => 'POST',
            'callback' => [$this, 'previewRelevance'],
            'permission_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_CONFIGURATION),
        ]);
        register_rest_route('starfiniti-search/v1', '/control/operations/(?P<operation_id>[0-9a-f-]{36})/approve', [
            'methods' => 'POST',
            'callback' => [$this, 'approve'],
            'permission_callback' => 'is_user_logged_in',
        ]);
        register_rest_route('starfiniti-search/v1', '/control/operations/(?P<operation_id>[0-9a-f-]{36})/execute', [
            'methods' => 'POST',
            'callback' => [$this, 'execute'],
            'permission_callback' => 'is_user_logged_in',
        ]);
    }

    public function canRead(): bool
    {
        return current_user_can(Capabilities::VIEW_HEALTH);
    }

    public function status(): WP_REST_Response { return $this->response($this->health->report(true)); }

    public function analytics(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response($this->health->analyticsReport((int) ($request->get_param('days') ?: 30)));
    }

    public function capabilities(): WP_REST_Response
    {
        return $this->response([
            'contract_version' => '1.0',
            'control_api' => 'starfiniti-search/v1',
            'operations' => ['index.build', 'index.activate', 'index.rollback', 'analytics.purge', 'reconciliation.start', 'configuration.apply'],
            'generic_http' => false,
            'raw_sql' => false,
            'shell' => false,
            'raw_provider_administration' => false,
            'immutable_plans' => true,
            'human_approval' => true,
        ]);
    }

    public function configuration(): WP_REST_Response
    {
        if (!current_user_can(Capabilities::MANAGE_CONFIGURATION)) {
            return $this->response(['contract_version' => '1.0', 'error' => 'forbidden'], 403);
        }
        $current = $this->configuration->current();
        return $this->response([
            'contract_version' => '1.0',
            'desired_configuration' => $current?->toArray(),
            'checksum' => $current?->checksum(),
            'history' => $this->configuration->history(20),
            'secrets_included' => false,
        ]);
    }

    public function operations(WP_REST_Request $request): WP_REST_Response
    {
        return $this->response(['contract_version' => '1.0', 'operations' => $this->operations->list((int) ($request->get_param('limit') ?: 20))]);
    }

    public function operation(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->safe(fn (): array => $this->operations->get((string) $request['operation_id']));
    }

    public function audit(WP_REST_Request $request): WP_REST_Response
    {
        if (!current_user_can(Capabilities::VIEW_AUDIT)) {
            return $this->response(['contract_version' => '1.0', 'error' => 'forbidden'], 403);
        }
        return $this->response(['contract_version' => '1.0', 'records' => $this->operations->auditHistory((int) ($request->get_param('limit') ?: 50))]);
    }

    public function plan(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $this->body($request);
        $type = (string) ($body['type'] ?? '');
        if (!$this->canOperate($type)) {
            return new WP_Error('starfiniti_control_forbidden', __('The caller cannot plan this operation.', 'starfiniti-search'), ['status' => 403]);
        }
        return $this->safe(fn (): array => $this->operations->create(
            $type,
            (string) ($body['idempotency_key'] ?? ''),
            is_array($body['desired_state'] ?? null) ? $body['desired_state'] : [],
            (string) ($body['reason'] ?? ''),
            get_current_user_id()
        ), 201);
    }

    public function previewRelevance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (strlen($request->get_body()) > 131072) {
            return new WP_Error('starfiniti_control_invalid', __('The preview request is too large.', 'starfiniti-search'), ['status' => 400]);
        }
        $body = $this->body($request);
        $query = trim((string) ($body['query'] ?? ''));
        if (mb_strlen($query) > 512) {
            return new WP_Error('starfiniti_control_invalid', __('The preview query is too long.', 'starfiniti-search'), ['status' => 400]);
        }
        $ranking = $body['ranking'] ?? null;
        if (!is_array($ranking)) {
            return new WP_Error('starfiniti_control_invalid', __('A ranking configuration is required.', 'starfiniti-search'), ['status' => 400]);
        }
        $current = $this->configuration->current();
        if ($current === null) {
            return new WP_Error('starfiniti_control_unavailable', __('Configuration is unavailable.', 'starfiniti-search'), ['status' => 503]);
        }
        $configuration = $current->toArray();
        $locale = (string) ($body['locale'] ?? determine_locale());
        if (!in_array($locale, $configuration['catalog']['locales'] ?? [], true)) {
            return new WP_Error('starfiniti_control_invalid', __('The preview locale is not configured.', 'starfiniti-search'), ['status' => 400]);
        }
        $channel = (string) ($body['channel'] ?? 'storefront');
        if (!in_array($channel, ['storefront', 'headless'], true)) {
            return new WP_Error('starfiniti_control_invalid', __('The preview channel is invalid.', 'starfiniti-search'), ['status' => 400]);
        }
        return $this->safe(function () use ($query, $ranking, $locale, $channel, $current): array {
            $result = $this->provider->search([
                'query' => $query,
                'context' => ['locale' => $locale, 'channel' => $channel, 'customer_scope' => ['public']],
                'page' => ['number' => 1, 'size' => 10],
                'filters' => null,
                'facets' => [],
                'sort' => [],
                'options' => ['suggestion_mode' => 'admin_test', 'include_explanation' => true, 'highlight' => true, 'ranking_override' => $ranking],
            ]);
            return [
                'contract_version' => '1.0',
                'active_configuration_revision' => $current->revision(),
                'preview_checksum' => hash('sha256', CanonicalJson::encode($ranking)),
                'mutation_performed' => false,
                'result' => $result,
            ];
        });
    }

    public function approve(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $this->body($request);
        return $this->safe(function () use ($request, $body): array {
            $operation = $this->operations->get((string) $request['operation_id']);
            if (!$this->canOperate((string) $operation['type'])) {
                throw new RuntimeException('Operation authorization failed.');
            }
            return $this->operations->approve((string) $request['operation_id'], (string) ($body['plan_hash'] ?? ''), get_current_user_id());
        });
    }

    public function execute(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = $this->body($request);
        return $this->safe(function () use ($request, $body): array {
            $operation = $this->operations->get((string) $request['operation_id']);
            if (!$this->canOperate((string) $operation['type'])) {
                throw new RuntimeException('Operation authorization failed.');
            }
            return $this->executor->execute((string) $request['operation_id'], (string) ($body['plan_hash'] ?? ''), get_current_user_id());
        });
    }

    private function canOperate(string $type): bool
    {
        return $type === 'analytics.purge'
            ? current_user_can(Capabilities::PURGE_ANALYTICS)
            : ($type === 'configuration.apply' ? current_user_can(Capabilities::MANAGE_CONFIGURATION) : current_user_can(Capabilities::MANAGE_INDEX));
    }

    /** @return array<string,mixed> */
    private function body(WP_REST_Request $request): array
    {
        $body = $request->get_json_params();
        return is_array($body) ? $body : [];
    }

    /** @param callable():array<string,mixed> $callback */
    private function safe(callable $callback, int $successStatus = 200): WP_REST_Response|WP_Error
    {
        try {
            return $this->response($callback(), $successStatus);
        } catch (\InvalidArgumentException $exception) {
            return new WP_Error('starfiniti_control_invalid', $exception->getMessage(), ['status' => 400]);
        } catch (RuntimeException $exception) {
            $status = str_contains(strtolower($exception->getMessage()), 'not found') ? 404 : 409;
            return new WP_Error('starfiniti_control_conflict', $exception->getMessage(), ['status' => $status]);
        } catch (\Throwable) {
            return new WP_Error('starfiniti_control_unavailable', __('The control operation is temporarily unavailable.', 'starfiniti-search'), ['status' => 503]);
        }
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): WP_REST_Response
    {
        if (!isset($data['contract_version'])) {
            $data = ['contract_version' => '1.0'] + $data;
        }
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
