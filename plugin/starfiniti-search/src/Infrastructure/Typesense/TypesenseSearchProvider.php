<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

use Starfiniti\Search\Domain\Provider\SearchProvider;

final class TypesenseSearchProvider implements SearchProvider
{
    private int $consecutiveFailures = 0;
    private int $circuitOpenedAt = 0;

    public function __construct(
        private readonly TypesenseTransport $transport,
        string $endpoint,
        private readonly CredentialReference $searchCredential,
        private readonly string $collectionAlias,
        private readonly int $deadlineMs = 1500,
        private readonly array $relevanceResources = []
    ) {
        $this->endpoint = EndpointPolicy::validate($endpoint);
        if (preg_match('/^[a-z0-9_]{8,191}$/', $collectionAlias) !== 1) {
            throw new \InvalidArgumentException('Invalid Typesense collection alias.');
        }
        if ($deadlineMs < 25 || $deadlineMs > 10000) {
            throw new \InvalidArgumentException('Invalid Typesense deadline.');
        }
        foreach ($relevanceResources as $scope => $resources) {
            if (!is_string($scope) || preg_match('/^[A-Za-z0-9_-]{2,32}\|[a-z][a-z0-9_-]{0,31}$/', $scope) !== 1 || !is_array($resources)
                || array_diff(array_keys($resources), ['synonym_set', 'curation_set']) !== []) {
                throw new \InvalidArgumentException('Invalid Typesense relevance resource map.');
            }
            foreach ($resources as $name) {
                if (!is_string($name) || preg_match('/^[A-Za-z0-9_.-]{1,191}$/', $name) !== 1) {
                    throw new \InvalidArgumentException('Invalid Typesense relevance resource name.');
                }
            }
        }
    }

    private readonly string $endpoint;

    public function id(): string
    {
        return 'typesense';
    }

    public function capabilities(): array
    {
        return [
            'contract_version' => '1.0',
            'provider_id' => 'typesense',
            'adapter_version' => defined('STARFINITI_SEARCH_VERSION') ? STARFINITI_SEARCH_VERSION : 'dev',
            'capabilities' => [
                'autocomplete' => ['state' => 'degraded', 'notes' => ['Adapter tests pass; real-service certification is required.']],
                'facets' => ['state' => 'degraded', 'notes' => ['Typesense capability is not exposed until conformance certification.']],
                'filters' => ['state' => 'degraded', 'notes' => ['Mandatory visibility filters compile; public filter AST mapping remains gated.']],
                'highlighting' => ['state' => 'degraded', 'notes' => ['Provider support exists; canonical highlight mapping is pending real-service tests.']],
                'typo_tolerance' => ['state' => 'degraded', 'notes' => ['Bounded provider parameters compile; version certification is pending.']],
                'synonyms' => ['state' => 'degraded', 'notes' => ['Scoped v30 synonym-set projection is implemented; real-service certification is pending.']],
                'curations' => ['state' => 'degraded', 'notes' => ['Scoped v30 curation-set projection is implemented; application-only actions remain explicit.']],
                'server_side_visibility' => ['state' => 'native', 'notes' => ['Mandatory provider filter cannot be removed by callers.']],
                'service_certification' => ['state' => 'unsupported', 'notes' => ['A supported real Typesense service has not been tested on this host.']],
            ],
            'limits' => ['max_page_size' => 100, 'max_facets' => 0, 'max_filter_nodes' => 0, 'max_query_length' => 512, 'deadline_ms' => $this->deadlineMs],
        ];
    }

