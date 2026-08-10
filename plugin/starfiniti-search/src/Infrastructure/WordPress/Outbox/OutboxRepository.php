<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Outbox;

use wpdb;

final class OutboxRepository
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function enqueue(string $type, int $id, string $operation = 'upsert', int $priority = 100, string $locale = '', ?int $targetGeneration = null): void
    {
        if ($id < 1 || !in_array($operation, ['upsert', 'delete'], true)) {
            return;
        }
        $table = $this->db->prefix . 'sfs_sync_outbox';
        $targetGeneration ??= (int) get_option('starfiniti_search_active_generation');
        if ($targetGeneration < 1) {
            return;
        }
        $dedupe = hash('sha256', implode('|', [$type, (string) $id, $locale, (string) $targetGeneration]));
        $now = gmdate('Y-m-d H:i:s.u');
        $sql = $this->db->prepare(
            "INSERT INTO {$table}
                (aggregate_type,aggregate_id,target_generation,locale,operation,priority,status,dedupe_key,attempts,available_at,created_at,updated_at)
             VALUES (%s,%d,%d,%s,%s,%d,'pending',%s,0,%s,%s,%s)
             ON DUPLICATE KEY UPDATE
                operation=VALUES(operation), priority=LEAST(priority,VALUES(priority)), status='pending',
                attempts=0, available_at=VALUES(available_at), leased_until=NULL, lease_token=NULL,
                last_error_code=NULL, updated_at=VALUES(updated_at)",
            $type,
            $id,
            $targetGeneration,
            $locale,
            $operation,
            $priority,
            $dedupe,
            $now,
            $now,
            $now
        );
        $this->db->query($sql);
    }

    /** @return list<array<string, mixed>> */
    public function claim(int $limit = 20, int $leaseSeconds = 120): array
    {
        $limit = max(1, min(100, $limit));
        $table = $this->db->prefix . 'sfs_sync_outbox';
        $token = wp_generate_uuid4();
        $now = gmdate('Y-m-d H:i:s.u');
        $until = gmdate('Y-m-d H:i:s.u', time() + $leaseSeconds);

        $this->db->query('START TRANSACTION');
        try {
            $ids = $this->db->get_col(
                $this->db->prepare(
                    "SELECT event_id FROM {$table}
                     WHERE (status='pending' OR (status='processing' AND leased_until < %s)) AND available_at <= %s
                     ORDER BY priority ASC,event_id ASC LIMIT %d FOR UPDATE SKIP LOCKED",
                    $now,
                    $now,
                    $limit
                )
            );
            if ($ids === []) {
                $this->db->query('COMMIT');
                return [];
            }
            $idList = implode(',', array_map('intval', $ids));
            $this->db->query(
                $this->db->prepare(
                    "UPDATE {$table} SET status='processing',lease_token=%s,leased_until=%s,attempts=attempts+1,updated_at=%s WHERE event_id IN ({$idList})",
                    $token,
                    $until,
                    $now
                )
            );
            $events = $this->db->get_results($this->db->prepare("SELECT * FROM {$table} WHERE lease_token=%s ORDER BY priority,event_id", $token), ARRAY_A);
            $this->db->query('COMMIT');
            return is_array($events) ? $events : [];
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    public function complete(int $eventId, string $leaseToken): void
    {
        $this->db->delete(
            $this->db->prefix . 'sfs_sync_outbox',
            ['event_id' => $eventId, 'lease_token' => $leaseToken],
            ['%d', '%s']
        );
    }

    public function hasReadyOrPending(): bool
    {
        $table = $this->db->prefix . 'sfs_sync_outbox';
        return (int) $this->db->get_var("SELECT EXISTS(SELECT 1 FROM {$table} WHERE status IN ('pending','processing') LIMIT 1)") === 1;
    }

    public function fail(int $eventId, string $leaseToken, string $code, int $attempts): void
    {
        $terminal = $attempts >= 8;
        $delay = min(3600, 2 ** min(10, max(1, $attempts)));
        $this->db->update(
            $this->db->prefix . 'sfs_sync_outbox',
            [
                'status' => $terminal ? 'failed' : 'pending',
                'available_at' => gmdate('Y-m-d H:i:s.u', time() + $delay),
                'leased_until' => null,
                'lease_token' => null,
                'last_error_code' => substr(sanitize_key($code), 0, 64),
                'updated_at' => gmdate('Y-m-d H:i:s.u'),
            ],
            ['event_id' => $eventId, 'lease_token' => $leaseToken],
            ['%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d', '%s']
        );
    }
}
