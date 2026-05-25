<?php

declare(strict_types=1);

use Demo\Controllers\AuthController;
use Demo\Controllers\ErrorController;
use Demo\Controllers\SlowController;
use Demo\Controllers\UserController;
use Statington\Statington;

require __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Demo\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $relative = preg_replace_callback('#^(Controllers|Services)/#', static fn (array $match): string => strtolower($match[1]) . '/', $relative);
    $file = dirname(__DIR__) . '/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Statington::install([
    'app' => 'statington-demo',
    'endpoint' => 'http://localhost:8123',
    'capture_input' => true,
    'capture_headers' => true,
    'db' => [
        'enabled' => true,
        'driver' => 'sqlite',
        'capture_bindings' => true,
        'redact_bindings' => false,
        'track_mutations_only' => false,
    ],
]);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    $response = match ($path) {
        '/' => home(),
        '/users' => (new UserController())->index(),
        '/db-impact' => (new UserController())->databaseImpact(),
        '/db-select' => (new UserController())->complexSelect(),
        '/login-fail' => (new AuthController())->loginFail(),
        '/redacted' => (new AuthController())->redacted(),
        '/redacted-submit' => (new AuthController())->redactedSubmit(),
        '/slow' => (new SlowController())->show(),
        '/error' => (new ErrorController())->explode(),
        '/fatal' => (new ErrorController())->fatal(),
        default => notFound(),
    };

    echo $response;
} finally {
    Statington::endRequest();
}

function home(): string
{
    Statington::log('Demo home rendered');

    return page('Statington Demo', [
        ['/', 'Home', 'Render a normal request with a small log.'],
        ['/users', 'Users', 'Run fake database and cache spans.'],
        ['/db-impact', 'Database impact', 'Generate INSERT, UPDATE, and SELECT events with in-memory SQLite.'],
        ['/db-select', 'Complex SELECT', 'Run a joined/grouped SELECT with bindings and show it in Database Impact.'],
        ['/login-fail', 'Login fail', 'Capture a warning log and failed auth span.'],
        ['/redacted?token=demo-token&api_key=demo-key&safe=visible', 'Redacted data', 'Show sensitive query, header, body, and log context redaction.'],
        ['/slow', 'Slow request', 'Generate a slow request highlight.'],
        ['/error', 'Error', 'Capture an exception and 500 response.'],
        ['/fatal', 'Fatal error', 'Trigger fatal error capture with an undefined function.'],
    ]);
}

function notFound(): string
{
    http_response_code(404);
    Statington::log('Route not found', ['path' => $_SERVER['REQUEST_URI'] ?? '/'], 'warning');

    return '<h1>Not found</h1>';
}

function page(string $title, array $links): string
{
    $items = '';
    foreach ($links as [$href, $label, $description]) {
        $items .= '<a class="route" href="' . htmlspecialchars($href, ENT_QUOTES) . '"><strong>' . htmlspecialchars($label, ENT_QUOTES) . '</strong><span>' . htmlspecialchars($description, ENT_QUOTES) . '</span></a>';
    }

    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title><style>
        body{font:15px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f6f7f9;color:#17202a}
        main{width:min(760px,calc(100vw - 32px));margin:48px auto}
        h1{font-size:32px;margin:0 0 8px}
        p{color:#657386;margin:0 0 24px}
        .grid{display:grid;gap:12px}
        .route{display:grid;gap:4px;padding:16px;border:1px solid #dfe5ec;border-radius:8px;background:#fff;color:inherit;text-decoration:none}
        .route:hover{border-color:#0f8b8d}
        .route span{color:#657386}
    </style></head><body><main><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1><p>Open the Statington dashboard at <code>http://localhost:8123</code>, then click these routes.</p><div class="grid">' . $items . '</div></main></body></html>';
}
