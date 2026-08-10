<?php

use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;

global $wpdb;
$outbox = new OutboxRepository($wpdb);
$active = (int) get_option('starfiniti_search_active_generation');
$documentsBefore = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $active));

$outbox->enqueue('product', 10, 'upsert', 90, 'lease-recovery-test', $active);
$first = $outbox->claim(1, 120)[0] ?? null;
if (!is_array($first) || $first['status'] !== 'processing') {
    throw new RuntimeException('Outbox lease test could not claim its event.');
}
$wpdb->update($wpdb->prefix . 'sfs_sync_outbox', ['leased_until' => gmdate('Y-m-d H:i:s.u', time() - 10)], ['event_id' => (int) $first['event_id']], ['%s'], ['%d']);
$second = $outbox->claim(1, 120)[0] ?? null;
if (!is_array($second) || $second['event_id'] !== $first['event_id'] || $second['lease_token'] === $first['lease_token'] || (int) $second['attempts'] !== 2) {
    throw new RuntimeException('Expired outbox lease was not safely reclaimed.');
}
$outbox->complete((int) $first['event_id'], (string) $first['lease_token']);
if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_sync_outbox WHERE event_id=%d", (int) $first['event_id'])) !== 1) {
    throw new RuntimeException('A stale worker completed an event owned by a newer lease.');
}
$outbox->complete((int) $second['event_id'], (string) $second['lease_token']);

$outbox->enqueue('product', 10, 'upsert', 90, 'terminal-retry-test', $active);
for ($expectedAttempt = 1; $expectedAttempt <= 8; ++$expectedAttempt) {
    // Keep the event strictly in the past. PHP's gmdate() emits zero
    // microseconds, so UTC_TIMESTAMP(6) from the same second can otherwise be
    // a few microseconds newer than the repository's comparison timestamp.
    $wpdb->query("UPDATE {$wpdb->prefix}sfs_sync_outbox SET available_at=DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND),leased_until=NULL WHERE locale='terminal-retry-test'");
    $event = $outbox->claim(1, 120)[0] ?? null;
    if (!is_array($event) || (int) $event['attempts'] !== $expectedAttempt) {
        throw new RuntimeException('Outbox retry attempt progression is incorrect.');
    }
    $outbox->fail((int) $event['event_id'], (string) $event['lease_token'], 'injected_failure', (int) $event['attempts']);
}
$failed = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}sfs_sync_outbox WHERE locale='terminal-retry-test'", ARRAY_A);
if (!is_array($failed) || $failed['status'] !== 'failed' || (int) $failed['attempts'] !== 8 || $failed['last_error_code'] !== 'injected_failure') {
    throw new RuntimeException('Outbox did not enter its audited terminal state after eight attempts.');
}
$outbox->enqueue('product', 10, 'upsert', 90, 'terminal-retry-test', $active);
$reset = $outbox->claim(1, 120)[0] ?? null;
if (!is_array($reset) || (int) $reset['attempts'] !== 1 || $reset['last_error_code'] !== null) {
    throw new RuntimeException('Explicit re-enqueue did not safely reset a terminal event.');
}
$outbox->complete((int) $reset['event_id'], (string) $reset['lease_token']);

$documentsAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}sfs_documents WHERE generation_id=%d", $active));
if ($documentsAfter !== $documentsBefore) {
    throw new RuntimeException('Lease/retry injection changed the active generation.');
}
echo "Outbox resilience passed: expired lease recovery, stale-token denial, terminal retry, explicit reset, and active-generation isolation.\n";
