<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Operations;

use RuntimeException;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\Reconciliation\CatalogReconciler;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;

final class OperationExecutor
{
    public function __construct(
        private readonly OperationRepository $operations,
        private readonly GenerationManager $generations,
        private readonly AnalyticsRepository $analytics,
        private readonly CatalogReconciler $reconciler,
        private readonly ConfigurationRepository $configuration
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(string $operationId, string $planHash, int $actorId): array
    {
        $started = microtime(true);
        $operation = $this->operations->claim($operationId, $planHash, $actorId);
        if ($operation['status'] === 'succeeded') {
            return $operation;
        }
        try {
            $this->assertPreconditions($operation);
            $desired = is_array($operation['plan']['desired_state'] ?? null) ? $operation['plan']['desired_state'] : [];
            $result = match ($operation['type']) {
                'index.build' => ['generation' => $this->generations->startBuild(), 'scheduled' => true],
                'index.activate' => $this->activate((int) ($desired['target_generation'] ?? 0)),
                'index.rollback' => $this->activate((int) ($desired['target_generation'] ?? 0)),
                'analytics.purge' => ['rows_deleted' => $this->analytics->purgeAll()],
                'reconciliation.start' => $this->reconcile(),
                'configuration.apply' => $this->applyConfiguration($operationId, $desired, $actorId),
                default => throw new RuntimeException('Unsupported typed operation.'),
            };
            return $this->operations->succeed($operationId, $result, $actorId, (microtime(true) - $started) * 1000);
        } catch (\Throwable $exception) {
            $this->operations->fail($operationId, $this->errorCode($exception), $actorId, (microtime(true) - $started) * 1000);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $operation */
    private function assertPreconditions(array $operation): void
    {
        $planned = $operation['plan']['current_state'] ?? [];
        $current = $this->operations->currentState();
        foreach (['site_id_hash', 'blog_id', 'active_generation', 'configuration_revision'] as $field) {
            if (($planned[$field] ?? null) !== ($current[$field] ?? null)) {
                throw new RuntimeException('Operation precondition failed because site state changed.');
            }
        }
    }

    /** @return array<string,mixed> */
    private function activate(int $target): array
    {
        if ($target < 1) {
            throw new RuntimeException('Operation target generation is invalid.');
        }
        if ((int) get_option('starfiniti_search_active_generation') !== $target) {
            $this->generations->activate($target);
        }
        return ['active_generation' => $target];
    }

    /** @return array<string,mixed> */
    private function reconcile(): array
    {
        $this->reconciler->run(0);
        return ['scheduled_or_completed' => true];
    }

    /** @param array<string,mixed> $desired @return array<string,mixed> */
    private function applyConfiguration(string $operationId, array $desired, int $actorId): array
    {
        $draft = $desired['configuration'] ?? null;
        if (!is_array($draft)) {
            throw new RuntimeException('Operation desired configuration is missing.');
        }
        $configuration = $this->configuration->createRevision($draft, 'Approved control operation ' . $operationId, $actorId);
        return ['configuration_revision' => $configuration->revision(), 'checksum' => $configuration->checksum()];
    }

    private function errorCode(\Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'precondition') ? 'precondition_failed' : 'operation_failed';
    }
}
