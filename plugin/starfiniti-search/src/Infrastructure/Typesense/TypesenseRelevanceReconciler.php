<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

use DateTimeImmutable;
use Starfiniti\Search\Domain\Search\RelevancePolicy;

/** Projects canonical relevance into Typesense v30 resources without making it the source of truth. */
final class TypesenseRelevanceReconciler
{
    private readonly string $endpoint;

    public function __construct(
        private readonly TypesenseTransport $transport,
        string $endpoint,
        private readonly CredentialReference $adminCredential,
        private readonly int $deadlineMs = 5000
    ) {
        $this->endpoint = EndpointPolicy::validate($endpoint);
        if ($deadlineMs < 100 || $deadlineMs > 30000) {
            throw new \InvalidArgumentException('Invalid Typesense relevance deadline.');
        }
    }

    /**
     * @param array<string,mixed> $ranking
     * @param array<int,string> $documentIdsByEntity
     * @return array{changed:bool,synonym_set:?string,curation_set:?string,synonyms:int,curations:int,resource_ids:array<string,string>,application_only:list<string>,next_reconcile_at:?string,projection_hash:string}
     */
    public function reconcile(
        string $collection,
        int $serverMajor,
        int $configurationRevision,
        array $ranking,
        string $locale,
        string $channel,
        array $documentIdsByEntity,
        ?DateTimeImmutable $at = null
    ): array {
        $this->collection($collection);
        if ($serverMajor !== 30) {
            throw new \InvalidArgumentException('Typesense relevance projection supports only the explicitly mapped v30 resource API.');
        }
        if ($configurationRevision < 1 || preg_match('/^[A-Za-z0-9_-]{2,32}$/', $locale) !== 1 || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $channel) !== 1) {
            throw new \InvalidArgumentException('Invalid Typesense relevance scope.');
        }
        RelevancePolicy::validate($ranking);
        $at ??= new DateTimeImmutable('now');
        $now = $at->getTimestamp();
        $next = null;
        $applicationOnly = [];
        $resourceIds = [];

        $synonyms = [];
        foreach ($ranking['synonyms'] ?? [] as $rule) {
            if (($rule['locale'] ?? null) !== $locale || ($rule['channel'] ?? null) !== $channel || !$this->active($rule, $now, $next)) {
                continue;
            }
            $id = 'sfs_' . (string) $rule['id'];
            $item = ['id' => $id, 'locale' => $locale, 'symbols_to_index' => []];
            if (($rule['type'] ?? null) === 'directional') {
                $item['root'] = (string) $rule['source'];
                $item['synonyms'] = array_values($rule['targets']);
            } else {
                $item['synonyms'] = array_values($rule['terms']);
            }
            $synonyms[] = $item;
            $resourceIds['synonym:' . $rule['id']] = $id;
        }

        $curations = [];
        foreach ($ranking['curations'] ?? [] as $rule) {
            if (($rule['locale'] ?? null) !== $locale || ($rule['channel'] ?? null) !== $channel || !$this->active($rule, $now, $next)) {
                continue;
            }
            $actions = $rule['actions'];
            $id = sprintf('sfs_p%04d_%s', 1000 - (int) $rule['priority'], (string) $rule['id']);
            $item = [
                'id' => $id,
                'rule' => ['query' => (string) $rule['query'], 'match' => 'exact'],
                'includes' => [],
                'excludes' => [],
                'filter_curated_hits' => true,
                'stop_processing' => false,
                'metadata' => ['starfiniti_rule_id' => (string) $rule['id']],
            ];
            foreach ($actions['pin'] ?? [] as $position => $entityId) {
                $documentId = $this->documentId($documentIdsByEntity, (int) $entityId);
                if ($documentId === null) {
                    $applicationOnly[] = 'curation:' . $rule['id'] . ':missing_document:' . $entityId;
                    continue;
                }
                $item['includes'][] = ['id' => $documentId, 'position' => $position + 1];
            }
            foreach ($actions['hide'] ?? [] as $entityId) {
                $documentId = $this->documentId($documentIdsByEntity, (int) $entityId);
                if ($documentId === null) {
                    $applicationOnly[] = 'curation:' . $rule['id'] . ':missing_document:' . $entityId;
                    continue;
                }
                $item['excludes'][] = ['id' => $documentId];
            }
            if (isset($actions['rewrite'])) {
                $item['replace_query'] = (string) $actions['rewrite'];
            }
            foreach (['boost', 'bury', 'filter', 'redirect'] as $applicationAction) {
                if (isset($actions[$applicationAction]) && $actions[$applicationAction] !== []) {
                    $applicationOnly[] = 'curation:' . $rule['id'] . ':' . $applicationAction;
                }
            }
            if (isset($rule['starts_at'])) {
                $item['effective_from_ts'] = (new DateTimeImmutable((string) $rule['starts_at']))->getTimestamp();
            }
            if (isset($rule['ends_at'])) {
                $item['effective_to_ts'] = (new DateTimeImmutable((string) $rule['ends_at']))->getTimestamp();
            }
            if ($item['includes'] === [] && $item['excludes'] === [] && !isset($item['replace_query'])) {
                $applicationOnly[] = 'curation:' . $rule['id'] . ':entire_rule';
                continue;
            }
            $curations[] = $item;
            $resourceIds['curation:' . $rule['id']] = $id;
        }

