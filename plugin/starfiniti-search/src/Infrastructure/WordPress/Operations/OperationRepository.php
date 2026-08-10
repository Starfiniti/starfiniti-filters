<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Operations;

use RuntimeException;
use Starfiniti\Search\Domain\Operations\OperationPlan;
use Starfiniti\Search\Domain\Configuration\DesiredConfiguration;
use Starfiniti\Search\Domain\Support\CanonicalJson;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use wpdb;

final class OperationRepository
{
    public function __construct(private readonly wpdb $db, private readonly ConfigurationRepository $configuration)
    {
    }

    /** @param array<string,mixed> $desiredState @return array<string,mixed> */
    public function create(string $type, string $idempotencyKey, array $desiredState, string $reason, int $actorId): array
    {
        $requestedState = $desiredState;
        $requestHash = hash('sha256', CanonicalJson::encode([
            'type' => $type,
            'idempotency_key' => $idempotencyKey,
            'desired_state' => $requestedState,
            'reason' => trim($reason),
        ]));
        $existing = $this->rowByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw new RuntimeException('The idempotency key was already used for a different request.');
            }
            return $this->decodeRow($existing);
        }

        $current = $this->currentState();
        $desiredState = $this->normalizeDesiredState($type, $requestedState, $current);

        $plan = OperationPlan::create(
            wp_generate_uuid4(),
            $type,
            $idempotencyKey,
            $current,
            $desiredState,
            $reason,
            gmdate(DATE_ATOM, time() + 1800)
        );
        $data = $plan->toArray();
        $created = gmdate('Y-m-d H:i:s.u');
        $inserted = $this->db->insert($this->plansTable(), [
            'operation_id' => $data['operation_id'],
            'plan_hash' => $plan->hash(),
            'operation_type' => $type,
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'status' => 'planned',
            'plan_json' => CanonicalJson::encode($data),
            'actor_id' => max(0, $actorId),
            'created_at' => $created,
            'expires_at' => gmdate('Y-m-d H:i:s.u', (int) strtotime((string) $data['expires_at'])),
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']);
        if ($inserted !== 1) {
            $existing = $this->rowByIdempotencyKey($idempotencyKey);
            if ($existing !== null && hash_equals((string) $existing['request_hash'], $requestHash)) {
                return $this->decodeRow($existing);
            }
            throw new RuntimeException('Operation plan persistence failed.');
        }
        $this->audit('plan.created', (string) $data['operation_id'], $plan->hash(), $requestHash, 'planned', $actorId, null, 'Immutable operation plan created');
        return $this->get((string) $data['operation_id']);
    }

    /** @return array<string,mixed> */
    public function get(string $operationId): array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->plansTable()} WHERE operation_id=%s", $operationId), ARRAY_A);
        if (!is_array($row)) {
            throw new RuntimeException('Operation plan was not found.');
        }
        return $this->decodeRow($row);
    }

    /** @return list<array<string,mixed>> */
    public function list(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->get_results($this->db->prepare("SELECT * FROM {$this->plansTable()} ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);
        return array_map(fn (array $row): array => $this->decodeRow($row), is_array($rows) ? $rows : []);
    }

    /** @return array<string,mixed> */
    public function approve(string $operationId, string $planHash, int $approverId): array
    {
        if ($approverId < 1) {
            throw new RuntimeException('An authenticated human approver is required.');
        }
        $operation = $this->get($operationId);
        $this->assertHash($operation, $planHash);
        if (($operation['plan']['approval_required'] ?? false) !== true) {
            return $operation;
        }
        if ($operation['status'] === 'approved' || $operation['status'] === 'succeeded') {
            return $operation;
        }
        if ($operation['status'] !== 'planned' || strtotime((string) $operation['plan']['expires_at']) <= time()) {
            throw new RuntimeException('Only a current planned operation can be approved.');
        }
        $updated = $this->db->query($this->db->prepare(
            "UPDATE {$this->plansTable()} SET status='approved',approver_id=%d,approved_at=%s WHERE operation_id=%s AND plan_hash=%s AND status='planned'",
            $approverId,
            gmdate('Y-m-d H:i:s.u'),
            $operationId,
            $planHash
        ));
        if ($updated !== 1) {
            throw new RuntimeException('Operation approval state changed concurrently.');
        }
        $this->audit('plan.approved', $operationId, $planHash, hash('sha256', $operationId . $planHash), 'approved', $approverId, $approverId, 'High-impact operation approved');
        return $this->get($operationId);
    }

    /** @return array<string,mixed> */
    public function claim(string $operationId, string $planHash, int $actorId): array
    {
        $operation = $this->get($operationId);
        $this->assertHash($operation, $planHash);
        if ($operation['status'] === 'succeeded') {
            return $operation;
        }
        if (strtotime((string) $operation['plan']['expires_at']) <= time()) {
            $this->db->update($this->plansTable(), ['status' => 'expired'], ['operation_id' => $operationId], ['%s'], ['%s']);
            throw new RuntimeException('Operation plan has expired.');
        }
        $approvalRequired = ($operation['plan']['approval_required'] ?? false) === true;
        $allowed = $approvalRequired ? ['approved', 'failed'] : ['planned', 'failed'];
        if (!in_array($operation['status'], $allowed, true)) {
            throw new RuntimeException('Operation cannot execute from its current state.');
        }
        if ($approvalRequired && empty($operation['approver_id'])) {
            throw new RuntimeException('Operation requires human approval.');
        }
        $placeholders = implode(',', array_fill(0, count($allowed), '%s'));
        $query = "UPDATE {$this->plansTable()} SET status='running' WHERE operation_id=%s AND plan_hash=%s AND status IN ({$placeholders})";
        $updated = $this->db->query($this->db->prepare($query, $operationId, $planHash, ...$allowed));
        if ($updated !== 1) {
            throw new RuntimeException('Operation execution was already claimed.');
        }
        $this->audit('operation.started', $operationId, $planHash, hash('sha256', $operationId . ':execute'), 'running', $actorId, isset($operation['approver_id']) ? (int) $operation['approver_id'] : null, 'Typed operation execution started');
        return $this->get($operationId);
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    public function succeed(string $operationId, array $result, int $actorId, float $durationMs): array
    {
        $safeResult = CanonicalJson::encode($result);
        if (strlen($safeResult) > 65535) {
            throw new RuntimeException('Operation result exceeded the safe persistence limit.');
        }
        $this->db->query($this->db->prepare("UPDATE {$this->plansTable()} SET status='succeeded',result_json=%s,executed_at=%s WHERE operation_id=%s AND status='running'", $safeResult, gmdate('Y-m-d H:i:s.u'), $operationId));
        $operation = $this->get($operationId);
        $this->audit('operation.completed', $operationId, (string) $operation['plan_hash'], hash('sha256', $safeResult), 'succeeded', $actorId, isset($operation['approver_id']) ? (int) $operation['approver_id'] : null, 'Typed operation completed', $durationMs);
        return $operation;
    }

    public function fail(string $operationId, string $code, int $actorId, float $durationMs): void
    {
        $result = CanonicalJson::encode(['code' => preg_replace('/[^a-z0-9_.-]/', '', strtolower($code)) ?: 'operation_failed']);
        $this->db->query($this->db->prepare("UPDATE {$this->plansTable()} SET status='failed',result_json=%s,executed_at=%s WHERE operation_id=%s AND status='running'", $result, gmdate('Y-m-d H:i:s.u'), $operationId));
        $operation = $this->get($operationId);
        $this->audit('operation.failed', $operationId, (string) $operation['plan_hash'], hash('sha256', $result), 'failed', $actorId, isset($operation['approver_id']) ? (int) $operation['approver_id'] : null, 'Typed operation failed safely', $durationMs);
    }

    /** @return array<string,int> */
    public function currentState(): array
    {
        return [
            'site_id_hash' => hash('sha256', (string) get_option('starfiniti_search_installation_uuid')),
            'blog_id' => get_current_blog_id(),
            'active_generation' => max(0, (int) get_option('starfiniti_search_active_generation')),
            'configuration_revision' => max(0, $this->configuration->current()?->revision() ?? 0),
        ];
    }

    /** @return list<array<string,mixed>> */
    public function auditHistory(int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->get_results($this->db->prepare("SELECT occurred_at,caller_id,action,operation_id,plan_hash,result_code,approval_identity,correlation_id,duration_ms,safe_summary FROM {$this->auditTable()} ORDER BY audit_id DESC LIMIT %d", $limit), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string,mixed> $desired @param array<string,mixed> $current @return array<string,mixed> */
    private function normalizeDesiredState(string $type, array $desired, array $current): array
    {
        if ($type === 'index.activate') {
            $target = (int) ($desired['target_generation'] ?? 0);
            if ($target < 1) {
                throw new RuntimeException('A target generation is required for activation.');
            }
            return ['target_generation' => $target];
        }
        if ($type === 'index.rollback') {
            $target = (int) get_option('starfiniti_search_previous_generation');
            if ($target < 1 || $target === (int) $current['active_generation']) {
                throw new RuntimeException('No previous generation is available for rollback planning.');
            }
            return ['target_generation' => $target];
        }
        if ($type === 'configuration.apply') {
            $draft = $desired['configuration'] ?? null;
            if (!is_array($draft)) {
                throw new RuntimeException('A desired configuration object is required.');
            }
            $draft['contract_version'] = '1.0';
            $draft['revision'] = (int) $current['configuration_revision'] + 1;
            return ['configuration' => DesiredConfiguration::fromArray($draft)->toArray()];
        }
        if (!in_array($type, ['index.build', 'analytics.purge', 'reconciliation.start'], true)) {
            throw new RuntimeException('Unsupported operation type.');
        }
        return [];
    }

    /** @param array<string,mixed> $operation */
    private function assertHash(array $operation, string $planHash): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $planHash) !== 1 || !hash_equals((string) $operation['plan_hash'], $planHash)) {
            throw new RuntimeException('Operation plan hash mismatch.');
        }
    }

    /** @return array<string,mixed>|null */
    private function rowByIdempotencyKey(string $key): ?array
    {
        $row = $this->db->get_row($this->db->prepare("SELECT * FROM {$this->plansTable()} WHERE idempotency_key=%s", $key), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function decodeRow(array $row): array
    {
        $plan = json_decode((string) $row['plan_json'], true, 64, JSON_THROW_ON_ERROR);
        $result = $row['result_json'] === null ? null : json_decode((string) $row['result_json'], true, 16, JSON_THROW_ON_ERROR);
        return [
            'operation_id' => $row['operation_id'],
            'plan_hash' => $row['plan_hash'],
            'type' => $row['operation_type'],
            'status' => $row['status'],
            'plan' => $plan,
            'result' => $result,
            'actor_id' => (int) $row['actor_id'],
            'approver_id' => $row['approver_id'] === null ? null : (int) $row['approver_id'],
            'created_at' => $row['created_at'],
            'approved_at' => $row['approved_at'],
            'executed_at' => $row['executed_at'],
        ];
    }

    private function audit(string $action, ?string $operationId, ?string $planHash, string $argumentsHash, string $resultCode, int $callerId, ?int $approverId, string $summary, float $durationMs = 0.0): void
    {
        $this->db->insert($this->auditTable(), [
            'occurred_at' => gmdate('Y-m-d H:i:s.u'),
            'caller_id' => max(0, $callerId),
            'action' => substr($action, 0, 64),
            'operation_id' => $operationId,
            'plan_hash' => $planHash,
            'arguments_hash' => $argumentsHash,
            'result_code' => substr($resultCode, 0, 64),
            'approval_identity' => $approverId,
            'correlation_id' => wp_generate_uuid4(),
            'duration_ms' => max(0.0, min(999999999.0, $durationMs)),
            'safe_summary' => mb_substr($summary, 0, 191),
        ], ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%f', '%s']);
    }

    private function plansTable(): string { return $this->db->prefix . 'sfs_operation_plans'; }
    private function auditTable(): string { return $this->db->prefix . 'sfs_operation_audit'; }
}
