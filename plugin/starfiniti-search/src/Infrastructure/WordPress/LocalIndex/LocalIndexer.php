<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\LocalIndex;

use Starfiniti\Search\Domain\Catalog\SearchDocument;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Domain\Search\PositionCodec;
use Starfiniti\Search\Domain\Search\RankingProfile;
use Starfiniti\Search\Domain\Support\CanonicalJson;
use wpdb;

final class LocalIndexer
{
    public function __construct(private readonly wpdb $db, private readonly Tokenizer $tokenizer)
    {
    }

    public function upsert(SearchDocument $document, ?int $targetGeneration = null): void
    {
        $data = $document->toArray();
        $generation = $this->generation($targetGeneration);
        $documents = $this->db->prefix . 'sfs_documents';
        $documentTerms = $this->db->prefix . 'sfs_document_terms';
        $postings = $this->db->prefix . 'sfs_postings';
        $terms = $this->db->prefix . 'sfs_terms';
        $facets = $this->db->prefix . 'sfs_facet_values';
        $documentId = $document->id();

        $this->db->query('START TRANSACTION');
        try {
            $existingDocument = $this->db->get_var($this->db->prepare(
                "SELECT document_id FROM {$documents} WHERE generation_id=%d AND document_id=%s FOR UPDATE",
                $generation,
                $documentId
            ));
            $oldTermIds = $this->db->get_col($this->db->prepare("SELECT term_id FROM {$documentTerms} WHERE generation_id=%d AND document_id=%s", $generation, $documentId));
            $this->db->delete($postings, ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            $this->db->delete($documentTerms, ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            $this->db->delete($facets, ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            foreach ($oldTermIds as $oldTermId) {
                $this->db->query($this->db->prepare("UPDATE {$terms} SET document_frequency=GREATEST(0,document_frequency-1) WHERE term_id=%d", $oldTermId));
            }

            $scopeTokens = $data['visibility']['scope_tokens'];
            sort($scopeTokens, SORT_STRING);
            $this->db->replace(
                $documents,
                [
                    'generation_id' => $generation,
                    'document_id' => $documentId,
                    'entity_type' => $data['entity_type'],
                    'entity_id' => $data['entity_id'],
                    'parent_id' => $data['parent_id'],
                    'locale' => $data['locale'],
                    'channel' => $data['channel'],
                    'searchable' => $data['visibility']['search'] && $data['inventory']['searchable'] ? 1 : 0,
                    'catalog_visible' => $data['visibility']['catalog'] ? 1 : 0,
                    'password_protected' => $data['visibility']['password_protected'] ? 1 : 0,
                    'scope_hash' => hash('sha256', implode('|', $scopeTokens)),
                    'sku' => $data['identity']['sku'],
                    'sku_normalized' => $data['identity']['sku'] ? $this->tokenizer->normalizeExact($data['identity']['sku']) : null,
                    'title' => $data['identity']['title'],
                    'title_normalized' => mb_substr($this->tokenizer->normalizePhrase((string) $data['identity']['title'], 64), 0, 512),
                    'search_text' => ' ' . mb_substr($this->tokenizer->normalizePhrase(implode(' ', [
                        (string) $data['identity']['title'],
                        (string) ($data['identity']['sku'] ?? ''),
                        implode(' ', $data['content']['search_keywords']),
                        implode(' ', $data['classification']['category_paths']),
                        (string) $data['content']['short_description_text'],
                        (string) $data['content']['description_text'],
                    ]), 4096), 0, 60000) . ' ',
                    'price_minor' => $data['pricing']['active_min_minor'],
                    'checksum' => $data['checksum'],
                    'document_json' => CanonicalJson::encode($data),
                    'updated_at' => gmdate('Y-m-d H:i:s.u'),
                ]
            );

            $facetValues = [
                'inventory.stock_status' => [$data['inventory']['stock_status']],
                'quality.featured' => [$data['quality']['featured'] ? '1' : '0'],
                'classification.category_ids' => array_map('strval', $data['classification']['category_ids']),
                'classification.category_paths' => $data['classification']['category_paths'],
            ];
            foreach ($data['attributes'] as $attribute => $values) {
                $key = 'attributes.' . sanitize_key((string) $attribute);
                if ($key !== 'attributes.') {
                    $facetValues[$key] = array_map('strval', is_array($values) ? $values : []);
                }
            }
            foreach ($facetValues as $key => $values) {
                foreach (array_values(array_unique($values)) as $value) {
                    $value = mb_substr((string) $value, 0, 191);
                    $this->db->replace($facets, [
                        'generation_id' => $generation,
                        'document_id' => $documentId,
                        'facet_key' => $key,
                        'facet_value' => $value,
                        'facet_normalized' => mb_substr($this->tokenizer->normalizeExact($value), 0, 191),
                        'numeric_value' => is_numeric($value) ? (string) $value : null,
                    ], ['%d', '%s', '%s', '%s', '%s', '%s']);
                }
            }

            $weights = RankingProfile::fieldWeights();
            $fields = [
                1 => [$data['identity']['title'], $weights[1]],
                2 => [implode(' ', array_filter([$data['identity']['sku'], $data['identity']['gtin']])), $weights[2]],
                3 => [implode(' ', $data['content']['search_keywords']), $weights[3]],
                4 => [implode(' ', $data['classification']['category_paths']), $weights[4]],
                5 => [$data['content']['short_description_text'] . ' ' . $data['content']['description_text'], $weights[5]],
            ];
            $seenTerms = [];
            foreach ($fields as $fieldCode => [$text, $weight]) {
                $sequence = $this->tokenizer->sequence((string) $text, 1024);
                $positions = [];
                foreach ($sequence as $position => $token) {
                    $key = 'term:' . $token;
                    $positions[$key]['term'] = $token;
                    $positions[$key]['positions'][] = $position;
                }
                foreach ($positions as $entry) {
                    $token = (string) $entry['term'];
                    $tokenPositions = $entry['positions'];
                    $hash = hash('sha256', $token);
                    $this->db->query(
                        $this->db->prepare(
                            "INSERT INTO {$terms} (generation_id,locale,term,term_hash,document_frequency)
                             VALUES (%d,%s,%s,UNHEX(%s),0)
                             ON DUPLICATE KEY UPDATE term=VALUES(term)",
                            $generation,
                            $data['locale'],
                            $token,
                            $hash
                        )
                    );
                    $termId = (int) $this->db->get_var($this->db->prepare("SELECT term_id FROM {$terms} WHERE generation_id=%d AND locale=%s AND term_hash=UNHEX(%s)", $generation, $data['locale'], $hash));
                    foreach ($this->grams($token) as $gram) {
                        $this->db->query($this->db->prepare(
                            "INSERT IGNORE INTO {$this->db->prefix}sfs_term_ngrams (generation_id,gram,term_id) VALUES (%d,%s,%d)",
                            $generation,
                            $gram,
                            $termId
                        ));
                    }
                    $this->db->replace(
                        $postings,
                        [
                            'generation_id' => $generation,
                            'term_id' => $termId,
                            'document_id' => $documentId,
                            'field_code' => $fieldCode,
                            'term_frequency' => count($tokenPositions),
                            'weight' => $weight,
                            'field_length' => count($sequence),
                            'positions_blob' => PositionCodec::encode($tokenPositions),
                        ],
                        ['%d', '%d', '%s', '%d', '%d', '%f', '%d', '%s']
                    );
                    if (!isset($seenTerms[$termId])) {
                        $this->db->query($this->db->prepare("INSERT IGNORE INTO {$documentTerms} (generation_id,document_id,term_id) VALUES (%d,%s,%d)", $generation, $documentId, $termId));
                        $this->db->query($this->db->prepare("UPDATE {$terms} SET document_frequency=document_frequency+1 WHERE term_id=%d", $termId));
                        $seenTerms[$termId] = true;
                    }
                }
            }

            if (!is_string($existingDocument)) {
                $this->db->query($this->db->prepare(
                    "UPDATE {$this->db->prefix}sfs_index_generations SET document_count=document_count+1 WHERE generation_id=%d",
                    $generation
                ));
            }

            $this->db->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    /** @return list<string> */
    private function grams(string $term): array
    {
        $value = '^' . $term . '$';
        $length = mb_strlen($value);
        $grams = [];
        for ($index = 0; $index <= $length - 3; ++$index) {
            $grams[] = mb_substr($value, $index, 3);
        }
        return array_values(array_unique(array_slice($grams, 0, 64)));
    }

    public function deleteEntity(string $entityType, int $entityId, ?int $targetGeneration = null): void
    {
        $generation = $this->generation($targetGeneration);
        $documents = $this->db->prefix . 'sfs_documents';
        $ids = $this->db->get_col($this->db->prepare("SELECT document_id FROM {$documents} WHERE generation_id=%d AND entity_type=%s AND entity_id=%d", $generation, $entityType, $entityId));
        foreach ($ids as $documentId) {
            $this->deleteDocument($generation, (string) $documentId);
        }
    }

    private function deleteDocument(int $generation, string $documentId): void
    {
        $this->db->query('START TRANSACTION');
        try {
            $documents = $this->db->prefix . 'sfs_documents';
            $existingDocument = $this->db->get_var($this->db->prepare(
                "SELECT document_id FROM {$documents} WHERE generation_id=%d AND document_id=%s FOR UPDATE",
                $generation,
                $documentId
            ));
            if (!is_string($existingDocument)) {
                $this->db->query('COMMIT');
                return;
            }
            $map = $this->db->prefix . 'sfs_document_terms';
            $terms = $this->db->prefix . 'sfs_terms';
            $termIds = $this->db->get_col($this->db->prepare("SELECT term_id FROM {$map} WHERE generation_id=%d AND document_id=%s", $generation, $documentId));
            $this->db->delete($this->db->prefix . 'sfs_postings', ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            $this->db->delete($map, ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            $this->db->delete($this->db->prefix . 'sfs_facet_values', ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            $this->db->delete($documents, ['generation_id' => $generation, 'document_id' => $documentId], ['%d', '%s']);
            if ($this->db->rows_affected === 1) {
                $this->db->query($this->db->prepare(
                    "UPDATE {$this->db->prefix}sfs_index_generations SET document_count=GREATEST(0,document_count-1) WHERE generation_id=%d",
                    $generation
                ));
            }
            foreach ($termIds as $termId) {
                $this->db->query($this->db->prepare("UPDATE {$terms} SET document_frequency=GREATEST(0,document_frequency-1) WHERE term_id=%d", $termId));
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $exception) {
            $this->db->query('ROLLBACK');
            throw $exception;
        }
    }

    private function generation(?int $targetGeneration): int
    {
        $generation = $targetGeneration ?? (int) get_option('starfiniti_search_active_generation');
        if ($generation < 1) {
            throw new \RuntimeException('No active local index generation.');
        }
        return $generation;
    }
}
