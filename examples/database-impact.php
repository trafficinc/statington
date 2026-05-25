<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Statington\Statington;

Statington::install([
    'app' => 'sqlite-database-impact-example',
    'db' => [
        'enabled' => true,
        'driver' => 'sqlite',
        'capture_bindings' => true,
        'redact_bindings' => false,
        'ignore_tables' => ['sessions', 'cache', 'jobs', 'migrations'],
        'slow_query_ms' => 50,
        'capture_source' => true,
        'source_root' => dirname(__DIR__),
    ],
]);

$pdo = Statington::wrapPdo(new PDO('sqlite::memory:'));
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');

$statement = $pdo->prepare('INSERT INTO users (name) VALUES (:name)');
$statement->execute([':name' => 'Ada']);

Statington::finishRequest();
