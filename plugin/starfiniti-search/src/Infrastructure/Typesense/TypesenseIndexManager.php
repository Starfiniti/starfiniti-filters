<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

final class TypesenseIndexManager
{
    private readonly string $endpoint;
    private readonly string $alias;

    public function __construct(
        private readonly TypesenseTransport $transport,
        string $endpoint,
        private readonly CredentialReference $adminCredential,
        string $installationUuid,
        string $environment,
        string $locale,
        private readonly int $deadlineMs = 5000
    ) {
        $this->endpoint = EndpointPolicy::validate($endpoint);
        $this->alias = CollectionNames::alias($installationUuid, $environment, $locale);
        if ($deadlineMs < 100 || $deadlineMs > 30000) {
            throw new \InvalidArgumentException('Invalid Typesense control-plane deadline.');
        }
    }

    /** @param array<string, mixed> $schema */
    public function ensureVersionedCollection(int $schemaVersion, int $build, array $schema): string
    {
        if ($schemaVersion < 1 || $build < 1 || strlen((string) json_encode($schema)) > 65536) {
            throw new \InvalidArgumentException('Invalid Typesense collection build request.');
        }
        $name = CollectionNames::versionedFromAlias($this->alias, $schemaVersion, $build);
        $existing = $this->request('GET', '/collections/' . rawurlencode($name), null, [200, 404]);
        if ($existing['status'] === 404) {
            $schema['name'] = $name;
            $this->request('POST', '/collections', $this->json($schema), [201, 409]);
        }
        $verified = $this->request('GET', '/collections/' . rawurlencode($name), null, [200]);
        $body = $this->decoded($verified['body']);
        if (($body['name'] ?? null) !== $name) {
            throw new ProviderUnavailable('Typesense collection verification failed.');
        }
        return $name;
    }

    /** @param list<array<string, mixed>> $documents @return list<array{document_id:string,success:bool,error_code:?string}> */
    public function import(string $collection, array $documents): array
    {
        $this->collection($collection);
        if ($documents === [] || count($documents) > 1000) {
            throw new \InvalidArgumentException('Typesense import batch must contain 1 to 1000 documents.');
        }
        $lines = [];
        $ids = [];
        foreach ($documents as $document) {
            $id = (string) ($document['document_id'] ?? '');
            if ($id === '' || strlen($id) > 255) {
                throw new \InvalidArgumentException('Typesense import document identity is invalid.');
            }
            if (isset($document['id']) && $document['id'] !== $id) {
                throw new \InvalidArgumentException('Typesense document ID must equal the canonical document identity.');
            }
            $document['id'] = $id;
            $ids[] = $id;
            $lines[] = $this->json($document);
        }
        $body = implode("\n", $lines) . "\n";
        if (strlen($body) > 4 * 1024 * 1024) {
            throw new \InvalidArgumentException('Typesense import batch exceeds 4 MiB.');
        }
        $response = $this->request('POST', '/collections/' . rawurlencode($collection) . '/documents/import?action=upsert', $body, [200], 'text/plain');
        $results = array_values(array_filter(preg_split('/\r?\n/', trim($response['body'])) ?: [], static fn (string $line): bool => $line !== ''));
        if (count($results) !== count($documents)) {
            throw new ProviderUnavailable('Typesense import result count mismatch.');
        }
        return array_map(function (string $line, int $index) use ($ids): array {
            try {
                $result = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $result = [];
            }
            $success = is_array($result) && ($result['success'] ?? false) === true;
            $error = $success ? null : 'typesense_import_' . substr(hash('sha256', (string) ($result['code'] ?? 'invalid_result')), 0, 12);
            return ['document_id' => $ids[$index], 'success' => $success, 'error_code' => $error];
        }, $results, array_keys($results));
    }

    /** @return array{active:string,previous:?string,changed:bool} */
    public function activate(string $collection): array
    {
        $this->collection($collection);
        $previous = $this->activeCollection();
        $changed = $previous !== $collection;
        if ($changed) {
            $this->request('PUT', '/aliases/' . rawurlencode($this->alias), $this->json(['collection_name' => $collection]), [200, 201]);
        }
        if ($this->activeCollection() !== $collection) {
            throw new ProviderUnavailable('Typesense alias verification failed.');
        }
        return ['active' => $collection, 'previous' => $previous, 'changed' => $changed];
    }

    /** @return array{active:string,previous:?string,changed:bool} */
    public function rollback(string $retainedCollection): array
    {
        return $this->activate($retainedCollection);
    }

    public function activeCollection(): ?string
    {
        $response = $this->request('GET', '/aliases/' . rawurlencode($this->alias), null, [200, 404]);
        if ($response['status'] === 404) {
            return null;
        }
        $body = $this->decoded($response['body']);
        $collection = (string) ($body['collection_name'] ?? '');
        $this->collection($collection);
        return $collection;
    }

    /** @return array{status:int,body:string,headers?:array<string,string>} */
    private function request(string $method, string $path, ?string $body, array $allowed, string $contentType = 'application/json'): array
    {
        $response = $this->transport->request($method, $this->endpoint . $path, [
            'Accept' => 'application/json',
            'Content-Type' => $contentType,
            'X-TYPESENSE-API-KEY' => $this->adminCredential->resolve(),
        ], $body, min(1000, $this->deadlineMs), $this->deadlineMs);
        if (!in_array($response['status'], $allowed, true)) {
            throw new ProviderUnavailable('Typesense control-plane request failed.');
        }
        return $response;
    }

    /** @return array<string,mixed> */
    private function decoded(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new ProviderUnavailable('Typesense control-plane response was invalid.');
        }
        if (!is_array($decoded)) {
            throw new ProviderUnavailable('Typesense control-plane response was invalid.');
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
