<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Configuration;

use InvalidArgumentException;
use Starfiniti\Search\Domain\Search\RelevancePolicy;
use Starfiniti\Search\Domain\Support\CanonicalJson;

final class DesiredConfiguration
{
    private const TOP_LEVEL = ['contract_version', 'revision', 'active_read_provider', 'write_targets', 'transport_policy', 'catalog', 'ranking', 'analytics', 'operations', 'provider_configuration_refs'];

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data)
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $unknown = array_diff(array_keys($data), self::TOP_LEVEL);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Configuration contains unsupported fields.');
        }
        foreach (['contract_version', 'revision', 'active_read_provider', 'write_targets', 'transport_policy', 'catalog', 'ranking', 'analytics', 'operations'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new InvalidArgumentException('Configuration is missing a required field.');
            }
        }
        if ($data['contract_version'] !== '1.0' || !is_int($data['revision']) || $data['revision'] < 1) {
            throw new InvalidArgumentException('Invalid configuration contract or revision.');
        }
        if (!in_array($data['active_read_provider'], ['local', 'typesense'], true)) {
            throw new InvalidArgumentException('Unsupported read provider.');
        }
        if (!is_array($data['write_targets']) || $data['write_targets'] === [] || array_diff($data['write_targets'], ['local', 'typesense']) !== []) {
            throw new InvalidArgumentException('Invalid write targets.');
        }
        $data['write_targets'] = array_values(array_unique($data['write_targets']));
        if (!in_array($data['active_read_provider'], $data['write_targets'], true)) {
            throw new InvalidArgumentException('The read provider must be a write target.');
        }
        self::validateTransport($data['transport_policy']);
        self::validateCatalog($data['catalog']);
        foreach (['ranking', 'analytics', 'operations'] as $object) {
            if (!is_array($data[$object])) {
                throw new InvalidArgumentException('Configuration contains an invalid object.');
            }
        }
        RelevancePolicy::validate($data['ranking']);
        if (!is_bool($data['analytics']['enabled'] ?? null) || !is_int($data['analytics']['retention_days'] ?? null)
            || $data['analytics']['retention_days'] < 0 || $data['analytics']['retention_days'] > 365
            || ($data['analytics']['enabled'] && $data['analytics']['retention_days'] < 1)) {
            throw new InvalidArgumentException('Invalid analytics retention policy.');
        }
        self::assertNoSecretValues($data);
        return new self($data);
    }

    public static function defaults(string $locale = 'en_US'): self
    {
        return self::fromArray([
            'contract_version' => '1.0',
            'revision' => 1,
            'active_read_provider' => 'local',
            'write_targets' => ['local'],
            'transport_policy' => ['mode' => 'wordpress_rest', 'allow_verified_local_fallback' => false, 'max_fallback_age_seconds' => 0],
            'catalog' => ['locales' => [$locale], 'variation_strategy' => 'variation_as_result', 'visibility_policy' => 'scope_tokens', 'price_policy' => 'per_price_scope', 'custom_fields' => []],
            'ranking' => RelevancePolicy::defaults(),
            'analytics' => ['enabled' => false, 'retention_days' => 0],
            'operations' => ['batch_size' => 50, 'query_deadline_ms' => 1500],
            'provider_configuration_refs' => [],
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    public function revision(): int
    {
        return $this->data['revision'];
    }

    public function checksum(): string
    {
        return hash('sha256', CanonicalJson::encode($this->data));
    }

    public function semanticChecksum(): string
    {
        $data = $this->data;
        unset($data['revision']);
        return hash('sha256', CanonicalJson::encode($data));
    }

    /** @param mixed $policy */
    private static function validateTransport(mixed $policy): void
    {
        if (!is_array($policy) || !in_array($policy['mode'] ?? null, ['wordpress_rest', 'local_fast_public', 'typesense_direct_public', 'typesense_proxy'], true) || !is_bool($policy['allow_verified_local_fallback'] ?? null)) {
            throw new InvalidArgumentException('Invalid transport policy.');
        }
        if (($policy['max_fallback_age_seconds'] ?? 0) < 0) {
            throw new InvalidArgumentException('Invalid fallback age.');
        }
    }

    /** @param mixed $catalog */
    private static function validateCatalog(mixed $catalog): void
    {
        if (!is_array($catalog) || !is_array($catalog['locales'] ?? null) || $catalog['locales'] === []) {
            throw new InvalidArgumentException('At least one locale is required.');
        }
        if (array_diff(array_keys($catalog), ['locales', 'variation_strategy', 'visibility_policy', 'price_policy', 'custom_fields']) !== []) {
            throw new InvalidArgumentException('Catalog policy contains unsupported fields.');
        }
        foreach ($catalog['locales'] as $locale) {
            if (!is_string($locale) || strlen($locale) > 32 || preg_match('/^[A-Za-z0-9_-]+$/', $locale) !== 1) {
                throw new InvalidArgumentException('Invalid catalog locale.');
            }
        }
        if (!in_array($catalog['variation_strategy'] ?? null, ['parent_collapsed', 'variation_as_result'], true)
            || !in_array($catalog['visibility_policy'] ?? null, ['public', 'scope_tokens', 'server_revalidate', 'separate_indexes'], true)
            || !in_array($catalog['price_policy'] ?? null, ['public_single', 'per_currency', 'per_price_scope', 'server_hydrate', 'hidden'], true)) {
            throw new InvalidArgumentException('Invalid catalog policy.');
        }
        $customFields = $catalog['custom_fields'] ?? [];
        if (!is_array($customFields) || count($customFields) > 64) {
            throw new InvalidArgumentException('Invalid custom field allow-list.');
        }
        $seen = [];
        foreach ($customFields as $field) {
            if (!is_array($field) || array_diff(array_keys($field), ['field', 'meta_key', 'type']) !== []
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/', (string) ($field['field'] ?? '')) !== 1
                || preg_match('/^[A-Za-z0-9_.:-]{1,191}$/', (string) ($field['meta_key'] ?? '')) !== 1
                || !in_array($field['type'] ?? null, ['string', 'integer', 'number', 'boolean'], true)
                || isset($seen[(string) $field['field']])) {
                throw new InvalidArgumentException('Invalid custom field allow-list entry.');
            }
            $seen[(string) $field['field']] = true;
        }
    }

    /** @param array<string, mixed> $data */
    private static function assertNoSecretValues(array $data): void
    {
        $walk = static function (mixed $value, string $path = '') use (&$walk): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $child) {
                $childPath = $path . '/' . (string) $key;
                if (preg_match('/(?:secret|password|api[_-]?key|private[_-]?key)$/i', (string) $key) === 1) {
                    throw new InvalidArgumentException('Secret material is prohibited in configuration.');
                }
                $walk($child, $childPath);
            }
        };
        $walk($data);
        foreach (($data['provider_configuration_refs'] ?? []) as $provider => $reference) {
            if (!is_string($reference) || preg_match('/^(?:constant|env):STARFINITI_[A-Z0-9_]{3,120}$/', $reference) !== 1) {
                throw new InvalidArgumentException('An external credential reference is invalid.');
            }
        }
    }
}
