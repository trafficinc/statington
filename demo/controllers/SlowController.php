<?php

declare(strict_types=1);

namespace Demo\Controllers;

use Demo\Services\FakeDb;
use Statington\Statington;

final class SlowController
{
    public function show(): string
    {
        usleep(350000);

        Statington::span('auth_check', static function (): void {
            usleep(5000);
        });

        (new FakeDb())->impactReport();

        Statington::span('transform', static function (): void {
            usleep(12000);
        });

        Statington::log('Slow report completed');

        return '<h1>Slow report</h1><p>This request intentionally crosses the slow request threshold.</p><p><a href="/">Back</a></p>';
    }
}
