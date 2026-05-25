<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Statington\Statington;

Statington::install([
    'app' => 'mysql-database-impact-example',
    'db' => [
        'enabled' => true,
        'driver' => 'mysql',
        'capture_bindings' => true,
        'redact_bindings' => true,
        'ignore_tables' => ['sessions', 'cache', 'jobs', 'migrations'],
        'slow_query_ms' => 50,
        'capture_source' => true,
        'source_root' => dirname(__DIR__),
    ],
]);

$pdo = Statington::wrapPdo(new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4',
    'app',
    'secret',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
));

$statement = $pdo->prepare('UPDATE `users` SET `login_count` = `login_count` + 1 WHERE `id` = :id');
$statement->execute(['id' => 42]);

Statington::finishRequest();