        usort($synonyms, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));
        usort($curations, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));
        $scope = 'sfsr_' . substr(hash('sha256', $collection . '|' . $locale . '|' . $channel), 0, 16) . '_';
        $synonymSet = $synonyms === [] ? null : $scope . 'syn';
        $curationSet = $curations === [] ? null : $scope . 'cur';
        $changed = false;
        if ($synonymSet !== null) {
            $changed = $this->ensureSet('synonym_sets', $synonymSet, $synonyms) || $changed;
        }
        if ($curationSet !== null) {
            $changed = $this->ensureSet('curation_sets', $curationSet, $curations) || $changed;
        }

        $collectionResponse = $this->request('GET', '/collections/' . rawurlencode($collection), null, [200]);
        $collectionState = $this->decoded($collectionResponse['body']);
        $oldManaged = [];
        $desiredLinks = [];
        foreach (['synonym_sets' => $synonymSet, 'curation_sets' => $curationSet] as $field => $desired) {
            $existing = is_array($collectionState[$field] ?? null) ? $collectionState[$field] : [];
            $external = [];
            foreach ($existing as $name) {
                if (!is_string($name) || preg_match('/^[A-Za-z0-9_.-]{1,191}$/', $name) !== 1) {
                    throw new ProviderUnavailable('Typesense collection relevance links were invalid.');
                }
                if (str_starts_with($name, $scope)) {
                    if ($name !== $desired) {
                        $oldManaged[$field][] = $name;
                    }
                } else {
                    $external[] = $name;
                }
            }
            sort($external, SORT_STRING);
            $desiredLinks[$field] = array_values(array_unique($external));
        }
        $currentLinks = [];
        foreach (array_keys($desiredLinks) as $field) {
            $currentLinks[$field] = is_array($collectionState[$field] ?? null) ? array_values($collectionState[$field]) : [];
            sort($currentLinks[$field], SORT_STRING);
        }
        if ($currentLinks !== $desiredLinks) {
            $this->request('PATCH', '/collections/' . rawurlencode($collection), $this->json($desiredLinks), [200]);
            $changed = true;
        }
        $verifiedCollection = $this->decoded($this->request('GET', '/collections/' . rawurlencode($collection), null, [200])['body']);
        foreach ($desiredLinks as $field => $expected) {
            $actual = is_array($verifiedCollection[$field] ?? null) ? array_values($verifiedCollection[$field]) : [];
            sort($actual, SORT_STRING);
            if ($actual !== $expected) {
                throw new ProviderUnavailable('Typesense relevance link verification failed.');
            }
        }
        foreach ($oldManaged as $field => $names) {
            $resource = $field === 'synonym_sets' ? 'synonym_sets' : 'curation_sets';
            foreach ($names as $name) {
                $this->request('DELETE', '/' . $resource . '/' . rawurlencode($name), null, [200, 404]);
            }
        }

        $projection = ['configuration_revision' => $configurationRevision, 'synonyms' => $synonyms, 'curations' => $curations, 'resources' => ['synonym_set' => $synonymSet, 'curation_set' => $curationSet]];
        sort($applicationOnly, SORT_STRING);
        return [
            'changed' => $changed,
            'synonym_set' => $synonymSet,
            'curation_set' => $curationSet,
            'synonyms' => count($synonyms),
            'curations' => count($curations),
            'resource_ids' => $resourceIds,
            'application_only' => array_values(array_unique($applicationOnly)),
            'next_reconcile_at' => $next === null ? null : gmdate(DATE_ATOM, $next),
            'projection_hash' => hash('sha256', $this->json($projection)),
        ];
    }

    /** @param array<string,mixed> $rule */
    private function active(array $rule, int $now, ?int &$next): bool
    {
        $starts = isset($rule['starts_at']) ? (new DateTimeImmutable((string) $rule['starts_at']))->getTimestamp() : null;
        $ends = isset($rule['ends_at']) ? (new DateTimeImmutable((string) $rule['ends_at']))->getTimestamp() : null;
        foreach ([$starts, $ends] as $boundary) {
            if ($boundary !== null && $boundary > $now && ($next === null || $boundary < $next)) {
                $next = $boundary;
            }
        }
        return ($starts === null || $starts <= $now) && ($ends === null || $ends > $now);
    }

    /** @param array<int,string> $documentIdsByEntity */
    private function documentId(array $documentIdsByEntity, int $entityId): ?string
    {
        $value = $documentIdsByEntity[$entityId] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid Typesense document identity mapping.');
        }
        return $value;
    }

    /** @param list<array<string,mixed>> $items */
    private function ensureSet(string $resource, string $name, array $items): bool
    {
        $path = '/' . $resource . '/' . rawurlencode($name);
        $response = $this->request('GET', $path, null, [200, 404]);
        $changed = $response['status'] === 404 || !$this->sameItems($resource, $items, $this->decoded($response['body'])['items'] ?? null);
        if ($changed) {
            $this->request('PUT', $path, $this->json(['items' => $items]), [200, 201]);
        }
        $verified = $this->decoded($this->request('GET', $path, null, [200])['body']);
        if (!$this->sameItems($resource, $items, $verified['items'] ?? null)) {
            throw new ProviderUnavailable('Typesense relevance resource verification failed.');
        }
        return $changed;
    }

    /** @param list<array<string,mixed>> $expected */
    private function sameItems(string $resource, array $expected, mixed $actual): bool
    {
        if (!is_array($actual) || !array_is_list($actual)) {
            return false;
        }
        $allowed = $resource === 'synonym_sets'
            ? ['id', 'synonyms', 'root', 'locale', 'symbols_to_index']
            : ['id', 'rule', 'includes', 'excludes', 'replace_query', 'filter_by', 'sort_by', 'filter_curated_hits', 'effective_from_ts', 'effective_to_ts', 'stop_processing', 'metadata'];
        $normalize = static function (array $item) use ($allowed): array {
            $normalized = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $item)) {
                    $normalized[$key] = $item[$key];
                }
            }
            return $normalized;
        };
        $expected = array_map(static fn (array $item): array => $normalize($item), $expected);
        $actual = array_map(static fn (mixed $item): array => is_array($item) ? $normalize($item) : [], $actual);
        usort($expected, static fn (array $left, array $right): int => strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')));
        usort($actual, static fn (array $left, array $right): int => strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? '')));
        return hash_equals(hash('sha256', $this->json($expected)), hash('sha256', $this->json($actual)));
    }

    /** @return array{status:int,body:string,headers?:array<string,string>} */
    private function request(string $method, string $path, ?string $body, array $allowed): array
    {
        try {
            $response = $this->transport->request($method, $this->endpoint . $path, [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-TYPESENSE-API-KEY' => $this->adminCredential->resolve(),
            ], $body, min(1000, $this->deadlineMs), $this->deadlineMs);
        } catch (\Throwable) {
            throw new ProviderUnavailable('Typesense relevance request failed.');
        }
        if (!in_array($response['status'], $allowed, true)) {
            throw new ProviderUnavailable('Typesense relevance request failed.');
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private function decoded(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ProviderUnavailable('Typesense relevance response was invalid.');
        }
        if (!is_array($decoded)) {
            throw new ProviderUnavailable('Typesense relevance response was invalid.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function collection(string $collection): void
    {
        if (preg_match('/^[a-z0-9_]{8,191}$/', $collection) !== 1) {
            throw new \InvalidArgumentException('Invalid Typesense collection name.');
        }
    }
}
