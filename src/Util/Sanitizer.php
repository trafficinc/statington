<?php

declare(strict_types=1);

namespace Statington\Util;

final class Sanitizer
{
    private const TRUNCATED = '... [truncated]';

    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pwd',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'set-cookie',
        'api_key',
        'apikey',
        'private_key',
        'credit_card',
        'card_number',
        'ssn',
    ];

    public static function clean(mixed $value, array $options = [], int $depth = 0): mixed
    {
        $options = self::options($options);

        if ($depth > 8) {
            return '[depth-limit]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $item) {
                if ($options['redact_sensitive'] && self::isSensitive((string) $key)) {
                    $clean[$key] = '[REDACTED]';
                    continue;
                }

                $clean[$key] = self::clean($item, $options, $depth + 1);
            }

            return $clean;
        }

        if (is_object($value)) {
            if ($value instanceof \Throwable) {
                return [
                    'class' => $value::class,
                    'message' => self::truncate($value->getMessage(), $options['max_context_bytes']),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            }

            return self::clean(get_object_vars($value), $options, $depth + 1);
        }

        if (is_string($value)) {
            return self::truncate($value, $options['max_context_bytes']);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[' . gettype($value) . ']';
    }

    public static function body(string $body, array $options = []): ?string
    {
        if ($body === '') {
            return null;
        }

        $options = self::options($options);

        return self::truncate($body, $options['max_body_bytes']);
    }

    public static function capturedBody(string $body, ?string $contentType, array $options = []): mixed
    {
        $options = self::options($options);
        if ($body === '') {
            return null;
        }

        if (str_contains(strtolower((string) $contentType), 'json')) {
            $decoded = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return self::clean($decoded, [
                    ...$options,
                    'max_context_bytes' => $options['max_body_bytes'],
                ]);
            }
        }

        return self::truncate($body, $options['max_body_bytes']);
    }

    public static function stacktrace(mixed $trace, array $options = []): string
    {
        $options = self::options($options);
        $trace = self::clean($trace, $options);
        $json = json_encode($trace, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return self::truncate($json === false ? '[]' : $json, $options['max_stacktrace_bytes']);
    }

    public static function jsonEncode(mixed $value, array $options = []): string
    {
        $json = json_encode(self::clean($value, $options), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{}' : $json;
    }

    public static function truncate(string $value, int $maxBytes): string
    {
        if ($maxBytes <= 0) {
            return self::TRUNCATED;
        }

        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $suffix = self::TRUNCATED;
        $length = max(0, $maxBytes - strlen($suffix));

        return substr($value, 0, $length) . $suffix;
    }

    private static function options(array $options): array
    {
        return array_merge([
            'redact_sensitive' => true,
            'max_context_bytes' => 65536,
            'max_body_bytes' => 65536,
            'max_stacktrace_bytes' => 131072,
        ], $options);
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', ' '], '-', $key));
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($normalized, str_replace('_', '-', $sensitive))) {
                return true;
            }
        }

        return false;
    }
}
