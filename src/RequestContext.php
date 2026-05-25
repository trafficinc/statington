<?php

declare(strict_types=1);

namespace Statington;

use Statington\Util\Sanitizer;

final class RequestContext
{
    private string $requestId;
    private float $startedAt;
    private array $server;
    private array $query;
    private array $post;
    private ?string $rawBody;
    private mixed $jsonBody;
    private bool $ended = false;
    private bool $captureInput;
    private bool $captureHeaders;
    private array $context;
    private ?int $statusCode = null;
    private array $sanitizerOptions;
    private mixed $body;

    public function __construct(bool $captureInput = true, bool $captureHeaders = true, array $context = [], array $sanitizerOptions = [])
    {
        $this->requestId = bin2hex(random_bytes(16));
        $this->startedAt = microtime(true);
        $this->server = $_SERVER ?? [];
        $this->query = $_GET ?? [];
        $this->post = $_POST ?? [];
        $this->captureInput = $captureInput;
        $this->captureHeaders = $captureHeaders;
        $this->sanitizerOptions = $sanitizerOptions;
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '');
        $input = $captureInput ? (string) @file_get_contents('php://input') : '';
        $this->rawBody = $captureInput ? Sanitizer::body($input, $sanitizerOptions) : null;
        $this->jsonBody = $this->decodeJsonBody($input);
        $this->body = $captureInput ? Sanitizer::capturedBody($input, $contentType, $sanitizerOptions) : null;
        $this->context = Sanitizer::clean($context, $sanitizerOptions);
    }

    public function id(): string
    {
        return $this->requestId;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    public function durationMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    public function markEnded(): bool
    {
        if ($this->ended) {
            return false;
        }

        $this->ended = true;

        return true;
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function forceStatusCode(int $statusCode): void
    {
        if ($this->statusCode === null || $this->statusCode < $statusCode) {
            $this->statusCode = $statusCode;
        }
    }

    public function startPayload(): array
    {
        $method = $this->method();
        $uri = $this->uri();

        return [
            'request_id' => $this->requestId,
            'method' => $method,
            'uri' => $uri,
            'path' => $this->path($uri),
            'query_string' => PHP_SAPI === 'cli' ? '' : (string) ($this->server['QUERY_STRING'] ?? ''),
            'ip' => $this->server['REMOTE_ADDR'] ?? null,
            'user_agent' => $this->server['HTTP_USER_AGENT'] ?? null,
            'headers' => $this->captureHeaders ? Sanitizer::clean($this->headers(), $this->sanitizerOptions) : [],
            'query_params' => Sanitizer::clean($this->query, $this->sanitizerOptions),
            'post_params' => $this->captureInput ? Sanitizer::clean($this->post, $this->sanitizerOptions) : [],
            'raw_json_body' => $this->jsonBody === null ? null : $this->rawBody,
            'json_body' => $this->jsonBody === null ? null : Sanitizer::clean($this->jsonBody, $this->sanitizerOptions),
            'body' => $this->body,
            'started_at' => $this->timestamp($this->startedAt),
            'memory_usage_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
            'context' => $this->context,
        ];
    }

    public function endPayload(Config $config): array
    {
        $statusCode = $this->statusCode();
        $durationMs = $this->durationMs();
        $slowMs = (int) $config->get('slow_request_ms', 200);

        return array_merge($this->startPayload(), [
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'is_slow' => $durationMs >= $slowMs,
            'slow_request_ms' => $slowMs,
            'ended_at' => date('c'),
            'memory_usage_end_bytes' => memory_get_usage(true),
            'memory_peak_bytes' => memory_get_peak_usage(true),
        ]);
    }

    private function statusCode(): int
    {
        if ($this->statusCode !== null) {
            return $this->statusCode;
        }

        if (PHP_SAPI === 'cli') {
            return 0;
        }

        $status = http_response_code();

        return is_int($status) ? $status : 200;
    }

    private function method(): string
    {
        return PHP_SAPI === 'cli' ? 'CLI' : (string) ($this->server['REQUEST_METHOD'] ?? 'GET');
    }

    private function uri(): string
    {
        if (PHP_SAPI === 'cli') {
            return implode(' ', $_SERVER['argv'] ?? []);
        }

        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    private function path(string $uri): string
    {
        if (PHP_SAPI === 'cli') {
            return basename((string) ($_SERVER['argv'][0] ?? 'cli'));
        }

        return parse_url($uri, PHP_URL_PATH) ?: $uri;
    }

    private function timestamp(float $time): string
    {
        return date('c', (int) $time);
    }

    private function decodeJsonBody(?string $body): mixed
    {
        if ($body === null || trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function headers(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }

        $headers = [];
        foreach ($this->server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr((string) $key, 5)))));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}
