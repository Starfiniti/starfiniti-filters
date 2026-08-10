<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Migration;

use wpdb;

/** Read-only compatibility inventory. This class never loads or mutates the legacy plugin. */
final class LegacyFiboFiltersReader
{
    public function __construct(private readonly wpdb $db)
    {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        $table = $this->db->prefix . 'fibofilters_storage';
        $exists = (string) $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table))) === $table;
        $version = sanitize_text_field((string) get_option('fibofilters_version_pro', get_option('fibofilters_version', '')));
        $filters = [];
        if ($exists) {
            $raw = $this->db->get_var($this->db->prepare("SELECT content FROM {$table} WHERE name=%s AND lang=%s", 'filters', ''));
            $decoded = is_string($raw) ? json_decode($raw, true, 32) : null;
            if (is_array($decoded)) {
                $filters = array_slice(array_values($decoded), 0, 500);
            }
        }
        return [
            'detected' => $exists || $version !== '',
            'version' => $version !== '' ? $version : null,
            'storage_detected' => $exists,
            'filter_definition_count' => count($filters),
            'mode' => 'read_only_inventory',
            'processed_index_imported' => false,
        ];
    }
}
