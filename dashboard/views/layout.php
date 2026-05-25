<?php

declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function json_pretty(mixed $json): string
{
    $decoded = is_string($json) ? json_decode($json, true) : $json;
    return json_encode($decoded ?? $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
}

function bytes_human(mixed $bytes): string
{
    $bytes = (int) $bytes;
    if ($bytes <= 0) {
        return '-';
    }

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 2) . ' MB';
    }

    return round($bytes / 1024, 1) . ' KB';
}

function status_class(mixed $status): string
{
    $status = (int) $status;
    if ($status >= 500) {
        return 'bad';
    }

    if ($status >= 400) {
        return 'warn';
    }

    if ($status >= 300) {
        return 'redirect';
    }

    return 'ok';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Statington') ?> - Statington</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
    <header class="topbar">
        <a class="brand" href="/">
            <span class="statington-dot"></span>
            <span>Statington</span>
        </a>
        <div class="topbar-meta">
            <span data-live-count="requests">0</span> requests
            <span data-live-count="errors">0</span> errors
            <span data-live-status>polling</span>
            <label class="theme-picker">
                <span>Theme</span>
                <select data-theme-select>
                    <option value="light">Light</option>
                    <option value="dark">Dark</option>
                    <option value="ubuntu">Ubuntu</option>
                    <option value="oceancity">OceanCity</option>
                </select>
            </label>
        </div>
    </header>
    <main class="shell">
        <?php require $view; ?>
    </main>
    <script src="/assets/app.js"></script>
</body>
</html>
