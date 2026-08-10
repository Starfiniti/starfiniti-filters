<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Cli;

use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\Operations\HealthService;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationExecutor;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationRepository;

final class SearchCommand
{
    public function __construct(
        private readonly HealthService $health,
        private readonly GenerationManager $generations,
        private readonly OperationRepository $operations,
        private readonly OperationExecutor $executor
    ) {
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function status(array $args, array $assocArgs): void
    {
        $report = $this->health->report(isset($assocArgs['deep']));
        if (isset($assocArgs['analytics'])) {
            $report['analytics'] = $this->health->analyticsReport((int) ($assocArgs['analytics-days'] ?? 30));
        }
        \WP_CLI::line((string) wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function build(array $args, array $assocArgs): void
    {
        $operation = $this->plan('index.build', [], $assocArgs);
        $completed = $this->executor->execute($operation['operation_id'], $operation['plan_hash'], 0);
        \WP_CLI::success('Shadow generation ' . (string) ($completed['result']['generation'] ?? '') . ' scheduled through operation ' . $operation['operation_id'] . '.');
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function activate(array $args, array $assocArgs): void
    {
        $generation = isset($assocArgs['generation']) ? (int) $assocArgs['generation'] : 0;
        $operation = $this->approvedPlan('index.activate', ['target_generation' => $generation], $assocArgs);
        $this->executor->execute($operation['operation_id'], $operation['plan_hash'], (int) $operation['approver_id']);
        \WP_CLI::success('Generation ' . $generation . ' activated.');
    }

    /** @param list<string> $args @param array<string, mixed> $assocArgs */
    public function rollback(array $args, array $assocArgs): void
    {
        $operation = $this->approvedPlan('index.rollback', [], $assocArgs);
        $completed = $this->executor->execute($operation['operation_id'], $operation['plan_hash'], (int) $operation['approver_id']);
        \WP_CLI::success('Rolled back to generation ' . (string) ($completed['result']['active_generation'] ?? '') . '.');
    }

    /** @param array<string,mixed> $desired @param array<string,mixed> $assocArgs @return array<string,mixed> */
    private function plan(string $type, array $desired, array $assocArgs): array
    {
        $key = isset($assocArgs['idempotency-key']) ? (string) $assocArgs['idempotency-key'] : 'cli:' . str_replace('.', '-', $type) . ':' . wp_generate_uuid4();
        $reason = isset($assocArgs['reason']) ? (string) $assocArgs['reason'] : 'WP-CLI initiated operation';
        return $this->operations->create($type, $key, $desired, $reason, 0);
    }

    /** @param array<string,mixed> $desired @param array<string,mixed> $assocArgs @return array<string,mixed> */
    private function approvedPlan(string $type, array $desired, array $assocArgs): array
    {
        if (!isset($assocArgs['approve']) || (int) ($assocArgs['approver'] ?? 0) < 1) {
            \WP_CLI::error('This operation requires --approve and --approver=<WordPress user ID>.');
        }
        $operation = $this->plan($type, $desired, $assocArgs);
        return $this->operations->approve($operation['operation_id'], $operation['plan_hash'], (int) $assocArgs['approver']);
    }
}
