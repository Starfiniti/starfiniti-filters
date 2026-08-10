<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Operations;

use InvalidArgumentException;
use Starfiniti\Search\Domain\Support\CanonicalJson;

final class OperationPlan
{
    private const TYPES = [
        'index.build' => ['scope' => 'search.index.execute', 'approval' => false, 'destructive' => false],
        'index.activate' => ['scope' => 'search.index.activate', 'approval' => true, 'destructive' => false],
        'index.rollback' => ['scope' => 'search.index.rollback', 'approval' => true, 'destructive' => false],
        'analytics.purge' => ['scope' => 'search.analytics.purge', 'approval' => true, 'destructive' => true],
        'reconciliation.start' => ['scope' => 'search.index.execute', 'approval' => false, 'destructive' => false],
        'configuration.apply' => ['scope' => 'search.config.write', 'approval' => true, 'destructive' => false],
    ];

    /** @param array<string,mixed> $currentState @param array<string,mixed> $desiredState */
    public static function create(string $operationId, string $type, string $idempotencyKey, array $currentState, array $desiredState, string $reason, string $expiresAt): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $operationId) !== 1) {
            throw new InvalidArgumentException('A UUIDv4 operation ID is required.');
        }
        if (!isset(self::TYPES[$type])) {
            throw new InvalidArgumentException('Unsupported operation type.');
        }
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,190}$/', $idempotencyKey) !== 1) {
            throw new InvalidArgumentException('A bounded idempotency key of at least eight characters is required.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new InvalidArgumentException('A bounded operation reason is required.');
        }
        $expiry = strtotime($expiresAt);
        if ($expiry === false || $expiry <= time() || $expiry > time() + 86400) {
            throw new InvalidArgumentException('Operation expiry must be within the next 24 hours.');
        }

        $policy = self::TYPES[$type];
        $step = [
            'id' => 'execute',
            'action' => $type,
            'description' => $reason,
            'destructive' => $policy['destructive'],
            'idempotency_key' => $idempotencyKey,
        ];
        $plan = [
            'contract_version' => '1.0',
            'operation_id' => strtolower($operationId),
            'plan_hash' => '',
            'type' => $type,
            'dry_run' => true,
            'current_state' => $currentState,
            'desired_state' => $desiredState,
            'steps' => [$step],
            'risks' => self::risks($type),
            'preconditions' => [
                ['field' => 'active_generation', 'equals' => (int) ($currentState['active_generation'] ?? 0)],
                ['field' => 'configuration_revision', 'equals' => (int) ($currentState['configuration_revision'] ?? 0)],
            ],
            'estimated_impact' => ['storefront_downtime_expected' => false, 'bounded' => true],
            'verification' => [['check' => 'operation_result_persisted'], ['check' => 'health_readiness']],
            'rollback' => self::rollback($type, $currentState),
            'required_scope' => $policy['scope'],
            'approval_required' => $policy['approval'],
            'expires_at' => gmdate(DATE_ATOM, $expiry),
        ];
        $hashInput = $plan;
        unset($hashInput['plan_hash']);
        $plan['plan_hash'] = hash('sha256', CanonicalJson::encode($hashInput));
        return new self($plan);
    }

    /** @param array<string,mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function hash(): string
    {
        return (string) $this->data['plan_hash'];
    }

    /** @return list<string> */
    private static function risks(string $type): array
    {
        return match ($type) {
            'index.activate', 'index.rollback' => ['Search ranking or result coverage may change; the prior generation remains available for rollback.'],
            'configuration.apply' => ['Search behavior or operational policy may change; the previous immutable revision remains in history.'],
            'analytics.purge' => ['Aggregated analytics are permanently deleted.'],
            default => ['Background work can increase database and queue load.'],
        };
    }

    /** @param array<string,mixed> $currentState @return array<string,mixed> */
    private static function rollback(string $type, array $currentState): array
    {
        return in_array($type, ['index.activate', 'index.rollback'], true)
            ? ['supported' => true, 'target_generation' => (int) ($currentState['active_generation'] ?? 0)]
            : ['supported' => false];
    }
}
