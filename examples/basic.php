<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Statington\Statington;

Statington::install();
Statington::log('Hello from Statington');
Statington::span('work', static function (): void {
    usleep(50000);
});
Statington::endRequest();
