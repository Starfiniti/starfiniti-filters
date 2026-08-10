<?php

declare(strict_types=1);

namespace Starfiniti\Search\Infrastructure\WordPress\Operations;

use Closure;

final class StructuredLogger
{
    private const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];
    private const FORBIDDEN_KEYS = '/query|token|secret|password|authorization|cookie|api[_-]?key|credential|document|email|ip_address/i';

    public function __construct(private readonly ?Closure $sink = null)
    {
    }

    /** @param array<string,mixed> $context */
    public function log(string $level, string $eventCode, string $message, array $context = []): string
    {
        $level = in_array($level, self::LEVELS, true) ? $level : 'info';
        $correlationId = isset($context['correlation_id']) && is_string($context['correlation_id']) && preg_match('/^[0-9a-f-]{36}$/i', $context['correlation_id']) === 1
            ? strtolower($context['correlation_id'])
            : (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4());
        unset($context['correlation_id']);
        $record = [
            'timestamp' => gmdate(DATE_ATOM),
            'level' => $level,
            'event_code' => substr(preg_replace('/[^a-z0-9_.-]/', '', strtolower($eventCode)) ?: 'unknown', 0, 64),
            'message' => mb_substr($this->plainText($message), 0, 191),
            'correlation_id' => $correlationId,
            'operation_id' => $this->scalar($context['operation_id'] ?? null, 36),
            'provider' => $this->scalar($context['provider'] ?? null, 32),
            'index_version' => isset($context['index_version']) ? max(0, (int) $context['index_version']) : null,
            'site_id' => function_exists('get_option') ? substr(hash('sha256', (string) get_option('starfiniti_search_installation_uuid')), 0, 16) : null,
            'duration_ms' => isset($context['duration_ms']) ? max(0.0, min(60000.0, (float) $context['duration_ms'])) : null,
            'retryable' => (bool) ($context['retryable'] ?? false),
            'safe_context' => $this->redact(is_array($context['safe_context'] ?? null) ? $context['safe_context'] : []),
        ];
        $encoded = (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($this->sink !== null) {
            ($this->sink)($record);
        } elseif (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $encoded, ['source' => 'starfiniti-search']);
        } elseif (function_exists('do_action')) {
            do_action('starfiniti_search_structured_log', $record, $encoded);
        }
        return $correlationId;
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function redact(array $value): array
    {
        $safe = [];
        foreach (array_slice($value, 0, 32, true) as $key => $item) {
            $name = mb_substr((string) $key, 0, 64);
            if (preg_match(self::FORBIDDEN_KEYS, $name) === 1) {
                $safe[$name] = '[redacted]';
            } elseif (is_array($item)) {
                $safe[$name] = $this->redact($item);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $safe[$name] = $item;
            } else {
                $safe[$name] = mb_substr($this->plainText((string) $item), 0, 256);
            }
        }
        return $safe;
    }

    private function scalar(mixed $value, int $limit): ?string
    {
        return $value === null ? null : mb_substr((string) $value, 0, $limit);
    }

    private function plainText(string $value): string
    {
        return function_exists('wp_strip_all_tags')
            ? wp_strip_all_tags($value, true)
            : trim((string) preg_replace('/<[^>]*>/', '', $value));
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
