<?php

declare(strict_types=1);

use Statington\Statington;
use Wayfinder\Database\Database;
use Wayfinder\Database\QueryExecuted;
use Wayfinder\Support\Container;

/*
 * Wayfinder / Research Capture example.
 *
 * Put Statington::install() after your app config is loaded in bootstrap/container.php.
 * Then attach the Database::listen() callback inside the Database singleton.
 */

Statington::install([
    'app' => 'researchcapture',
    'endpoint' => 'http://localhost:8123',
    'ignore_paths' => [
        '/favicon.ico',
        '/robots.txt',
        '/health',
        '/assets/*',
        '/build/*',
    ],
    'db' => [
        'enabled' => true,
        'driver' => (string) $config->get('database.connections.default.driver', 'sqlite'),
        'capture_queries' => true,
        'capture_bindings' => true,
        'redact_bindings' => false,
        'track_mutations_only' => false,
        'ignore_tables' => ['sessions', 'cache', 'jobs', 'migrations'],
        'slow_query_ms' => 50,
        'capture_source' => true,
        'source_root' => dirname(__DIR__),
        'source_paths' => ['app', 'Modules'],
        'ignore_source_paths' => ['vendor', 'bootstrap', 'public', 'storage'],
        'ignore_source_classes' => ['Statington\\', 'PDO', 'PDOStatement'],
    ],
]);

$container->singleton(Database::class, static function (Container $container): Database {
    $database = $container->get(\Wayfinder\Database\DatabaseManager::class)->connection();

    $database->listen(static function (QueryExecuted $query): void {
        Statington::recordQuery(
            sql: $query->sql,
            bindings: $query->bindings,
            durationMs: $query->milliseconds,
        );
    });

    return $database;
});