    public function search(array $request): array
    {
        if ($this->circuitOpenedAt > 0 && time() - $this->circuitOpenedAt < 30) {
            throw new ProviderUnavailable('Typesense circuit is open.');
        }
        $started = hrtime(true);
        $query = mb_substr(trim((string) ($request['query'] ?? '')), 0, 512);
        $page = max(1, min(10000, (int) ($request['page']['number'] ?? 1)));
        $size = max(1, min(100, (int) ($request['page']['size'] ?? 10)));
        $locale = $this->filterLiteral((string) ($request['context']['locale'] ?? 'en_US'), 32);
        $channel = $this->filterLiteral((string) ($request['context']['channel'] ?? 'storefront'), 64);
        $parameters = [
            'q' => $query !== '' ? $query : '*',
            'query_by' => 'title,sku,search_text',
            'query_by_weights' => '12,30,1',
            'filter_by' => "searchable:=true && password_protected:=false && locale:={$locale} && channel:={$channel} && scope_tokens:=[public]",
            'page' => (string) $page,
            'per_page' => (string) $size,
            'prefix' => 'true,true,true',
            'num_typos' => '1,0,1',
            'exclude_fields' => 'search_text,scope_tokens',
        ];
        $resources = $this->relevanceResources[$locale . '|' . $channel] ?? [];
        if (isset($resources['synonym_set'])) {
            $parameters['synonym_sets'] = $resources['synonym_set'];
        }
        if (isset($resources['curation_set'])) {
            $parameters['curation_sets'] = $resources['curation_set'];
        }
        $url = $this->endpoint . '/collections/' . rawurlencode($this->collectionAlias) . '/documents/search?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $headers = ['Accept' => 'application/json', 'X-TYPESENSE-API-KEY' => $this->searchCredential->resolve()];

        try {
            $response = $this->perform($url, $headers);
            $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !isset($payload['hits']) || !is_array($payload['hits'])) {
                throw new ProviderUnavailable('Typesense returned an invalid response contract.');
            }
            $this->consecutiveFailures = 0;
            $this->circuitOpenedAt = 0;
        } catch (\Throwable $exception) {
            ++$this->consecutiveFailures;
            if ($this->consecutiveFailures >= 3) {
                $this->circuitOpenedAt = time();
            }
            throw $exception instanceof ProviderUnavailable ? $exception : new ProviderUnavailable('Typesense request failed.', 0, $exception);
        }

        $hits = [];
        foreach (array_slice($payload['hits'], 0, $size) as $index => $hit) {
            $document = is_array($hit['document'] ?? null) ? $hit['document'] : [];
            $canonical = is_array($document['canonical'] ?? null) ? $document['canonical'] : $document;
            $hits[] = [
                'document_id' => (string) ($canonical['document_id'] ?? ''),
                'entity_type' => (string) ($canonical['entity_type'] ?? 'product'),
                'entity_id' => max(1, (int) ($canonical['entity_id'] ?? 1)),
                'parent_id' => isset($canonical['parent_id']) ? (int) $canonical['parent_id'] : null,
                'score' => isset($hit['text_match']) ? min(1.0, max(0.0, (float) $hit['text_match'] / 1_000_000_000.0)) : 0.0,
                'rank' => (($page - 1) * $size) + $index + 1,
                'highlights' => (object) [],
                'matched_fields' => [],
                'projection' => $this->projection($canonical),
            ];
        }
        $elapsed = round((hrtime(true) - $started) / 1_000_000, 3);
        $total = max(0, (int) ($payload['found'] ?? count($hits)));
        return [
            'contract_version' => '1.0',
            'provider' => 'typesense',
            'index_version' => $this->collectionAlias,
            'query_id' => bin2hex(random_bytes(16)),
            'hits' => $hits,
            'facets' => (object) [],
            'total' => $total,
            'page' => ['number' => $page, 'size' => $size, 'has_more' => $page * $size < $total],
            'timing' => ['provider_ms' => $elapsed, 'application_ms' => 0.0, 'total_ms' => $elapsed],
            'warnings' => ['real_service_certification_required'],
        ];
    }

    /** @param array<string,string> $headers @return array{status:int,body:string,headers?:array<string,string>} */
    private function perform(string $url, array $headers): array
    {
        $attempts = 0;
        do {
            ++$attempts;
            $response = $this->transport->request('GET', $url, $headers, null, min(500, $this->deadlineMs), $this->deadlineMs);
            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response;
            }
        } while ($attempts < 2 && in_array($response['status'], [429, 502, 503, 504], true));
        throw new ProviderUnavailable('Typesense returned an unavailable status.');
    }

    private function filterLiteral(string $value, int $length): string
    {
        $value = mb_substr($value, 0, $length);
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid provider context value.');
        }
        return $value;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function projection(array $document): array
    {
        $projection = [];
        foreach (['identity', 'content', 'classification', 'attributes', 'pricing', 'inventory', 'media', 'quality'] as $field) {
            $projection[$field] = is_array($document[$field] ?? null) ? $document[$field] : [];
        }
        return $projection;
    }
}
