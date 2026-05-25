<?php

declare(strict_types=1);

use Statington\Statington;

// Copy-paste example only. This file does not require Slim or PSR packages as dependencies.
//
// Put this near your Slim app setup, then add it with:
// $app->add($statingtonMiddleware);
$statingtonMiddleware = function ($request, $handler) {
    Statington::configure([
        'app' => $_ENV['APP_NAME'] ?? 'slim-app',
        'environment' => $_ENV['APP_ENV'] ?? 'dev',
        'endpoint' => $_ENV['STATINGTON_ENDPOINT'] ?? 'http://localhost:8123',
    ]);

    Statington::startRequest([
        'method' => $request->getMethod(),
        'uri' => (string) $request->getUri(),
        'path' => $request->getUri()->getPath(),
    ]);

    try {
        $response = $handler->handle($request);
        Statington::finishRequest($response->getStatusCode());

        return $response;
    } catch (\Throwable $e) {
        Statington::captureException($e);
        Statington::finishRequest(500);

        throw $e;
    }
};

// $app->add($statingtonMiddleware);
