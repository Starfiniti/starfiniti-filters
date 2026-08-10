<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\Typesense;

final class WordPressTypesenseTransport implements TypesenseTransport
{
    /** @param array<string, string> $headers @return array{status:int,body:string,headers:array<string,string>} */
    public function request(string $method, string $url, array $headers, ?string $body, int $connectTimeoutMs, int $totalTimeoutMs): array
    {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        $addresses = [];
        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                } elseif (isset($record['ipv6'])) {
                    $addresses[] = (string) $record['ipv6'];
                }
            }
        }
        EndpointPolicy::assertResolvedAddresses(array_values(array_unique($addresses)));
        $response = wp_safe_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => max(0.025, $totalTimeoutMs / 1000),
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 2 * 1024 * 1024,
            'user-agent' => 'Starfiniti-Search/' . STARFINITI_SEARCH_VERSION,
        ]);
        if (is_wp_error($response)) {
            throw new ProviderUnavailable('Typesense transport failed.');
        }
        $responseHeaders = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $responseHeaders[strtolower((string) $name)] = (string) $value;
        }
        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
            'headers' => $responseHeaders,
        ];
    }
}
