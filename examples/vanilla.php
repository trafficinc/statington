<?php

declare(strict_types=1);

require 'vendor/autoload.php';

use Statington\Statington;

// Raw PHP front controller example.
// Put this near the top of public/index.php before your router runs.
Statington::install();

try {
    Statington::log('Hello from vanilla PHP');

    // require __DIR__ . '/../app/router.php';
    echo 'Hello from your PHP app';
} catch (Throwable $e) {
    Statington::captureException($e);
    Statington::finishRequest(500);

    throw $e;
}
