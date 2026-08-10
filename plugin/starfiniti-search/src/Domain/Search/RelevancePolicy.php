<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Search;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class RelevancePolicy
{
    public const SYNONYM_WEIGHT_FACTOR = 0.75;
    private const MAX_RULES = 256;
    private const MAX_TERMS_PER_RULE = 8;
    private const MAX_EXPANSIONS = 16;
    private const MAX_STOP_WORDS_PER_LOCALE = 512;

    /** @param array<string,mixed> $ranking */
    private function __construct(private readonly array $ranking, private readonly Tokenizer $tokenizer)
    {
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'profile' => RankingProfile::VERSION,
            'synonyms' => [],
            'stop_words' => [],
            'curations' => [],
        ];
    }

    /** @param array<string,mixed> $ranking */
    public static function fromArray(array $ranking, Tokenizer $tokenizer): self
    {
        self::validate($ranking);
        return new self($ranking + self::defaults(), $tokenizer);
    }

    /** @param array<string,mixed> $ranking */
    public static function validate(array $ranking): void
    {
        $allowed = ['profile', 'synonyms', 'stop_words', 'curations'];
        $unknown = array_diff(array_keys($ranking), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Ranking policy contains unsupported fields.');
        }
        if (!is_string($ranking['profile'] ?? null) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', (string) $ranking['profile']) !== 1) {
            throw new InvalidArgumentException('Invalid ranking profile identifier.');
        }

        $synonyms = $ranking['synonyms'] ?? [];
        if (!is_array($synonyms) || count($synonyms) > self::MAX_RULES || !array_is_list($synonyms)) {
            throw new InvalidArgumentException('Synonym rules must be a bounded list.');
        }
        $ids = [];
        $directional = [];
        foreach ($synonyms as $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException('Each synonym rule must be an object.');
            }
            self::validateRuleEnvelope($rule, ['id', 'type', 'locale', 'channel', 'terms', 'source', 'targets', 'starts_at', 'ends_at']);
            $id = self::identifier($rule['id'] ?? null, 'synonym');
            if (isset($ids[$id])) {
                throw new InvalidArgumentException('Duplicate synonym rule identifier.');
            }
            $ids[$id] = true;
            $type = $rule['type'] ?? null;
            if ($type === 'equivalent') {
                self::stringList($rule['terms'] ?? null, 2, self::MAX_TERMS_PER_RULE, 'equivalent synonym terms');
                if (array_key_exists('source', $rule) || array_key_exists('targets', $rule)) {
                    throw new InvalidArgumentException('Equivalent synonym rules cannot have directional fields.');
                }
            } elseif ($type === 'directional') {
                $source = self::boundedText($rule['source'] ?? null, 'directional synonym source');
                $targets = self::stringList($rule['targets'] ?? null, 1, self::MAX_TERMS_PER_RULE, 'directional synonym targets');
                if (array_key_exists('terms', $rule)) {
                    throw new InvalidArgumentException('Directional synonym rules cannot have equivalent terms.');
                }
                $scope = (string) $rule['locale'] . '|' . (string) $rule['channel'];
                $sourceKey = self::graphKey($source);
                if (isset($directional[$scope][$sourceKey])) {
                    throw new InvalidArgumentException('Conflicting directional synonym source in the same scope.');
                }
                $directional[$scope][$sourceKey] = array_map([self::class, 'graphKey'], $targets);
            } else {
                throw new InvalidArgumentException('Unsupported synonym rule type.');
            }
        }
        foreach ($directional as $graph) {
            self::assertAcyclic($graph);
        }

        $stopWords = $ranking['stop_words'] ?? [];
        if (!is_array($stopWords) || array_is_list($stopWords) && $stopWords !== []) {
            throw new InvalidArgumentException('Stop words must be keyed by locale.');
        }
        foreach ($stopWords as $locale => $words) {
            self::locale((string) $locale);
            self::stringList($words, 0, self::MAX_STOP_WORDS_PER_LOCALE, 'stop words');
        }

        $curations = $ranking['curations'] ?? [];
        if (!is_array($curations) || !array_is_list($curations) || count($curations) > self::MAX_RULES) {
            throw new InvalidArgumentException('Curations must be a bounded list.');
        }
        $curationIds = [];
        $conditions = [];
        $rewrites = [];
        foreach ($curations as $rule) {
            if (!is_array($rule)) {
                throw new InvalidArgumentException('Each curation must be an object.');
            }
            $unknown = array_diff(array_keys($rule), ['id', 'query', 'locale', 'channel', 'priority', 'actions', 'starts_at', 'ends_at']);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Curation contains unsupported fields.');
            }
            $id = self::identifier($rule['id'] ?? null, 'curation');
            if (isset($curationIds[$id])) {
                throw new InvalidArgumentException('Duplicate curation identifier.');
            }
            $curationIds[$id] = true;
            $query = $rule['query'] ?? null;
            if (!is_string($query) || trim($query) === '' || mb_strlen($query) > 512 || preg_match('/[\x00-\x1F\x7F]/u', $query) === 1) {
                throw new InvalidArgumentException('Invalid curation query.');
            }
            self::locale((string) ($rule['locale'] ?? ''));
            if (!is_string($rule['channel'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', (string) $rule['channel']) !== 1) {
                throw new InvalidArgumentException('Invalid curation channel.');
            }
            $priority = $rule['priority'] ?? null;
            if (!is_int($priority) || $priority < -1000 || $priority > 1000) {
                throw new InvalidArgumentException('Curation priority must be an integer from -1000 to 1000.');
            }
            $starts = self::date($rule['starts_at'] ?? null, 'starts_at');
            $ends = self::date($rule['ends_at'] ?? null, 'ends_at');
            if ($starts !== null && $ends !== null && $ends <= $starts) {
                throw new InvalidArgumentException('Curation effective date range is empty.');
            }
            $condition = self::graphKey($query) . '|' . $rule['locale'] . '|' . $rule['channel'] . '|' . $priority;
            if (isset($conditions[$condition])) {
                throw new InvalidArgumentException('Curations with the same query, scope, and priority are ambiguous.');
            }
            $conditions[$condition] = true;
            self::validateCurationActions($rule['actions'] ?? null);
            if (isset($rule['actions']['rewrite'])) {
                $scope = (string) $rule['locale'] . '|' . (string) $rule['channel'];
                $source = self::graphKey($query);
                if (isset($rewrites[$scope][$source])) {
                    throw new InvalidArgumentException('Multiple rewrite rules for the same query and scope are ambiguous.');
                }
                $rewrites[$scope][$source] = [self::graphKey((string) $rule['actions']['rewrite'])];
            }
        }
        foreach ($rewrites as $graph) {
            self::assertAcyclic($graph, true);
        }
    }

    /**
     * @return array{tokens:list<string>,synonym_terms:array<string,array{rule_id:string,type:string}>,stop_words_removed:list<string>,all_stop_words:bool}
     */
    public function analyze(string $query, string $locale, string $channel, ?DateTimeImmutable $now = null): array
    {
        $original = array_slice($this->tokenizer->sequence($query, 8), 0, 8);
        $stopSet = [];
        foreach (($this->ranking['stop_words'][$locale] ?? []) as $word) {
            foreach ($this->tokenizer->sequence((string) $word, 1) as $token) {
                $stopSet[$token] = true;
            }
        }
        $tokens = [];
        $removed = [];
        foreach ($original as $token) {
            if (isset($stopSet[$token])) {
                $removed[] = $token;
            } else {
                $tokens[] = $token;
            }
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $normalizedQuery = $this->tokenizer->normalizePhrase($query, 16);
        $expansions = [];
        foreach ($this->ranking['synonyms'] as $rule) {
            if (!$this->active($rule, $locale, $channel, $now)) {
                continue;
            }
            $targets = [];
            if ($rule['type'] === 'equivalent') {
                $normalizedTerms = [];
                $matched = false;
                foreach ($rule['terms'] as $term) {
                    $normalized = $this->tokenizer->normalizePhrase((string) $term, 4);
                    $normalizedTerms[] = $normalized;
                    if ($normalized === $normalizedQuery || (count($this->tokenizer->sequence($normalized, 4)) === 1 && in_array($normalized, $tokens, true))) {
                        $matched = true;
                    }
                }
                if ($matched) {
                    $targets = $normalizedTerms;
                }
            } else {
                $source = $this->tokenizer->normalizePhrase((string) $rule['source'], 4);
                if ($source === $normalizedQuery || (count($this->tokenizer->sequence($source, 4)) === 1 && in_array($source, $tokens, true))) {
                    $targets = $rule['targets'];
                }
            }
            foreach ($targets as $target) {
                foreach ($this->tokenizer->sequence((string) $target, 4) as $term) {
                    if (!in_array($term, $tokens, true) && !isset($stopSet[$term])) {
                        $expansions[$term] ??= ['rule_id' => (string) $rule['id'], 'type' => (string) $rule['type']];
                        if (count($expansions) >= self::MAX_EXPANSIONS) {
                            break 3;
                        }
                    }
                }
            }
        }
        ksort($expansions, SORT_STRING);
        return [
            'tokens' => array_values($tokens),
            'synonym_terms' => $expansions,
            'stop_words_removed' => array_values(array_unique($removed)),
            'all_stop_words' => $query !== '' && $original !== [] && $tokens === [],
        ];
    }

    /**
     * @return array{rule_ids:list<string>,entity_actions:array<int,array{type:string,value:int,rule_id:string,priority:int}>,pins:list<int>,hidden:list<int>,adjustments:array<int,int>,redirect:array{url:string,rule_id:string}|null,rewrite:array{query:string,rule_id:string}|null,filter:array{filter:array<string,mixed>,rule_id:string}|null}
     */
    public function curation(string $query, string $locale, string $channel, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $normalized = $this->tokenizer->normalizePhrase($query, 16);
        $rules = array_values(array_filter($this->ranking['curations'], function (array $rule) use ($normalized, $locale, $channel, $now): bool {
            return $this->tokenizer->normalizePhrase((string) $rule['query'], 16) === $normalized
                && $this->active($rule, $locale, $channel, $now);
        }));
        usort($rules, static fn (array $left, array $right): int => ((int) $right['priority'] <=> (int) $left['priority']) ?: strcmp((string) $left['id'], (string) $right['id']));

        $ruleIds = [];
        $entityActions = [];
        $redirect = null;
        $rewrite = null;
        $filter = null;
        $pinPosition = 0;
        foreach ($rules as $rule) {
            $ruleIds[] = (string) $rule['id'];
            $actions = $rule['actions'];
            foreach ($actions['pin'] ?? [] as $entityId) {
                $id = (int) $entityId;
                $entityActions[$id] ??= ['type' => 'pin', 'value' => $pinPosition++, 'rule_id' => (string) $rule['id'], 'priority' => (int) $rule['priority']];
            }
            foreach ($actions['hide'] ?? [] as $entityId) {
                $id = (int) $entityId;
                $entityActions[$id] ??= ['type' => 'hide', 'value' => 0, 'rule_id' => (string) $rule['id'], 'priority' => (int) $rule['priority']];
            }
            foreach (['boost' => 1, 'bury' => -1] as $type => $direction) {
                foreach ($actions[$type] ?? [] as $entityId => $value) {
                    $id = (int) $entityId;
                    $entityActions[$id] ??= ['type' => $type, 'value' => $direction * (int) $value, 'rule_id' => (string) $rule['id'], 'priority' => (int) $rule['priority']];
                }
            }
            if ($redirect === null && isset($actions['redirect'])) {
                $redirect = ['url' => (string) $actions['redirect'], 'rule_id' => (string) $rule['id']];
            }
            if ($rewrite === null && isset($actions['rewrite'])) {
                $rewrite = ['query' => (string) $actions['rewrite'], 'rule_id' => (string) $rule['id']];
            }
            if ($filter === null && isset($actions['filter'])) {
                $filter = ['filter' => $actions['filter'], 'rule_id' => (string) $rule['id']];
            }
        }
        $pins = [];
        $hidden = [];
        $adjustments = [];
        foreach ($entityActions as $entityId => $action) {
            if ($action['type'] === 'pin') {
                $pins[$action['value']] = $entityId;
            } elseif ($action['type'] === 'hide') {
                $hidden[] = $entityId;
            } else {
                $adjustments[$entityId] = $action['value'];
            }
        }
        ksort($pins, SORT_NUMERIC);
        sort($hidden, SORT_NUMERIC);
        ksort($adjustments, SORT_NUMERIC);
        return ['rule_ids' => $ruleIds, 'entity_actions' => $entityActions, 'pins' => array_values($pins), 'hidden' => $hidden, 'adjustments' => $adjustments, 'redirect' => $redirect, 'rewrite' => $rewrite, 'filter' => $filter];
    }

    /** @param array<string,mixed> $rule */
    private function active(array $rule, string $locale, string $channel, DateTimeImmutable $now): bool
    {
        if (($rule['locale'] ?? '') !== $locale || ($rule['channel'] ?? '') !== $channel) {
            return false;
        }
        $timestamp = $now->getTimestamp();
        return (!isset($rule['starts_at']) || (new DateTimeImmutable((string) $rule['starts_at']))->getTimestamp() <= $timestamp)
            && (!isset($rule['ends_at']) || (new DateTimeImmutable((string) $rule['ends_at']))->getTimestamp() > $timestamp);
    }

    /** @param array<string,mixed> $rule @param list<string> $allowed */
    private static function validateRuleEnvelope(array $rule, array $allowed): void
    {
        $unknown = array_diff(array_keys($rule), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Synonym rule contains unsupported fields.');
        }
        self::locale((string) ($rule['locale'] ?? ''));
        if (!is_string($rule['channel'] ?? null) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', (string) $rule['channel']) !== 1) {
            throw new InvalidArgumentException('Invalid synonym channel.');
        }
        $starts = self::date($rule['starts_at'] ?? null, 'starts_at');
        $ends = self::date($rule['ends_at'] ?? null, 'ends_at');
        if ($starts !== null && $ends !== null && $ends <= $starts) {
            throw new InvalidArgumentException('Synonym effective date range is empty.');
        }
    }

    private static function identifier(mixed $value, string $label): string
    {
        if (!is_string($value) || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new InvalidArgumentException('Relevance rule identifier is invalid.');
        }
        return $value;
    }

    private static function locale(string $locale): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{2,32}$/', $locale) !== 1) {
            throw new InvalidArgumentException('Invalid relevance locale.');
        }
    }

    /** @return list<string> */
    private static function stringList(mixed $value, int $minimum, int $maximum, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) < $minimum || count($value) > $maximum) {
            throw new InvalidArgumentException('Relevance values must be a bounded list.');
        }
        $result = [];
        foreach ($value as $item) {
            $result[] = self::boundedText($item, $label);
        }
        if (count(array_unique(array_map([self::class, 'graphKey'], $result))) !== count($result)) {
            throw new InvalidArgumentException('Relevance values contain duplicates.');
        }
        return $result;
    }

    private static function boundedText(mixed $value, string $label): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 128 || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
            throw new InvalidArgumentException('Relevance text is invalid.');
        }
        return trim($value);
    }

    private static function validateCurationActions(mixed $actions): void
    {
        if (!is_array($actions) || $actions === []) {
            throw new InvalidArgumentException('Curation actions must be a non-empty object.');
        }
        $unknown = array_diff(array_keys($actions), ['pin', 'boost', 'bury', 'hide', 'redirect', 'rewrite', 'filter']);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Curation contains unsupported actions.');
        }
        $entities = [];
        foreach (['pin', 'hide'] as $type) {
            foreach (self::entityList($actions[$type] ?? [], $type) as $entityId) {
                if (isset($entities[$entityId])) {
                    throw new InvalidArgumentException('A product cannot have conflicting actions in one curation.');
                }
                $entities[$entityId] = $type;
            }
        }
        foreach (['boost', 'bury'] as $type) {
            $values = $actions[$type] ?? [];
            if (!is_array($values) || array_is_list($values) && $values !== [] || count($values) > 50) {
                throw new InvalidArgumentException('Curation adjustments must be a bounded product-to-weight object.');
            }
            foreach ($values as $entityId => $value) {
                $id = filter_var($entityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false || !is_int($value) || $value < 1 || $value > 1000 || isset($entities[$id])) {
                    throw new InvalidArgumentException('Invalid or conflicting curation adjustment.');
                }
                $entities[$id] = $type;
            }
        }
        if (isset($actions['redirect'])) {
            self::internalPath($actions['redirect']);
        }
        if (isset($actions['rewrite'])) {
            $rewrite = $actions['rewrite'];
            if (!is_string($rewrite) || trim($rewrite) === '' || mb_strlen($rewrite) > 512 || preg_match('/[\x00-\x1F\x7F]/u', $rewrite) === 1) {
                throw new InvalidArgumentException('Invalid curation query rewrite.');
            }
        }
        if (isset($actions['filter'])) {
            $budget = 32;
            self::validateFilter($actions['filter'], $budget, 0);
        }
    }

    /** @return list<int> */
    private static function entityList(mixed $value, string $label): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 50) {
            throw new InvalidArgumentException('Curation products must be a bounded list.');
        }
        $result = [];
        foreach ($value as $entityId) {
            if (!is_int($entityId) || $entityId < 1 || isset($result[$entityId])) {
                throw new InvalidArgumentException('Invalid or duplicate curation product identifier.');
            }
            $result[$entityId] = $entityId;
        }
        return array_values($result);
    }

    private static function internalPath(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 2048 || !str_starts_with($value, '/') || str_starts_with($value, '//')
            || str_contains($value, '\\') || preg_match('/[\x00-\x20\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Curation redirects must be safe internal paths.');
        }
        return $value;
    }

    private static function validateFilter(mixed $filter, int &$budget, int $depth): void
    {
        if (!is_array($filter) || $depth > 4 || --$budget < 0) {
            throw new InvalidArgumentException('Curation filter exceeds the supported complexity budget.');
        }
        foreach (['and', 'or'] as $group) {
            if (array_key_exists($group, $filter)) {
                if (count($filter) !== 1 || !is_array($filter[$group]) || !array_is_list($filter[$group]) || $filter[$group] === [] || count($filter[$group]) > 32) {
                    throw new InvalidArgumentException('Invalid curation filter group.');
                }
                foreach ($filter[$group] as $child) {
                    self::validateFilter($child, $budget, $depth + 1);
                }
                return;
            }
        }
        if (array_key_exists('not', $filter)) {
            if (count($filter) !== 1) {
                throw new InvalidArgumentException('Invalid curation negation filter.');
            }
            self::validateFilter($filter['not'], $budget, $depth + 1);
            return;
        }
        if (array_diff(array_keys($filter), ['field', 'op', 'value']) !== [] || !is_string($filter['field'] ?? null) || !is_string($filter['op'] ?? null)) {
            throw new InvalidArgumentException('Invalid curation filter leaf.');
        }
        $field = $filter['field'];
        $allowedField = in_array($field, ['pricing.active_min_minor', 'identity.sku', 'inventory.stock_status', 'quality.featured', 'classification.category_ids', 'classification.category_paths'], true)
            || preg_match('/^attributes\.[a-z0-9_-]{1,120}$/', $field) === 1;
        if (!$allowedField || !in_array($filter['op'], ['eq', 'neq', 'in', 'not_in', 'exists', 'lt', 'lte', 'gt', 'gte', 'between'], true)) {
            throw new InvalidArgumentException('Unsupported curation filter field or operator.');
        }
        $operator = $filter['op'];
        $value = $filter['value'] ?? null;
        if ($field === 'pricing.active_min_minor') {
            if ($operator === 'between') {
                if (!is_array($value) || !array_is_list($value) || count($value) !== 2 || !is_numeric($value[0]) || !is_numeric($value[1])) {
                    throw new InvalidArgumentException('Invalid curation numeric range.');
                }
            } elseif (!in_array($operator, ['eq', 'neq', 'lt', 'lte', 'gt', 'gte'], true) || !is_numeric($value)) {
                throw new InvalidArgumentException('Invalid curation numeric comparison.');
            }
            return;
        }
        if ($operator === 'exists' && $field === 'identity.sku') {
            throw new InvalidArgumentException('Identifier existence is not a supported curation filter.');
        }
        if ($operator === 'exists') {
            return;
        }
        if (!in_array($operator, ['eq', 'neq', 'in', 'not_in'], true)) {
            throw new InvalidArgumentException('Invalid curation scalar filter operation.');
        }
        $values = is_array($value) ? $value : [$value];
        if ($values === [] || count($values) > 32) {
            throw new InvalidArgumentException('Curation filter values must be bounded.');
        }
        foreach ($values as $item) {
            if (!is_scalar($item) || mb_strlen((string) $item) > 191) {
                throw new InvalidArgumentException('Invalid curation filter value.');
            }
        }
    }

    private static function graphKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value), 'UTF-8');
    }

    private static function date(mixed $value, string $label): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidArgumentException('Synonym effective date is invalid.');
        }
        try {
            return (new DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Synonym effective date is invalid.');
        }
    }

    /** @param array<string,list<string>> $graph */
    private static function assertAcyclic(array $graph, bool $curation = false): void
    {
        $visiting = [];
        $visited = [];
        $visit = static function (string $node) use (&$visit, &$visiting, &$visited, $graph, $curation): void {
            if (isset($visiting[$node])) {
                throw new InvalidArgumentException($curation ? 'Curation rewrite cycle detected.' : 'Directional synonym cycle detected.');
            }
            if (isset($visited[$node])) {
                return;
            }
            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $target) {
                if (isset($graph[$target])) {
                    $visit($target);
                }
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };
        foreach (array_keys($graph) as $node) {
            $visit($node);
        }
    }
}
