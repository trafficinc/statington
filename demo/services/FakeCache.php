<?php

declare(strict_types=1);

namespace Demo\Services;

use Statington\Statington;

final class FakeCache
{
    public function lookup(string $key): mixed
    {
        return Statington::span('cache_lookup', static function () use ($key): mixed {
            usleep(12000);

            return null;
        });
    }
}
