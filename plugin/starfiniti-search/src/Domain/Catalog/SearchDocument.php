<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Catalog;

use InvalidArgumentException;
use Starfiniti\Search\Domain\Support\CanonicalJson;

final readonly class SearchDocument
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        foreach (['contract_version', 'schema_version', 'document_id', 'entity_type', 'entity_id', 'visibility', 'identity', 'content', 'pricing', 'inventory', 'timestamps'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException('SearchDocument is missing a required field.');
            }
        }
        if ($data['contract_version'] !== '1.0' || !preg_match('/^[a-z][a-z0-9_-]*:.+$/', (string) $data['document_id'])) {
            throw new InvalidArgumentException('Invalid SearchDocument identity or contract version.');
        }

        unset($data['checksum']);
        $checksumData = $data;
        unset($checksumData['timestamps']['source_observed_at']);
        $data['checksum'] = hash('sha256', CanonicalJson::encode($checksumData));
        $this->data = $data;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function checksum(): string
    {
        return $this->data['checksum'];
    }

    public function id(): string
    {
        return $this->data['document_id'];
    }
}
