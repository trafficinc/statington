<?php

declare(strict_types=1);

namespace Statington;

use Statington\Database\PdoProxy;
use Statington\Util\Sanitizer;

final class Statington
{
    private static ?Config $config = null;
    private static ?RequestContext $request = null;
    private static ?Client $client = null;
    private static ?Logger $logger = null;
    private static ?Tracer $tracer = null;
    private static ?ErrorHandler $errorHandler = null;
    private static array $configured = [];
    private static bool $installed = false;
    private static bool $silent = false;
    private static bool $shutdownRegistered = false;
    private static bool $handlersRegistered = false;

    public static function configure(array $config): void
    {
        if (self::$installed) {
            return;
        }

        self::$configured = array_replace_recursive(self::$configured, $config);
    }

    public static function install(array $config = []): void
    {
        if (self::$installed) {
            return;
        }

        $config = array_replace_recursive(self::$configured, $config);
        $environment = (string) ($config['environment'] ?? getenv('APP_ENV') ?: 'dev');
        if ($environment === 'production' && ($config['enabled'] ?? null) !== true) {
            $config['enabled'] = false;
        }

        self::$config = new Config($config);
        if (!self::$config->enabled()) {
            self::$silent = true;
            self::$installed = true;
            return;
        }

        if (self::$config->shouldIgnorePath(self::currentPath())) {
            self::$silent = true;
            self::$installed = true;
            return;
        }

        self::bootRequest([]);
        self::$installed = true;

        self::registerHandlers();
        self::registerShutdown();
    }

    public static function auto(): void
    {
        $environment = getenv('APP_ENV') ?: 'dev';
        if ($environment === 'production') {
            return;
        }

        self::install([
            'environment' => $environment,
            'enabled' => true,
        ]);
    }

    public static function log(string $message, array $context = [], string $level = 'info'): void
    {
        self::ensureInstalled();
        if (self::$silent) {
            return;
        }

        self::$logger?->log($message, $context, $level);
    }

    public static function span(string $name, callable $callback): mixed
    {
        self::ensureInstalled();
        if (self::$silent || !self::$tracer) {
            $span = self::noopSpan($name);

            try {
                return $callback($span);
            } finally {
                $span->end();
            }
        }

        return self::$tracer?->span($name, $callback);
    }

    public static function startSpan(string $name): Span
    {
        self::ensureInstalled();
        if (self::$silent || !self::$tracer) {
            return self::noopSpan($name);
        }

        return self::$tracer->startSpan($name);
    }

    public static function captureException(\Throwable $e): void
    {
        self::ensureInstalled();
        if (self::$silent) {
            return;
        }

        self::$errorHandler?->captureException($e);
    }

    public static function startRequest(array $context = []): string
    {
        if (self::$silent) {
            return '';
        }

        if (!self::$installed) {
            self::$config = new Config(self::$configured);
            self::$installed = true;
        }

        if (!self::$config || !self::$config->enabled()) {
            self::$silent = true;
            return '';
        }

        if (self::$request && !self::$request->isEnded()) {
            return self::$request->id();
        }

        self::bootRequest($context);
        self::registerHandlers();
        self::registerShutdown();

        return self::$request?->id() ?? '';
    }

