<?php

declare(strict_types=1);

return [
    'app' => 'default',
    'enabled' => true,
    'environment' => getenv('APP_ENV') ?: 'dev',
    'endpoint' => 'http://localhost:8123',
    'capture_input' => true,
    'capture_headers' => true,
    'slow_request_ms' => 200,
    'redact_sensitive' => true,
    'max_context_bytes' => 65536,
    'max_body_bytes' => 65536,
    'max_stacktrace_bytes' => 131072,
    'ignore_paths' => [
        '/favicon.ico',
        '/robots.txt',
        '/assets/*',
        '/build/*',
    ],
    'db' => [
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
    ],
];
