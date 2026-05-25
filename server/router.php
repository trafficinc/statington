<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Statington\\Server\\' => __DIR__ . '/',
        'Statington\\' => dirname(__DIR__) . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (str_starts_with($class, $prefix)) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

use Statington\Server\Database;
use Statington\Server\EventController;
use Statington\Server\RequestStore;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $store = new RequestStore((new Database())->pdo());
} catch (Throwable $exception) {
    renderCollectorDatabaseError($exception, $method, $path);
    return true;
}

if ($method === 'GET' && $path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    return true;
}

if ($method === 'POST' && $path === '/event') {
    (new EventController($store))->receive();
    return true;
}

if ($method === 'GET' && $path === '/api/requests') {
    header('Content-Type: application/json');
    echo json_encode($store->searchRequests([
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
    ]), JSON_UNESCAPED_SLASHES);
    return true;
}

if ($method === 'POST' && $path === '/api/clear') {
    $store->clear();
    header('Location: /', true, 303);
    return true;
}

if ($method === 'GET' && $path === '/api/request') {
    header('Content-Type: application/json');
    $requestId = (string) ($_GET['id'] ?? '');
    $detail = $requestId !== '' ? $store->requestDetail($requestId) : null;
    if (!$detail) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        return true;
    }

    echo json_encode($detail, JSON_UNESCAPED_SLASHES);
    return true;
}

if ($method === 'GET' && $path === '/api/request/export') {
    header('Content-Type: application/json');
    $requestId = (string) ($_GET['id'] ?? '');
    $detail = $requestId !== '' ? $store->requestDetail($requestId) : null;
    if (!$detail) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        return true;
    }

    header('Content-Disposition: attachment; filename="statington-request-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $requestId) . '.json"');
    echo json_encode([
        'exported_at' => date('c'),
        'statington' => 'request_bundle',
        'bundle' => $detail,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return true;
}

if ($method === 'GET' && $path === '/api/live') {
    header('Content-Type: application/json');
    $since = filter_var($_GET['since'] ?? 0, FILTER_VALIDATE_INT);
    echo json_encode($store->live($since === false ? 0 : $since), JSON_UNESCAPED_SLASHES);
    return true;
}

if ($path === '/request' || $path === '/request.php') {
    require dirname(__DIR__) . '/dashboard/public/request.php';
    return true;
}

$publicFile = dirname(__DIR__) . '/dashboard/public' . $path;

if ($method === 'GET' && $path !== '/' && is_file($publicFile) && pathinfo($publicFile, PATHINFO_EXTENSION) !== 'php') {
    $extension = pathinfo($publicFile, PATHINFO_EXTENSION);
    $types = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
    ];
    header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
    readfile($publicFile);
    return true;
}

if (is_file(dirname(__DIR__) . '/dashboard/public/index.php')) {
    require dirname(__DIR__) . '/dashboard/public/index.php';
    return true;
}

header('Location: /health', true, 302);
return true;

function renderCollectorDatabaseError(Throwable $exception, string $method, string $path): void
{
    http_response_code(500);

    if (str_starts_with($path, '/api/') || $path === '/event' || $method === 'POST') {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'collector_database_unavailable',
            'message' => $exception->getMessage(),
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    $storage = htmlspecialchars(__DIR__ . '/storage', ENT_QUOTES, 'UTF-8');
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statington - Collector Error</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="/">
            <span class="statington-dot"></span>
            <span>Statington</span>
        </a>
    </header>
    <main class="shell">
        <section class="panel error-state">
            <div class="panel-head">
                <h1>Collector database unavailable</h1>
            </div>
            <p>Statington could not open its local SQLite database.</p>
            <pre class="json">{$message}</pre>
            <p class="muted">Check that this directory is writable:</p>
            <pre class="json">{$storage}</pre>
        </section>
    </main>
</body>
</html>
HTML;
}
