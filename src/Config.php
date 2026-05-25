<?php

declare(strict_types=1);

namespace Statington;

final class Config
{
    private array $values;

    public function __construct(array $config = [])
    {
        $this->values = array_merge(
            $this->defaults(),
            $this->localConfig(),
            $this->appConfig(),
            $config
        );

        $this->values['endpoint'] = rtrim((string) $this->values['endpoint'], '/');
        $this->values['enabled'] = (bool) $this->values['enabled'];
        $this->values['capture_input'] = (bool) $this->values['capture_input'];
        $this->values['capture_headers'] = (bool) $this->values['capture_headers'];
        $this->values['slow_request_ms'] = (int) $this->values['slow_request_ms'];
        $this->values['redact_sensitive'] = (bool) $this->values['redact_sensitive'];
        $this->values['max_context_bytes'] = (int) $this->values['max_context_bytes'];
        $this->values['max_body_bytes'] = (int) $this->values['max_body_bytes'];
        $this->values['max_stacktrace_bytes'] = (int) $this->values['max_stacktrace_bytes'];
        $this->values['ignore_paths'] = $this->normalizePathPatterns($this->values['ignore_paths'] ?? []);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->values;
    }

    public function enabled(): bool
    {
        return $this->values['enabled'] === true;
    }

    public function shouldIgnorePath(string $path): bool
    {
        foreach ($this->values['ignore_paths'] as $pattern) {
            if ($this->pathMatches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public function sanitizerOptions(): array
    {
        return [
            'redact_sensitive' => $this->values['redact_sensitive'],
            'max_context_bytes' => $this->values['max_context_bytes'],
            'max_body_bytes' => $this->values['max_body_bytes'],
            'max_stacktrace_bytes' => $this->values['max_stacktrace_bytes'],
        ];
    }

    public function dbOptions(): array
    {
        $db = is_array($this->values['db'] ?? null) ? $this->values['db'] : [];

        return array_merge([
            'enabled' => false,
            'driver' => null,
            'capture_queries' => true,
            'capture_bindings' => false,
            'redact_bindings' => true,
            'track_mutations_only' => true,
            'max_query_bytes' => 8192,
            'ignore_tables' => ['sessions', 'sessions*', 'cache', 'cache_*', 'jobs', 'migrations'],
            'slow_query_ms' => 50,
            'capture_source' => true,
            'source_root' => null,
            'source_paths' => ['app', 'Modules'],
            'ignore_source_paths' => ['vendor', 'bootstrap', 'public', 'storage', 'server', 'dashboard', 'tests'],
            'ignore_source_classes' => ['Statington\\', 'PDO', 'PDOStatement'],
        ], $this->normalizeDbOptions($db));
    }

    private function defaults(): array
    {
        return $this->loadConfig(dirname(__DIR__) . '/config/statington.php');
    }

    private function localConfig(): array
    {
        return $this->loadConfig(dirname(__DIR__) . '/config/statington.local.php');
    }

    private function appConfig(): array
    {
        $cwd = getcwd();
        if (!$cwd) {
            return [];
        }

        $packageDefault = realpath(dirname(__DIR__) . '/config/statington.php');
        foreach ([$cwd . '/config/statington.php', $cwd . '/statington.php'] as $path) {
            if ($packageDefault !== false && realpath($path) === $packageDefault) {
                continue;
            }

            $config = $this->loadConfig($path);
            if ($config !== []) {
                return $config;
            }
        }

        return [];
    }

    private function loadConfig(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config) ? $config : [];
    }

    private function normalizeDbOptions(array $db): array
    {
        if (array_key_exists('driver', $db) && $db['driver'] !== null) {
            $driver = strtolower(trim((string) $db['driver']));
            $db['driver'] = $driver === '' ? null : $driver;
        }

        if (array_key_exists('source_root', $db) && $db['source_root'] !== null) {
            $resolved = realpath((string) $db['source_root']);
            $root = rtrim(str_replace('\\', '/', $resolved !== false ? $resolved : (string) $db['source_root']), '/');
            $db['source_root'] = $root === '' ? null : $root;
        }

        return $db;
    }

    private function normalizePathPatterns(mixed $patterns): array
    {
        if (!is_array($patterns)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $pattern): string => trim((string) $pattern),
            $patterns
        ), static fn (string $pattern): bool => $pattern !== ''));
    }

    private function pathMatches(string $path, string $pattern): bool
    {
        if ($pattern === $path) {
            return true;
        }

        if (!str_contains($pattern, '*')) {
            return false;
        }

        $regex = '#^' . str_replace('\\*', '.*', preg_quote($pattern, '#')) . '$#';

        return preg_match($regex, $path) === 1;
    }
}
