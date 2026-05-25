<?php

declare(strict_types=1);

if (is_file(dirname(__DIR__) . '/dashboard/public/index.php')) {
    require dirname(__DIR__) . '/dashboard/public/index.php';
    return;
}

header('Location: /health', true, 302);
