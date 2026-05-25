<?php

declare(strict_types=1);

namespace Demo\Controllers;

use Statington\Statington;

final class ErrorController
{
    public function explode(): string
    {
        http_response_code(500);
        Statington::log('About to throw demo exception');
        throw new \RuntimeException('Demo exception from Statington');
    }

    public function fatal(): string
    {
        http_response_code(500);
        Statington::log('About to trigger demo fatal error');

        undefined_statington_demo_function();
    }
}
