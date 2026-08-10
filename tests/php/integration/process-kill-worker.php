<?php

use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;

$marker = getenv('STARFINITI_PROCESS_KILL_MARKER');
if (!is_string($marker) || $marker === '') {
    throw new RuntimeException('Process-kill marker path is unavailable.');
}
global $wpdb;
$outbox = new OutboxRepository($wpdb);
$active = (int) get_option('starfiniti_search_active_generation');
$outbox->enqueue('product', 10, 'upsert', 0, 'process-kill-test', $active);
$event = $outbox->claim(1, 2)[0] ?? null;
if (!is_array($event) || ($event['locale'] ?? '') !== 'process-kill-test' || ($event['status'] ?? '') !== 'processing') {
    throw new RuntimeException('Process-kill worker did not acquire the isolated lease.');
}
file_put_contents($marker, wp_json_encode(['event_id' => (int) $event['event_id'], 'lease_token' => (string) $event['lease_token'], 'attempts' => (int) $event['attempts']]), LOCK_EX);
sleep(120);
throw new RuntimeException('Process-kill worker was not terminated as required.');
