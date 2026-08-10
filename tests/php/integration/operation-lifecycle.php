<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Infrastructure\WordPress\Analytics\AnalyticsRepository;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\GenerationManager;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationExecutor;
use Starfiniti\Search\Infrastructure\WordPress\Operations\OperationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Outbox\OutboxRepository;
use Starfiniti\Search\Infrastructure\WordPress\Reconciliation\CatalogReconciler;

global $wpdb;
wp_set_current_user(1);
$wpdb->query("DELETE FROM {$wpdb->prefix}sfs_operation_audit");
$wpdb->query("DELETE FROM {$wpdb->prefix}sfs_operation_plans");

$configuration = new ConfigurationRepository($wpdb);
$operations = new OperationRepository($wpdb, $configuration);
$generations = new GenerationManager($wpdb);
$analytics = new AnalyticsRepository($wpdb, $configuration);
$outbox = new OutboxRepository($wpdb);
$reconciler = new CatalogReconciler($wpdb, new WooProductSource(new ProductDocumentFactory()), $outbox);
$executor = new OperationExecutor($operations, $generations, $analytics, $reconciler, $configuration);

$plan = $operations->create('reconciliation.start', 'integration-reconcile-0001', [], 'Integration reconciliation operation', 1);
$repeat = $operations->create('reconciliation.start', 'integration-reconcile-0001', [], 'Integration reconciliation operation', 1);
if ($plan['operation_id'] !== $repeat['operation_id'] || $plan['plan_hash'] !== $repeat['plan_hash']) {
    throw new RuntimeException('Idempotent plan replay did not return the original operation.');
}
try {
    $operations->create('reconciliation.start', 'integration-reconcile-0001', [], 'Changed payload', 1);
    throw new RuntimeException('Changed payload was accepted under an existing idempotency key.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Changed payload was accepted under an existing idempotency key.') {
        throw $exception;
    }
}
$completed = $executor->execute($plan['operation_id'], $plan['plan_hash'], 1);
$completedAgain = $executor->execute($plan['operation_id'], $plan['plan_hash'], 1);
if ($completed['status'] !== 'succeeded' || $completedAgain['operation_id'] !== $completed['operation_id']) {
    throw new RuntimeException('Operation execution was not idempotent.');
}

$purge = $operations->create('analytics.purge', 'integration-purge-000001', [], 'Integration aggregate analytics purge', 1);
try {
    $executor->execute($purge['operation_id'], $purge['plan_hash'], 1);
    throw new RuntimeException('High-impact operation executed without approval.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'High-impact operation executed without approval.') {
        throw $exception;
    }
}
$approved = $operations->approve($purge['operation_id'], $purge['plan_hash'], 1);
if ($approved['status'] !== 'approved' || $approved['approver_id'] !== 1) {
    throw new RuntimeException('Human approval was not bound to the operation.');
}
if ($executor->execute($purge['operation_id'], $purge['plan_hash'], 1)['status'] !== 'succeeded') {
    throw new RuntimeException('Approved analytics purge did not execute.');
}

$originalConfiguration = $configuration->current();
if ($originalConfiguration === null) {
    throw new RuntimeException('Configuration unavailable for controlled apply test.');
}
$draft = $originalConfiguration->toArray();
$draft['operations']['batch_size'] = (int) $draft['operations']['batch_size'] === 47 ? 48 : 47;
$draft['ranking']['synonyms'] = [[
    'id' => 'operation-preview-rule',
    'type' => 'directional',
    'locale' => determine_locale(),
    'channel' => 'storefront',
    'source' => 'operation-preview',
    'targets' => ['alpine'],
]];
$draft['ranking']['stop_words'] = [determine_locale() => ['operationstop']];
$configurationPlan = $operations->create('configuration.apply', 'integration-config-000001', ['configuration' => $draft], 'Integration controlled configuration apply', 1);
$operations->approve($configurationPlan['operation_id'], $configurationPlan['plan_hash'], 1);
$configurationResult = $executor->execute($configurationPlan['operation_id'], $configurationPlan['plan_hash'], 1);
if ($configurationResult['status'] !== 'succeeded' || hash_equals($configuration->current()->semanticChecksum(), $originalConfiguration->semanticChecksum())) {
    throw new RuntimeException('Controlled configuration apply did not create a semantic revision.');
}
$restorePlan = $operations->create('configuration.apply', 'integration-config-restore-0001', ['configuration' => $originalConfiguration->toArray()], 'Integration controlled configuration restore', 1);
$operations->approve($restorePlan['operation_id'], $restorePlan['plan_hash'], 1);
$executor->execute($restorePlan['operation_id'], $restorePlan['plan_hash'], 1);
if (!hash_equals($configuration->current()->semanticChecksum(), $originalConfiguration->semanticChecksum())) {
    throw new RuntimeException('Controlled configuration restore failed.');
}

$unsafeDraft = $originalConfiguration->toArray();
$unsafeDraft['providers']['local']['api_key'] = 'must-never-persist';
try {
    $operations->create('configuration.apply', 'integration-config-unsafe-01', ['configuration' => $unsafeDraft], 'Reject embedded secret', 1);
    throw new RuntimeException('Controlled configuration plan accepted an embedded secret.');
} catch (InvalidArgumentException|RuntimeException $exception) {
    if ($exception->getMessage() === 'Controlled configuration plan accepted an embedded secret.') {
        throw $exception;
    }
}

$stale = $operations->create('index.build', 'integration-stale-000001', [], 'Integration stale precondition check', 1);
$active = (int) get_option('starfiniti_search_active_generation');
update_option('starfiniti_search_active_generation', $active + 12345, false);
try {
    $executor->execute($stale['operation_id'], $stale['plan_hash'], 1);
    throw new RuntimeException('Changed-state precondition did not block execution.');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'Changed-state precondition did not block execution.') {
        update_option('starfiniti_search_active_generation', $active, false);
        throw $exception;
    }
} finally {
    update_option('starfiniti_search_active_generation', $active, false);
}
if ($operations->get($stale['operation_id'])['status'] !== 'failed') {
    throw new RuntimeException('Precondition failure was not persisted.');
}

$audit = $operations->auditHistory(100);
if (count($audit) < 8 || array_filter($audit, static fn (array $row): bool => empty($row['correlation_id']) || empty($row['result_code']))) {
    throw new RuntimeException('Operation audit records are incomplete.');
}
$columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}sfs_operation_audit", 0);
foreach ($columns as $column) {
    if (preg_match('/token|secret|password|raw_query/i', (string) $column) === 1) {
        throw new RuntimeException('Operation audit schema contains a forbidden secret-bearing column.');
    }
}

echo "Operation lifecycle passed: immutable plan, replay safety, approval, typed execution, preconditions, and safe audit.\n";
