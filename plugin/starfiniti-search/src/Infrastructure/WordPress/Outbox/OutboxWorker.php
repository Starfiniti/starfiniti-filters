<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Outbox;

use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;
use Starfiniti\Search\Infrastructure\WordPress\Operations\StructuredLogger;
use Starfiniti\Search\Infrastructure\WordPress\Operations\AdaptiveBatchSizer;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use Starfiniti\Search\Infrastructure\WordPress\Scheduling\ActionSchedulerQueue;

final class OutboxWorker
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly WooProductSource $source,
        private readonly LocalIndexer $indexer,
        private readonly ?StructuredLogger $logger = null,
        private readonly ?ConfigurationRepository $configuration = null
    ) {
    }

    public function run(string $continuationToken = ''): void
    {
        $configured = (int) ($this->configuration?->current()?->toArray()['operations']['batch_size'] ?? 20);
        foreach ($this->outbox->claim(AdaptiveBatchSizer::fromRuntime($configured)) as $event) {
            try {
                $id = (int) $event['aggregate_id'];
                $generation = (int) $event['target_generation'];
                if ($event['operation'] === 'delete') {
                    $this->indexer->deleteEntity((string) $event['aggregate_type'], $id, $generation);
                } else {
                    $document = $this->source->get($id);
                    if ($document === null) {
                        $this->indexer->deleteEntity((string) $event['aggregate_type'], $id, $generation);
                    } else {
                        $this->indexer->upsert($document, $generation);
                    }
                }
                $this->outbox->complete((int) $event['event_id'], (string) $event['lease_token']);
            } catch (\Throwable $exception) {
                $code = $this->errorCode($exception);
                $this->outbox->fail(
                    (int) $event['event_id'],
                    (string) $event['lease_token'],
                    $code,
                    (int) $event['attempts']
                );
                $this->logger?->log('error', 'outbox.index_failed', 'Catalog indexing event failed.', [
                    'index_version' => (int) $event['target_generation'],
                    'retryable' => (int) $event['attempts'] < 5,
                    'safe_context' => ['event_id' => (int) $event['event_id'], 'error_code' => $code],
                ]);
            }
        }

        if ($this->outbox->hasReadyOrPending()) {
            ActionSchedulerQueue::scheduleTokenizedSuccessor('starfiniti_search_process_outbox', 5);
        }
    }

    private function errorCode(\Throwable $exception): string
    {
        return 'index_' . substr(hash('sha256', $exception::class . ':' . $exception->getCode()), 0, 16);
    }
}
