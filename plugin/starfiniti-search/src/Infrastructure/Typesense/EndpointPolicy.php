<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

use InvalidArgumentException;

final class EndpointPolicy
{
    public static function validate(string $endpoint, bool $allowPrivateNetwork = false): string
    {
        if (function_exists('wp_parse_url')) {
            $parts = wp_parse_url($endpoint);
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure-PHP provider policy is also exercised outside WordPress.
            $parts = parse_url($endpoint);
        }
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
            throw new InvalidArgumentException('Typesense endpoint must be an absolute HTTPS URL.');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Endpoint credentials, query strings, and fragments are prohibited.');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (in_array($host, ['localhost', 'metadata.google.internal'], true) || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('Localhost and metadata hosts are prohibited.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            self::assertAddress($host, $allowPrivateNetwork);
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $trimmedPath = trim((string) ($parts['path'] ?? ''), '/');
        $path = $trimmedPath !== '' ? '/' . $trimmedPath : '';
        return 'https://' . $host . $port . $path;
    }

    /** @param list<string> $addresses */
    public static function assertResolvedAddresses(array $addresses, bool $allowPrivateNetwork = false): void
    {
        if ($addresses === []) {
            throw new InvalidArgumentException('Endpoint hostname did not resolve.');
        }
        foreach ($addresses as $address) {
            self::assertAddress($address, $allowPrivateNetwork);
        }
    }

    private static function assertAddress(string $address, bool $allowPrivateNetwork): void
    {
        if (in_array($address, ['169.254.169.254', '100.100.100.200'], true)) {
            throw new InvalidArgumentException('Cloud metadata addresses are prohibited.');
        }
        $public = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$allowPrivateNetwork && $public === false) {
            throw new InvalidArgumentException('Private or reserved endpoint addresses are prohibited.');
        }
        if ($allowPrivateNetwork && (str_starts_with($address, '169.254.') || $address === '::1')) {
            throw new InvalidArgumentException('Link-local and loopback endpoints remain prohibited.');
        }
    }
}
