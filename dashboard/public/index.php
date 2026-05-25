<?php

declare(strict_types=1);

$query = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'method' => trim((string) ($_GET['method'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'app' => trim((string) ($_GET['app'] ?? '')),
    'environment' => trim((string) ($_GET['environment'] ?? '')),
    'has_errors' => isset($_GET['has_errors']) ? '1' : '',
    'is_slow' => isset($_GET['is_slow']) ? '1' : '',
    'db_slow' => isset($_GET['db_slow']) ? '1' : '',
    'db_failed' => isset($_GET['db_failed']) ? '1' : '',
    'hide_noise' => isset($_GET['hide_noise']) ? '1' : '',
    'page' => max(1, (int) ($_GET['page'] ?? 1)),
    'per_page' => max(10, (int) ($_GET['per_page'] ?? 50)),
];
$result = $store->searchRequests($query);
$requests = $result['requests'];
$filterOptions = $store->filterOptions();
$title = 'Requests';
$view = dirname(__DIR__) . '/views/requests.php';
require dirname(__DIR__) . '/views/layout.php';
