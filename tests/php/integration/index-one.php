<?php

use Starfiniti\Search\Domain\Catalog\ProductDocumentFactory;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Infrastructure\WordPress\Catalog\WooProductSource;
use Starfiniti\Search\Infrastructure\WordPress\LocalIndex\LocalIndexer;

global $wpdb;
$entityId = (int) (getenv('STARFINITI_TEST_ENTITY_ID') ?: 0);
if ($entityId < 1) {
    throw new RuntimeException('STARFINITI_TEST_ENTITY_ID must be set.');
}

$source = new WooProductSource(new ProductDocumentFactory());
$document = $source->get($entityId);
if ($document === null) {
    throw new RuntimeException('Fixture product was not found.');
}

(new LocalIndexer($wpdb, new Tokenizer()))->upsert($document);
echo $document->id() . ' ' . $document->checksum() . PHP_EOL;
