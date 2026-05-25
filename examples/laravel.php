<?php

declare(strict_types=1);

use Statington\Statington;

// Copy-paste example only. This file does not require Laravel as a dependency.
//
// Put this class in your Laravel app, for example:
// app/Http/Middleware/StatingtonMiddleware.php
//
// Then register it in your HTTP kernel or route middleware stack.
final class StatingtonMiddleware
{
    public function handle($request, \Closure $next)
    {
        Statington::configure([
            'app' => env('APP_NAME', 'laravel-app'),
            'environment' => env('APP_ENV', 'local'),
            'endpoint' => env('STATINGTON_ENDPOINT', 'http://localhost:8123'),
        ]);

        Statington::startRequest([
            'method' => $request->method(),
            'uri' => $request->fullUrl(),
            'path' => $request->path(),
        ]);

        try {
            $response = $next($request);
            Statington::finishRequest($response->getStatusCode());

            return $response;
        } catch (\Throwable $e) {
            Statington::captureException($e);
            Statington::finishRequest(500);

            throw $e;
        }
    }
}