    private static function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return parse_url($uri, PHP_URL_PATH) ?: $uri;
    }

    public static function finishRequest(int $status = 200): void
    {
        self::ensureInstalled();
        if (self::$silent) {
            return;
        }

        self::$request?->setStatusCode($status);
        self::endRequest();
    }

    public static function endRequest(): void
    {
        if (self::$silent || !self::$installed || !self::$request || !self::$config || !self::$client) {
            return;
        }

        if (!self::$request->markEnded()) {
            return;
        }

        self::$tracer?->flushOpenSpans();
        self::$client->emit('request_end', self::$request->endPayload(self::$config));
    }

    public static function requestId(): ?string
    {
        if (self::$silent) {
            return null;
        }

        return self::$request?->id();
    }

    public static function recordQuery(string $sql, array $bindings = [], ?float $durationMs = null, ?int $affectedRows = null): void
    {
        self::ensureInstalled();

        $payload = self::queryPayload($sql, $bindings, $durationMs, $affectedRows);
        if ($payload === null) {
            return;
        }

        self::$client->emit('db_query', $payload);
    }

    public static function recordQueryError(string $sql, array $bindings, \Throwable $error, ?float $durationMs = null): void
    {
        self::ensureInstalled();

        $payload = self::queryPayload($sql, $bindings, $durationMs, null);
        if ($payload === null || !self::$client) {
            return;
        }

        $payload['is_error'] = true;
        $payload['error_class'] = $error::class;
        $payload['error_message'] = $error->getMessage();
        $payload['error_code'] = (string) $error->getCode();

        self::$client->emit('db_query_error', $payload);
    }

    public static function wrapPdo(\PDO $pdo): PdoProxy
    {
        self::ensureInstalled();

        return new PdoProxy($pdo);
    }

    private static function ensureInstalled(): void
    {
        if (!self::$installed) {
            self::install();
        }
    }

    private static function bootRequest(array $context): void
    {
        if (!self::$config || !self::$config->enabled()) {
            self::$silent = true;
            return;
        }

        self::$request = new RequestContext(
            self::$config->get('capture_input', true),
            self::$config->get('capture_headers', true),
            $context,
            self::$config->sanitizerOptions()
        );
        self::$client = new Client(self::$config);
        self::$logger = new Logger(self::$client, self::$request, self::$config->sanitizerOptions());
        self::$tracer = new Tracer(self::$client, self::$request);
        if (self::$errorHandler && self::$handlersRegistered) {
            self::$errorHandler->replaceContext(self::$client, self::$request);
        } else {
            self::$errorHandler = new ErrorHandler(
                self::$client,
                self::$request,
                \Closure::fromCallable([self::class, 'endRequest']),
                self::$config->sanitizerOptions()
            );
        }

        self::$client->emit('request_start', self::$request->startPayload());
    }

    private static function noopSpan(string $name): Span
    {
        return new Span('', $name, static function (): void {
        });
    }

    private static function registerShutdown(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function([self::class, 'endRequest']);
        self::$shutdownRegistered = true;
    }

    private static function registerHandlers(): void
    {
        if (self::$handlersRegistered || !self::$errorHandler) {
            return;
        }

        self::$errorHandler->register();
        self::$handlersRegistered = true;
    }

    private static function queryOperation(string $sql): string
    {
        if (preg_match('/^\s*(insert|update|delete|select)\b/i', $sql, $match) === 1) {
            return strtoupper($match[1]);
        }

        return 'OTHER';
    }

    private static function queryPayload(string $sql, array $bindings, ?float $durationMs, ?int $affectedRows): ?array
    {
        if (!self::$client || !self::$request || !self::$config) {
            return null;
        }

        $options = self::$config->dbOptions();
        if (!$options['enabled'] || !$options['capture_queries']) {
            return null;
        }

        $operation = self::queryOperation($sql);
        $driver = is_string($options['driver']) ? $options['driver'] : null;
        $isMutation = in_array($operation, ['INSERT', 'UPDATE', 'DELETE'], true);
        if ($options['track_mutations_only'] && !$isMutation) {
            return null;
        }

        $tables = self::queryTables($sql, $operation, $driver);
        if (self::shouldIgnoreTables($tables, $options['ignore_tables'] ?? [])) {
            return null;
        }

        $slowMs = (float) ($options['slow_query_ms'] ?? 50);

        $source = $options['capture_source'] ? self::querySource($options) : null;

        return [
            'request_id' => self::$request->id(),
            'driver' => $driver,
            'operation' => $operation,
            'tables' => $tables,
            'sql' => Sanitizer::truncate($sql, (int) $options['max_query_bytes']),
            'bindings' => self::sanitizeBindings($bindings, $options, self::$config->sanitizerOptions()),
            'source' => $source,
            'source_confidence' => is_array($source) ? (string) ($source['confidence'] ?? 'unknown') : 'unknown',
            'duration_ms' => $durationMs,
            'affected_rows' => $affectedRows,
            'is_mutation' => $isMutation,
            'is_slow' => $durationMs !== null && $durationMs >= $slowMs,
            'slow_query_ms' => $slowMs,
            'is_error' => false,
            'timestamp' => date('c'),
        ];
    }

    private static function queryTables(string $sql, string $operation, ?string $driver): array
    {
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            return [];
        }

        $patterns = [
            'INSERT' => '/\binsert\s+into\s+((?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+)(?:\s*\.\s*(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+))?)/i',
            'UPDATE' => '/\bupdate\s+((?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+)(?:\s*\.\s*(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+))?)/i',
            'DELETE' => '/\bdelete\s+from\s+((?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+)(?:\s*\.\s*(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+))?)/i',
            'SELECT' => '/\bfrom\s+((?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+)(?:\s*\.\s*(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[a-zA-Z0-9_]+))?)/i',
        ];

        $pattern = $patterns[$operation] ?? null;
        if (!$pattern || preg_match($pattern, $sql, $match) !== 1) {
            return [];
        }

        return [self::normalizeSqlIdentifier($match[1])];
    }

    private static function normalizeSqlIdentifier(string $identifier): string
    {
        $parts = preg_split('/\s*\.\s*/', trim($identifier)) ?: [];
        $parts = array_map(static function (string $part): string {
            return trim($part, "`\"[] \t\n\r\0\x0B");
        }, $parts);

        return implode('.', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private static function shouldIgnoreTables(array $tables, mixed $ignoreTables): bool
    {
        if ($tables === [] || !is_array($ignoreTables) || $ignoreTables === []) {
            return false;
        }

        $ignored = array_map(static fn (mixed $table): string => strtolower((string) $table), $ignoreTables);
        $ignored = array_filter($ignored, static fn (string $table): bool => $table !== '');
        if ($ignored === []) {
            return false;
        }

        foreach ($tables as $table) {
            $normalized = strtolower((string) $table);
            $base = str_contains($normalized, '.') ? substr($normalized, strrpos($normalized, '.') + 1) : $normalized;
            if (!self::tableMatchesAny($normalized, $ignored) && !self::tableMatchesAny($base, $ignored)) {
                return false;
            }
        }

        return true;
    }

    private static function tableMatchesAny(string $table, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::wildcardMatches($table, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function wildcardMatches(string $value, string $pattern): bool
    {
        if ($pattern === $value) {
            return true;
        }

        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $value) === 1;
    }

    private static function redactBindings(array $bindings): array
    {
        $redacted = [];
        foreach ($bindings as $key => $value) {
            $redacted[$key] = '[REDACTED]';
        }

        return $redacted;
    }

    private static function sanitizeBindings(array $bindings, array $dbOptions, array $sanitizerOptions): array
    {
        if (!$dbOptions['capture_bindings']) {
            return [];
        }

        if ((bool) $dbOptions['redact_bindings']) {
            return self::redactBindings($bindings);
        }

        return Sanitizer::clean($bindings, [
            ...$sanitizerOptions,
            'redact_sensitive' => false,
        ]);
    }

    private static function querySource(array $dbOptions): ?array
    {
        $configuredRoot = is_string($dbOptions['source_root'] ?? null) ? (string) $dbOptions['source_root'] : '';
        $root = self::normalizePath($configuredRoot !== '' ? $configuredRoot : (getcwd() ?: dirname(__DIR__)));
        $sourcePaths = self::pathList($dbOptions['source_paths'] ?? []);
        $ignorePaths = self::pathList($dbOptions['ignore_source_paths'] ?? []);
        $ignoreClasses = self::classList($dbOptions['ignore_source_classes'] ?? []);
        $fallback = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? self::normalizePath($frame['file']) : null;
            if (!$file || str_contains($file, self::normalizePath(dirname(__DIR__) . '/src'))) {
                continue;
            }

            $class = isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null;
            if ($class !== null && self::matchesClassPrefix($class, $ignoreClasses)) {
                continue;
            }

            $relative = self::relativePath($file, $root);
            if ($relative === null || self::matchesPathPrefix($relative, $ignorePaths)) {
                continue;
            }

            $source = [
                'file' => $relative,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'class' => isset($frame['class']) && is_string($frame['class']) ? $frame['class'] : null,
                'function' => isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : null,
                'confidence' => 'fallback',
            ];

            if (self::matchesPathPrefix($relative, $sourcePaths)) {
                $source['confidence'] = 'source';
                return $source;
            }

            $fallback ??= $source;
        }

        return $fallback ?? ['confidence' => 'unknown'];
    }

    private static function pathList(mixed $paths): array
    {
        if (!is_array($paths)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $path): string => trim(str_replace('\\', '/', (string) $path), '/'),
            $paths
        ), static fn (string $path): bool => $path !== ''));
    }

    private static function classList(mixed $classes): array
    {
        if (!is_array($classes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $class): string => ltrim((string) $class, '\\'),
            $classes
        ), static fn (string $class): bool => $class !== ''));
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function relativePath(string $file, string $root): ?string
    {
        $root = rtrim($root, '/') . '/';

        if (str_starts_with($file, $root)) {
            return ltrim(substr($file, strlen($root)), '/');
        }

        return null;
    }

    private static function matchesPathPrefix(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function matchesClassPrefix(string $class, array $prefixes): bool
    {
        $class = ltrim($class, '\\');
        foreach ($prefixes as $prefix) {
            if ($class === rtrim($prefix, '\\') || str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
