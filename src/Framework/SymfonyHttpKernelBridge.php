<?php

declare(strict_types=1);

namespace Statington\Framework;

use Statington\Statington;
use Throwable;

final class SymfonyHttpKernelBridge
{
    public function onRequest(mixed $event): void
    {
        $request = method_exists($event, 'getRequest') ? $event->getRequest() : null;

        Statington::startRequest([
            'method' => is_object($request) && method_exists($request, 'getMethod') ? $request->getMethod() : null,
            'uri' => is_object($request) && method_exists($request, 'getUri') ? $request->getUri() : null,
            'path' => is_object($request) && method_exists($request, 'getPathInfo') ? $request->getPathInfo() : null,
        ]);
    }

    public function onResponse(mixed $event): void
    {
        $response = method_exists($event, 'getResponse') ? $event->getResponse() : null;
        $status = is_object($response) && method_exists($response, 'getStatusCode')
            ? (int) $response->getStatusCode()
            : 200;

        Statington::finishRequest($status);
    }

    public function onException(mixed $event): void
    {
        $throwable = method_exists($event, 'getThrowable') ? $event->getThrowable() : null;
        if (!$throwable instanceof Throwable && method_exists($event, 'getException')) {
            $throwable = $event->getException();
        }

        if ($throwable instanceof Throwable) {
            Statington::captureException($throwable);
        }

        Statington::finishRequest(500);
    }
}
