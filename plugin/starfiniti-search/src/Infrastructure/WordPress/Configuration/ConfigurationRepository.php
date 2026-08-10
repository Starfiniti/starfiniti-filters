<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Configuration;

use RuntimeException;
use Starfiniti\Search\Domain\Configuration\DesiredConfiguration;
use Starfiniti\Search\Domain\Support\CanonicalJson;
use wpdb;

final class ConfigurationRepository
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function initialize(string $locale): DesiredConfiguration
    {
        $current = $this->current();
        if ($current !== null) {
            return $current;
        }
        $configuration = DesiredConfiguration::defaults($locale);
        $this->insert($configuration, null, 0, 'installation default');
        return $configuration;
    }

    public function current(): ?DesiredConfiguration
    {
        $row = $this->db->get_row("SELECT configuration_json FROM {$this->table()} WHERE state='active' ORDER BY revision_id DESC LIMIT 1", ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        $decoded = json_decode((string) $row['configuration_json'], true, 64, JSON_THROW_ON_ERROR);
        return DesiredConfiguration::fromArray($decoded);
    }

    /** @param array<string, mixed> $draft */
    public function createRevision(array $draft, string $reason, int $actorId, bool $allowUncertifiedExternalProvider = false): DesiredConfiguration
    {
        $current = $this->current();
        if ($current === null) {
            throw new RuntimeException('Configuration has not been initialized.');
        }
        $draft['contract_version'] = '1.0';
        $draft['revision'] = $current->revision() + 1;
        $next = DesiredConfiguration::fromArray($draft);
        if (!$allowUncertifiedExternalProvider && $next->toArray()['active_read_provider'] !== 'local') {
            throw new RuntimeException('External provider activation is unavailable until real-service certification passes.');
        }
        if (hash_equals($current->semanticChecksum(), $next->semanticChecksum())) {
            throw new RuntimeException('Configuration revision has no effective change.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 191) {
            throw new RuntimeException('A bounded revision reason is required.');
        }

        $table = $this->table();
        $parent = (int) $this->db->get_var("SELECT revision_id FROM {$table} WHERE state='active' ORDER BY revision_id DESC LIMIT 1");
        $this->db->query('START TRANSACTION');
        try {
            $this->db->update($table, ['state' => 'retired'], ['revision_id' => $parent, 'state' => 'active'], ['%s'], ['%d', '%s']);
            $this->insert($next, $parent, max(0, $actorId), $reason);
            $this->db->query('COMMIT');
            return $next;
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    /** @return list<array<string, int|string|null>> */
    public function history(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->db->get_results($this->db->prepare("SELECT revision_id,parent_revision_id,state,checksum,actor_id,reason,created_at,activated_at FROM {$this->table()} ORDER BY revision_id DESC LIMIT %d", $limit), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function insert(DesiredConfiguration $configuration, ?int $parent, int $actorId, string $reason): void
    {
        $now = gmdate('Y-m-d H:i:s.u');
        $ok = $this->db->insert($this->table(), [
            'parent_revision_id' => $parent,
            'contract_version' => '1.0',
            'state' => 'active',
            'checksum' => $configuration->checksum(),
            'configuration_json' => CanonicalJson::encode($configuration->toArray()),
            'actor_id' => $actorId,
            'reason' => $reason,
            'created_at' => $now,
            'activated_at' => $now,
        ], ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
        if ($ok !== 1) {
            throw new RuntimeException('Configuration revision insert failed.');
        }
    }

    private function table(): string
    {
        return $this->db->prefix . 'sfs_configuration_revisions';
    }
}
