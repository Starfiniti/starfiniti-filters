<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\LocalIndex;

use InvalidArgumentException;
use Starfiniti\Search\Domain\Provider\SearchProvider;
use Starfiniti\Search\Domain\Search\Tokenizer;
use Starfiniti\Search\Domain\Search\EditDistance;
use Starfiniti\Search\Domain\Search\RankingProfile;
use Starfiniti\Search\Domain\Search\RelevancePolicy;
use Starfiniti\Search\Infrastructure\WordPress\Configuration\ConfigurationRepository;
use wpdb;

final class LocalSearchProvider implements SearchProvider
{
    public function __construct(
        private readonly wpdb $db,
        private readonly Tokenizer $tokenizer,
        private readonly ?ConfigurationRepository $configuration = null
    )
    {
    }

    public function id(): string
    {
        return 'local';
    }

    public function capabilities(): array
    {
        $facetReady = (int) get_option('starfiniti_search_active_index_schema') >= 2;
        $relevanceReady = (int) get_option('starfiniti_search_active_index_schema') >= 3;
        return [
            'contract_version' => '1.0',
            'provider_id' => 'local',
            'adapter_version' => defined('STARFINITI_SEARCH_VERSION') ? STARFINITI_SEARCH_VERSION : 'dev',
            'capabilities' => [
                'autocomplete' => ['state' => 'native', 'notes' => ['Bounded candidate ranking with exact-SKU inclusion.']],
                'facets' => ['state' => $facetReady ? 'native' : 'unsupported', 'notes' => $facetReady ? [] : ['Activate an index schema v2 generation.']],
                'filters' => ['state' => $facetReady ? 'native' : 'unsupported', 'notes' => $facetReady ? [] : ['Activate an index schema v2 generation.']],
                'phrase_matching' => ['state' => $relevanceReady ? 'native' : 'unsupported', 'notes' => $relevanceReady ? ['Quoted phrases are enforced against normalized indexed text.'] : ['Activate an index schema v3 generation.']],
                'highlighting' => ['state' => 'native', 'notes' => ['Safe text-segment highlights; no provider HTML.']],
                'typo_tolerance' => ['state' => $relevanceReady ? 'native' : 'unsupported', 'notes' => $relevanceReady ? ['Trigram vocabulary candidates, Unicode Damerau-Levenshtein, maximum 16 expansions.'] : ['Activate an index schema v3 generation.']],
                'synonyms' => ['state' => 'native', 'notes' => ['Immutable query-time equivalent and directional rules; maximum 16 expansions.']],
                'stop_words' => ['state' => 'native', 'notes' => ['Immutable locale-specific lists with safe no-result handling.']],
                'curations' => ['state' => 'native', 'notes' => ['Exact-query, scoped and effective-dated pin, boost, bury, hide, and internal redirect rules with deterministic priority.']],
                'explanations' => ['state' => 'native', 'notes' => ['Administrator-only normalized plan and score-policy explanation.']],
                'server_side_visibility' => ['state' => 'native', 'notes' => []],
                'sorting' => ['state' => 'native', 'notes' => ['Title and active price are supported.']],
            ],
            'limits' => ['max_page_size' => 100, 'max_facets' => 16, 'max_filter_nodes' => 32, 'max_query_length' => 512, 'autocomplete_candidate_limit' => 250, 'max_fuzzy_expansions' => 16, 'max_synonym_expansions' => 16],
        ];
    }

