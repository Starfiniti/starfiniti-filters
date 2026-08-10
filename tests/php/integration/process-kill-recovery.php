<?php

use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;

$marker = getenv('STARFINITI_PROCESS_KILL_MARKER');
if (!is_string($marker) || !is_readable($marker)) {
    throw new RuntimeException('Process-kill recovery marker is unavailable.');
}
$original = json_decode((string) file_get_contents($marker), true, 8, JSON_THROW_ON_ERROR);
global $wpdb;
$active = (int) get_option('starfiniti_search_active_generation');
$documentsBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $active));
$row = $wpdb->get_row("SELECT status,attempts,lease_token,leased_until FROM {$wpdb->prefix}sfs_sync_outbox WHERE locale='process-kill-test'", ARRAY_A);
if (!is_array($row) || $row['status'] !== 'processing' || (int) $row['attempts'] !== 1 || $row['lease_token'] !== ($original['lease_token'] ?? null)) {
    throw new RuntimeException('Killed worker lease evidence was not preserved.');
}
$outbox = new OutboxRepository($wpdb);
$reclaimed = $outbox->claim(1, 120)[0] ?? null;
if (!is_array($reclaimed) || ($reclaimed['locale'] ?? '') !== 'process-kill-test' || (int) $reclaimed['event_id'] !== (int) ($original['event_id'] ?? 0) || (int) $reclaimed['attempts'] !== 2 || $reclaimed['lease_token'] === ($original['lease_token'] ?? null)) {
    throw new RuntimeException('Killed worker lease was not safely reclaimed with new ownership.');
}
$outbox->complete((int) $reclaimed['event_id'], (string) $reclaimed['lease_token']);
if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_sync_outbox WHERE locale='process-kill-test'") !== 0) {
    throw new RuntimeException('Reclaimed process-kill event was not completed exactly once.');
}
$documentsAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $active));
if ($documentsAfter !== $documentsBefore) {
    throw new RuntimeException('Process-kill recovery changed the active generation.');
}
echo "Process-kill recovery passed: abrupt worker death preserved lease evidence, reclaimed with attempt two, and completed exactly once.\n";