    public function search(array $request): array
    {
        $started = hrtime(true);
        $query = mb_substr(trim((string) ($request['query'] ?? '')), 0, 512);
        $page = max(1, min(10000, (int) ($request['page']['number'] ?? 1)));
        $size = max(1, min(100, (int) ($request['page']['size'] ?? 10)));
        $locale = mb_substr((string) ($request['context']['locale'] ?? determine_locale()), 0, 32);
        $channel = mb_substr((string) ($request['context']['channel'] ?? 'storefront'), 0, 64);
        $rankingOverride = $request['options']['ranking_override'] ?? null;
        $ranking = is_array($rankingOverride) ? $rankingOverride : ($this->configuration?->current()?->toArray()['ranking'] ?? RelevancePolicy::defaults());
        $policy = RelevancePolicy::fromArray(is_array($ranking) ? $ranking : RelevancePolicy::defaults(), $this->tokenizer);
        $curation = $policy->curation($query, $locale, $channel);
        $effectiveQuery = $curation['rewrite']['query'] ?? $query;
        $analysis = $policy->analyze($effectiveQuery, $locale, $channel);
        $tokens = $analysis['tokens'];
        $synonymTerms = $analysis['synonym_terms'];
        $generation = (int) get_option('starfiniti_search_active_generation');
        $queryId = wp_generate_uuid4();
        if ((int) get_option('starfiniti_search_active_index_schema') < 2 && (($request['filters'] ?? null) !== null || ($request['facets'] ?? []) !== [])) {
            throw new InvalidArgumentException('The active generation does not support facets; activate a schema-v2 shadow generation.');
        }
        if ($generation < 1) {
            return $this->response($generation, $queryId, [], [], 0, $page, $size, $started, [], $curation['redirect']);
        }
        if ($effectiveQuery !== '' && $tokens === []) {
            $warning = $analysis['all_stop_words'] ? 'all_stop_words_safe_no_result' : 'unsearchable_query_safe_no_result';
            return $this->response($generation, $queryId, [], [], 0, $page, $size, $started, [$warning], $curation['redirect']);
        }

        $documents = $this->db->prefix . 'sfs_documents';
        $terms = $this->db->prefix . 'sfs_terms';
        $postings = $this->db->prefix . 'sfs_postings';
        $from = "{$documents} d";
        $baseWhere = 'd.generation_id=%d AND d.searchable=1 AND d.password_protected=0 AND d.locale=%s AND d.channel=%s AND d.scope_hash=%s';
        $baseParams = [$generation, $locale, $channel, hash('sha256', 'public')];
        if ($curation['hidden'] !== []) {
            $baseWhere .= ' AND d.entity_id NOT IN (' . implode(',', array_fill(0, count($curation['hidden']), '%d')) . ')';
            $baseParams = [...$baseParams, ...$curation['hidden']];
        }
        $where = $baseWhere;
        $params = $baseParams;
        $score = '0';
        $grouped = false;

        if ($tokens !== []) {
            $from = "{$postings} p INNER JOIN {$terms} t ON t.term_id=p.term_id AND t.generation_id=p.generation_id INNER JOIN {$documents} d ON d.document_id=p.document_id AND d.generation_id=p.generation_id";
            $clauses = [];
            foreach ($tokens as $token) {
                $clauses[] = 't.term LIKE %s';
                $params[] = $this->db->esc_like($token) . '%';
            }
            foreach (array_keys($synonymTerms) as $term) {
                $clauses[] = 't.term=%s';
                $params[] = $term;
            }
            $fuzzyTerms = (int) get_option('starfiniti_search_active_index_schema') >= 3 ? $this->fuzzyExpansions($tokens, $generation, $locale) : [];
            foreach (array_keys($fuzzyTerms) as $term) {
                $clauses[] = 't.term=%s';
                $params[] = $term;
            }
            $where .= ' AND (' . implode(' OR ', $clauses) . ')';
            $grouped = true;
        } else {
            $fuzzyTerms = [];
        }

        $quotedPhrases = $this->quotedPhrases($effectiveQuery);
        foreach ($quotedPhrases as $phrase) {
            $where .= ' AND d.search_text LIKE %s';
            $params[] = '% ' . $this->db->esc_like($phrase) . ' %';
        }

        $budget = 32;
        $requestFilter = $request['filters'] ?? null;
        if ($curation['filter'] !== null) {
            $requestFilter = $requestFilter === null ? $curation['filter']['filter'] : ['and' => [$curation['filter']['filter'], $requestFilter]];
        }
        [$filterSql, $filterParams] = $this->compileFilter($requestFilter, $generation, $budget, 0);
        if ($filterSql !== '') {
            $where .= ' AND ' . $filterSql;
            $params = [...$params, ...$filterParams];
        }
        $pinnedWhere = $baseWhere . ($filterSql !== '' ? ' AND ' . $filterSql : '');
        $pinnedParams = [...$baseParams, ...$filterParams];

        $mode = (string) ($request['options']['suggestion_mode'] ?? 'full_results');
        $autocomplete = $grouped && $mode === 'autocomplete' && ($request['facets'] ?? []) === [];
        $candidateLimit = $autocomplete ? 250 : 5000;
        if ($autocomplete) {
            $total = 0;
            $totalIsLowerBound = false;
        } else {
            $total = (int) $this->db->get_var($this->db->prepare("SELECT COUNT(DISTINCT d.document_id) FROM {$from} WHERE {$where}", ...$params));
            $totalIsLowerBound = false;
        }
        $offset = ($page - 1) * $size;
        $hasCuration = $curation['entity_actions'] !== [];
        if ($hasCuration && $offset + $size + count($curation['pins']) > $candidateLimit) {
            throw new InvalidArgumentException('Curated result pagination exceeds the bounded candidate window.');
        }
        $queryLimit = $hasCuration ? min($candidateLimit, $offset + $size + count($curation['pins'])) : $size;
        $queryOffset = $hasCuration ? 0 : $offset;
        $selectParams = $params;
        $order = $this->orderClause($request['sort'] ?? [], $grouped);
        if ($grouped) {
            $sku = $this->tokenizer->normalizeExact($effectiveQuery);
            $normalizedQuery = $this->tokenizer->normalizePhrase(trim($effectiveQuery, " \t\n\r\0\x0B\""), 16);
            $phrasePattern = '% ' . $this->db->esc_like($normalizedQuery) . ' %';
            $fuzzyList = array_keys($fuzzyTerms);
            $synonymList = array_keys($synonymTerms);
            $weightExpression = 'p.weight';
            $weightParams = [];
            $weightCases = [];
            if ($fuzzyList !== []) {
                $weightCases[] = 'WHEN t.term IN (' . implode(',', array_fill(0, count($fuzzyList), '%s')) . ') THEN ' . RankingProfile::FUZZY_WEIGHT_FACTOR;
                $weightParams = [...$weightParams, ...$fuzzyList];
            }
            if ($synonymList !== []) {
                $weightCases[] = 'WHEN t.term IN (' . implode(',', array_fill(0, count($synonymList), '%s')) . ') THEN ' . RelevancePolicy::SYNONYM_WEIGHT_FACTOR;
                $weightParams = [...$weightParams, ...$synonymList];
            }
            if ($weightCases !== []) {
                $weightExpression = 'p.weight * CASE ' . implode(' ', $weightCases) . ' ELSE 1 END';
            }
            $candidateFrom = "{$terms} t STRAIGHT_JOIN {$postings} p FORCE INDEX (PRIMARY) ON p.term_id=t.term_id AND p.generation_id=t.generation_id STRAIGHT_JOIN {$documents} d ON d.document_id=p.document_id AND d.generation_id=p.generation_id";
            $candidate = "SELECT p.document_id,{$weightExpression} AS weight,d.sku_normalized,d.title_normalized,d.search_text FROM {$candidateFrom} WHERE {$where} LIMIT {$candidateLimit}";
            $exactCandidate = "SELECT d.document_id," . RankingProfile::EXACT_IDENTIFIER_BOOST . " AS weight,d.sku_normalized,d.title_normalized,d.search_text FROM {$documents} d WHERE {$pinnedWhere} AND d.sku_normalized=%s LIMIT 1";
            $ranked = "SELECT c.document_id,SUM(c.weight) + IF(MAX(c.sku_normalized)=%s," . RankingProfile::EXACT_IDENTIFIER_BOOST . ",0) + IF(MAX(c.title_normalized)=%s," . RankingProfile::EXACT_TITLE_BOOST . ",0) + IF(MAX(c.search_text) LIKE %s," . RankingProfile::PHRASE_BOOST . ",0) AS raw_score FROM (({$candidate}) UNION ALL ({$exactCandidate})) c GROUP BY c.document_id";
            $boundedTotal = $autocomplete ? ',COUNT(*) OVER() AS bounded_total' : '';
            $sql = "SELECT d.document_id,d.entity_type,d.entity_id,d.parent_id,d.document_json,r.raw_score{$boundedTotal} FROM ({$ranked}) r INNER JOIN {$documents} d ON d.generation_id=%d AND d.document_id=r.document_id ORDER BY {$order} LIMIT %d OFFSET %d";
            $selectParams = [$sku, $normalizedQuery, $phrasePattern, ...$weightParams, ...$params, ...$pinnedParams, $sku];
            $selectParams[] = $generation;
        } else {
            $sql = "SELECT d.document_id,d.entity_type,d.entity_id,d.parent_id,d.document_json,0 AS raw_score FROM {$from} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d";
        }
        $selectParams[] = $queryLimit;
        $selectParams[] = $queryOffset;
        $rows = $this->db->get_results($this->db->prepare($sql, ...$selectParams), ARRAY_A);
        if ($autocomplete) {
            $total = isset($rows[0]['bounded_total']) ? (int) $rows[0]['bounded_total'] : 0;
            $totalIsLowerBound = $total >= $candidateLimit;
        }

        $visiblePinnedIds = [];
        if ($curation['pins'] !== []) {
            $pinPlaceholders = implode(',', array_fill(0, count($curation['pins']), '%d'));
            $pinnedSql = "SELECT d.document_id,d.entity_type,d.entity_id,d.parent_id,d.document_json,0 AS raw_score FROM {$documents} d WHERE {$pinnedWhere} AND d.entity_id IN ({$pinPlaceholders})";
            $pinnedRows = $this->db->get_results($this->db->prepare($pinnedSql, ...$pinnedParams, ...$curation['pins']), ARRAY_A);
            $existing = [];
            foreach ($rows as $row) {
                $existing[(string) $row['document_id']] = true;
            }
            $visiblePinnedSet = [];
            foreach (is_array($pinnedRows) ? $pinnedRows : [] as $row) {
                $visiblePinnedSet[(int) $row['entity_id']] = true;
                if (!isset($existing[(string) $row['document_id']])) {
                    $rows[] = $row;
                    $existing[(string) $row['document_id']] = true;
                }
            }
            $visiblePinnedIds = array_values(array_filter($curation['pins'], static fn (int $entityId): bool => isset($visiblePinnedSet[$entityId])));
            if ($visiblePinnedIds !== []) {
                $matchPlaceholders = implode(',', array_fill(0, count($visiblePinnedIds), '%d'));
                $matchedPins = (int) $this->db->get_var($this->db->prepare(
                    "SELECT COUNT(DISTINCT d.entity_id) FROM {$from} WHERE {$where} AND d.entity_id IN ({$matchPlaceholders})",
                    ...$params,
                    ...$visiblePinnedIds
                ));
                $total += max(0, count($visiblePinnedIds) - $matchedPins);
            }
        }

        foreach ($rows as $index => &$row) {
            $row['_source_order'] = $index;
            $row['_text_raw_score'] = (float) $row['raw_score'];
            $action = $curation['entity_actions'][(int) $row['entity_id']] ?? null;
            if (is_array($action) && in_array($action['type'], ['boost', 'bury'], true)) {
                $row['raw_score'] = (float) $row['raw_score'] + (int) $action['value'];
            }
        }
        unset($row);
        if ($hasCuration) {
            $pinOrder = array_flip($visiblePinnedIds);
            $firstSort = is_array($request['sort'] ?? null) && isset($request['sort'][0]) && is_array($request['sort'][0]) ? $request['sort'][0] : [];
            $relevanceSort = !isset($firstSort['field']) || $firstSort['field'] === '_relevance';
            usort($rows, static function (array $left, array $right) use ($pinOrder, $curation, $relevanceSort): int {
                $leftId = (int) $left['entity_id'];
                $rightId = (int) $right['entity_id'];
                $leftPin = $pinOrder[$leftId] ?? null;
                $rightPin = $pinOrder[$rightId] ?? null;
                if ($leftPin !== null || $rightPin !== null) {
                    if ($leftPin === null) return 1;
                    if ($rightPin === null) return -1;
                    return $leftPin <=> $rightPin;
                }
                $leftAdjusted = isset($curation['adjustments'][$leftId]);
                $rightAdjusted = isset($curation['adjustments'][$rightId]);
                if ($relevanceSort || $leftAdjusted || $rightAdjusted) {
                    $score = (float) $right['raw_score'] <=> (float) $left['raw_score'];
                    if ($score !== 0) return $score;
                }
                return (int) $left['_source_order'] <=> (int) $right['_source_order'];
            });
            $rows = array_slice($rows, $offset, $size);
        }

        $maxScore = 0.0;
        foreach ($rows as $row) {
            $maxScore = max($maxScore, max(0.0, (float) $row['raw_score']));
        }
        $visiblePinnedSet = array_fill_keys($visiblePinnedIds, true);
        $pageHasPinned = false;
        if ($visiblePinnedIds !== [] && $rows !== []) {
            foreach ($rows as &$row) {
                if (isset($visiblePinnedSet[(int) $row['entity_id']])) {
                    $row['raw_score'] = $maxScore + 1.0;
                    $pageHasPinned = true;
                }
            }
            unset($row);
            if ($pageHasPinned) {
                $maxScore += 1.0;
            }
        }
        $hits = [];
        foreach ($rows as $index => $row) {
            $document = json_decode((string) $row['document_json'], true, 64, JSON_THROW_ON_ERROR);
            $hit = [
                'document_id' => $row['document_id'],
                'entity_type' => $row['entity_type'],
                'entity_id' => (int) $row['entity_id'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'score' => $maxScore > 0 ? round(max(0.0, (float) $row['raw_score']) / $maxScore, 6) : 0.0,
                'rank' => $offset + $index + 1,
                'highlights' => ($request['options']['highlight'] ?? false) === true ? $this->highlights($document, $effectiveQuery) : (object) [],
                'matched_fields' => $this->matchedFields($document, array_values(array_unique([...$tokens, ...array_keys($synonymTerms), ...array_keys($fuzzyTerms)])), $effectiveQuery),
                'projection' => [
                    'identity' => $document['identity'],
                    'content' => $document['content'],
                    'classification' => $document['classification'],
                    'attributes' => $document['attributes'],
                    'pricing' => $document['pricing'],
                    'inventory' => $document['inventory'],
                    'media' => $document['media'],
                    'quality' => $document['quality'],
                ],
            ];
            if (($request['options']['include_explanation'] ?? false) === true && ($request['options']['suggestion_mode'] ?? '') === 'admin_test') {
                $hit['explanation'] = [
                    'original_query' => $query,
                    'normalized_query' => $this->tokenizer->normalizePhrase($effectiveQuery, 16),
                    'recognized_identifier' => $this->tokenizer->normalizeExact($effectiveQuery),
                    'quoted_phrases' => $quotedPhrases,
                    'stop_words_removed' => $analysis['stop_words_removed'],
                    'synonym_expansions' => $synonymTerms,
                    'fuzzy_expansions' => $fuzzyTerms,
                    'matched_fields' => $hit['matched_fields'],
                    'raw_score' => round((float) $row['raw_score'], 4),
                    'text_raw_score' => round((float) ($row['_text_raw_score'] ?? $row['raw_score']), 4),
                    'curation_effect' => $curation['entity_actions'][(int) $row['entity_id']] ?? null,
                    'curation_rules' => $curation['rule_ids'],
                    'curation_rewrite' => $curation['rewrite'],
                    'curation_filter' => $curation['filter'] !== null ? ['rule_id' => $curation['filter']['rule_id'], 'applied' => true] : null,
                    'ranking_profile' => RankingProfile::describe(),
                    'filters_applied' => $requestFilter !== null,
                ];
            }
            $hits[] = $hit;
        }

        $facetKeys = $this->facetKeys($request['facets'] ?? []);
        $facets = $this->facetCounts($facetKeys, $from, $where, $params, $generation, $documents, $pinnedWhere, $pinnedParams, $visiblePinnedIds);
        $warnings = [];
        if ($totalIsLowerBound) {
            $warnings[] = 'total_is_lower_bound:' . $candidateLimit;
        }
        if ($grouped && ($totalIsLowerBound || $total > $candidateLimit)) {
            $warnings[] = 'candidate_limit_applied:' . $candidateLimit;
        }
        if ($fuzzyTerms !== []) {
            $warnings[] = 'fuzzy_expansions_applied:' . count($fuzzyTerms);
        }
        if ($synonymTerms !== []) {
            $warnings[] = 'synonym_expansions_applied:' . count($synonymTerms);
        }
        if ($analysis['stop_words_removed'] !== []) {
            $warnings[] = 'stop_words_removed:' . count($analysis['stop_words_removed']);
        }
        if ($curation['rule_ids'] !== []) {
            $warnings[] = 'curations_applied:' . count($curation['rule_ids']);
        }
        if ($curation['rewrite'] !== null) {
            $warnings[] = 'query_rewritten:' . $curation['rewrite']['rule_id'];
        }
        if ($curation['filter'] !== null) {
            $warnings[] = 'curation_filter_applied:' . $curation['filter']['rule_id'];
        }
        if ($visiblePinnedIds !== []) {
            $warnings[] = 'pinned_results_applied:' . count($visiblePinnedIds);
        }
        return $this->response($generation, $queryId, $hits, $facets, $total, $page, $size, $started, $warnings, $curation['redirect']);
    }

    /** @param mixed $filter @return array{0:string,1:list<mixed>} */
    private function compileFilter(mixed $filter, int $generation, int &$budget, int $depth): array
    {
        if ($filter === null) {
            return ['', []];
        }
        if (!is_array($filter) || $depth > 4 || --$budget < 0) {
            throw new InvalidArgumentException('Filter exceeds the supported complexity budget.');
        }
        foreach (['and' => 'AND', 'or' => 'OR'] as $key => $operator) {
            if (isset($filter[$key])) {
                if (!is_array($filter[$key]) || $filter[$key] === []) {
                    throw new InvalidArgumentException('Filter group must not be empty.');
                }
                $parts = [];
                $params = [];
                foreach (array_slice($filter[$key], 0, 32) as $child) {
                    [$sql, $childParams] = $this->compileFilter($child, $generation, $budget, $depth + 1);
                    $parts[] = '(' . $sql . ')';
                    $params = [...$params, ...$childParams];
                }
                return [implode(" {$operator} ", $parts), $params];
            }
        }
        if (isset($filter['not'])) {
            [$sql, $params] = $this->compileFilter($filter['not'], $generation, $budget, $depth + 1);
            return ['NOT (' . $sql . ')', $params];
        }

        $field = (string) ($filter['field'] ?? '');
        $op = (string) ($filter['op'] ?? '');
        $value = $filter['value'] ?? null;
        if ($field === 'pricing.active_min_minor') {
            return $this->numericFilter('d.price_minor', $op, $value);
        }
        if ($field === 'identity.sku') {
            return $this->scalarFilter('d.sku_normalized', $op, $value, fn (mixed $item): string => $this->tokenizer->normalizeExact((string) $item));
        }
        if (!$this->isFacetKey($field)) {
            throw new InvalidArgumentException('Unsupported filter field.');
        }
        $facets = $this->db->prefix . 'sfs_facet_values';
        $values = is_array($value) ? array_slice(array_values($value), 0, 32) : [$value];
        $values = array_map(fn (mixed $item): string => mb_substr($this->tokenizer->normalizeExact((string) $item), 0, 191), $values);
        if ($op === 'exists') {
            return ["EXISTS (SELECT 1 FROM {$facets} fx WHERE fx.generation_id=%d AND fx.document_id=d.document_id AND fx.facet_key=%s)", [$generation, $field]];
        }
        if (!in_array($op, ['eq', 'neq', 'in', 'not_in'], true) || $values === []) {
            throw new InvalidArgumentException('Unsupported facet filter operation.');
        }
        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $predicate = "EXISTS (SELECT 1 FROM {$facets} fx WHERE fx.generation_id=%d AND fx.document_id=d.document_id AND fx.facet_key=%s AND fx.facet_normalized IN ({$placeholders}))";
        return [in_array($op, ['neq', 'not_in'], true) ? "NOT {$predicate}" : $predicate, [$generation, $field, ...$values]];
    }

    /** @return array{0:string,1:list<mixed>} */
    private function numericFilter(string $column, string $op, mixed $value): array
    {
        if ($op === 'between' && is_array($value) && count($value) === 2) {
            return ["{$column} BETWEEN %f AND %f", [(float) $value[0], (float) $value[1]]];
        }
        $operators = ['eq' => '=', 'neq' => '<>', 'lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='];
        if (!isset($operators[$op]) || !is_numeric($value)) {
            throw new InvalidArgumentException('Unsupported numeric filter.');
        }
        return ["{$column} {$operators[$op]} %f", [(float) $value]];
    }

    /** @param callable(mixed):string $normalize @return array{0:string,1:list<mixed>} */
    private function scalarFilter(string $column, string $op, mixed $value, callable $normalize): array
    {
        $values = is_array($value) ? array_slice(array_values($value), 0, 32) : [$value];
        if (!in_array($op, ['eq', 'neq', 'in', 'not_in'], true) || $values === []) {
            throw new InvalidArgumentException('Unsupported scalar filter.');
        }
        $values = array_map($normalize, $values);
        $predicate = $column . ' IN (' . implode(',', array_fill(0, count($values), '%s')) . ')';
        return [in_array($op, ['neq', 'not_in'], true) ? "NOT ({$predicate})" : $predicate, $values];
    }

    /** @param mixed $sort */
    private function orderClause(mixed $sort, bool $grouped): string
    {
        $first = is_array($sort) && isset($sort[0]) && is_array($sort[0]) ? $sort[0] : null;
        $direction = ($first['direction'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        return match ($first['field'] ?? '') {
            'pricing.active_min_minor' => "d.price_minor {$direction},d.entity_id ASC",
            'identity.title' => "d.title {$direction},d.entity_id ASC",
            default => $grouped ? 'raw_score DESC,d.entity_id ASC' : 'd.entity_id ASC',
        };
    }

    /** @param mixed $requested @return list<string> */
    private function facetKeys(mixed $requested): array
    {
        if (!is_array($requested)) {
            throw new InvalidArgumentException('Facets must be an array.');
        }
        return array_slice(array_values(array_unique(array_filter(array_map('strval', $requested), [$this, 'isFacetKey']))), 0, 16);
    }

    private function isFacetKey(string $field): bool
    {
        return in_array($field, ['inventory.stock_status', 'quality.featured', 'classification.category_ids', 'classification.category_paths'], true)
            || preg_match('/^attributes\.[a-z0-9_-]{1,120}$/', $field) === 1;
    }

    /** @param list<string> $tokens @return array<string,int> */
    private function fuzzyExpansions(array $tokens, int $generation, string $locale): array
    {
        $expansions = [];
        $table = $this->db->prefix . 'sfs_term_ngrams';
        $terms = $this->db->prefix . 'sfs_terms';
        foreach ($tokens as $token) {
            $length = mb_strlen($token);
            if ($length < 3 || preg_match('/\d/u', $token) === 1 || count($expansions) >= 16) {
                continue;
            }
            $maximum = $length <= 5 ? 1 : 2;
            $grams = $this->grams($token);
            if ($grams === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($grams), '%s'));
            $sql = "SELECT t.term,COUNT(*) AS overlap FROM {$table} ng INNER JOIN {$terms} t ON t.generation_id=ng.generation_id AND t.term_id=ng.term_id WHERE ng.generation_id=%d AND t.locale=%s AND t.document_frequency>0 AND ng.gram IN ({$placeholders}) GROUP BY t.term_id,t.term,t.document_frequency ORDER BY overlap DESC,t.document_frequency DESC,t.term ASC LIMIT 32";
            $rows = $this->db->get_results($this->db->prepare($sql, $generation, $locale, ...$grams), ARRAY_A);
            foreach (is_array($rows) ? $rows : [] as $row) {
                $candidate = (string) $row['term'];
                if ($candidate === $token || str_starts_with($candidate, $token)) {
                    continue;
                }
                $distance = EditDistance::damerauLevenshtein($token, $candidate, $maximum);
                if ($distance === null || $distance === 0) {
                    continue;
                }
                $expansions[$candidate] = min($expansions[$candidate] ?? 99, $distance);
                if (count($expansions) >= 16) {
                    break 2;
                }
            }
        }
        asort($expansions, SORT_NUMERIC);
        return array_slice($expansions, 0, 16, true);
    }

    /** @return list<string> */
    private function grams(string $term): array
    {
        $value = '^' . $term . '$';
        $grams = [];
        for ($index = 0, $length = mb_strlen($value); $index <= $length - 3; ++$index) {
            $grams[] = mb_substr($value, $index, 3);
        }
        return array_values(array_unique(array_slice($grams, 0, 64)));
    }

    /** @return list<string> */
    private function quotedPhrases(string $query): array
    {
        preg_match_all('/"([^"\r\n]{1,256})"/u', $query, $matches);
        $phrases = [];
        foreach (array_slice($matches[1] ?? [], 0, 4) as $value) {
            $normalized = $this->tokenizer->normalizePhrase((string) $value, 16);
            if ($normalized !== '') {
                $phrases[] = $normalized;
            }
        }
        return array_values(array_unique($phrases));
    }

    /** @param array<string,mixed> $document @param list<string> $tokens @return list<string> */
    private function matchedFields(array $document, array $tokens, string $query): array
    {
        $fields = [
            'identity.title' => (string) ($document['identity']['title'] ?? ''),
            'identity.sku' => (string) ($document['identity']['sku'] ?? ''),
            'classification.category_paths' => implode(' ', $document['classification']['category_paths'] ?? []),
            'content.description_text' => (string) ($document['content']['description_text'] ?? ''),
        ];
        $matched = [];
        foreach ($fields as $field => $value) {
            $normalized = $field === 'identity.sku' ? $this->tokenizer->normalizeExact($value) : $this->tokenizer->normalizePhrase($value, 4096);
            foreach ($tokens as $token) {
                if ($normalized !== '' && ($field === 'identity.sku' ? str_contains($normalized, $this->tokenizer->normalizeExact($query)) : str_contains($normalized, $token))) {
                    $matched[] = $field;
                    break;
                }
            }
        }
        return array_values(array_unique($matched));
    }

    /** @param array<string,mixed> $document @return array<string,list<array{text:string,highlighted:bool}>>|object */
    private function highlights(array $document, string $query): array|object
    {
        $tokens = array_values(array_filter($this->tokenizer->tokenize($query, 8), static fn (string $token): bool => mb_strlen($token) >= 2));
        if ($tokens === []) {
            return (object) [];
        }
        usort($tokens, static fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $pattern = '/(' . implode('|', array_map(static fn (string $token): string => preg_quote($token, '/'), $tokens)) . ')/iu';
        $result = [];
        foreach (['identity.title' => (string) ($document['identity']['title'] ?? ''), 'identity.sku' => (string) ($document['identity']['sku'] ?? '')] as $field => $value) {
            if ($value === '' || preg_match($pattern, $value) !== 1) {
                continue;
            }
            $parts = preg_split($pattern, mb_substr($value, 0, 10000), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
            $result[$field] = array_map(static fn (string $part): array => ['text' => $part, 'highlighted' => preg_match($pattern, $part) === 1], array_slice($parts, 0, 64));
        }
        return $result === [] ? (object) [] : $result;
    }

    /** @param list<string> $keys @param list<mixed> $params @param list<mixed> $pinnedParams @param list<int> $pinnedIds @return array<string, list<array{value:string,count:int}>> */
    private function facetCounts(array $keys, string $from, string $where, array $params, int $generation, string $documents, string $pinnedWhere, array $pinnedParams, array $pinnedIds): array
    {
        $result = [];
        $table = $this->db->prefix . 'sfs_facet_values';
        foreach ($keys as $key) {
            if ($pinnedIds === []) {
                $sql = "SELECT fv.facet_value,COUNT(DISTINCT d.document_id) AS result_count FROM {$from} INNER JOIN {$table} fv ON fv.generation_id=%d AND fv.document_id=d.document_id AND fv.facet_key=%s WHERE {$where} GROUP BY fv.facet_value ORDER BY result_count DESC,fv.facet_value ASC LIMIT 100";
                $queryParams = [$generation, $key, ...$params];
            } else {
                $pinPlaceholders = implode(',', array_fill(0, count($pinnedIds), '%d'));
                $sql = "SELECT fv.facet_value,COUNT(DISTINCT candidates.document_id) AS result_count FROM ((SELECT DISTINCT d.document_id FROM {$from} WHERE {$where}) UNION (SELECT d.document_id FROM {$documents} d WHERE {$pinnedWhere} AND d.entity_id IN ({$pinPlaceholders}))) candidates INNER JOIN {$table} fv ON fv.generation_id=%d AND fv.document_id=candidates.document_id AND fv.facet_key=%s GROUP BY fv.facet_value ORDER BY result_count DESC,fv.facet_value ASC LIMIT 100";
                $queryParams = [...$params, ...$pinnedParams, ...$pinnedIds, $generation, $key];
            }
            $rows = $this->db->get_results($this->db->prepare($sql, ...$queryParams), ARRAY_A);
            $result[$key] = array_map(static fn (array $row): array => ['value' => (string) $row['facet_value'], 'count' => (int) $row['result_count']], is_array($rows) ? $rows : []);
        }
        return $result;
    }

    /** @param list<array<string, mixed>> $hits @param array<string, mixed> $facets @return array<string, mixed> */
    private function response(int $generation, string $queryId, array $hits, array $facets, int $total, int $page, int $size, int $started, array $warnings = [], ?array $redirect = null): array
    {
        $elapsed = round((hrtime(true) - $started) / 1_000_000, 3);
        $response = [
            'contract_version' => '1.0',
            'provider' => 'local',
            'index_version' => 'local-v3-g' . max(0, $generation),
            'query_id' => $queryId,
            'hits' => $hits,
            'facets' => (object) $facets,
            'total' => $total,
            'page' => ['number' => $page, 'size' => $size, 'has_more' => ($page * $size) < $total],
            'timing' => ['provider_ms' => $elapsed, 'application_ms' => 0.0, 'total_ms' => $elapsed],
            'warnings' => $warnings,
        ];
        if ($redirect !== null) {
            $response['redirect'] = $redirect;
        }
        return $response;
    }
}
