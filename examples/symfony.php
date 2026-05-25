<?php

declare(strict_types=1);

use Statington\Statington;

// Copy-paste example only. This file does not require Symfony as a dependency.
//
// Put this class in your Symfony app, for example:
// src/EventSubscriber/StatingtonSubscriber.php
//
// Register it as an event subscriber service.
final class StatingtonEventSubscriber
{
    public static function getSubscribedEvents(): array
    {
        return [
            'kernel.request' => 'onKernelRequest',
            'kernel.response' => 'onKernelResponse',
            'kernel.exception' => 'onKernelException',
        ];
    }

    public function onKernelRequest($event): void
    {
        $request = $event->getRequest();

        Statington::configure([
            'app' => $_ENV['APP_NAME'] ?? 'symfony-app',
            'environment' => $_ENV['APP_ENV'] ?? 'dev',
            'endpoint' => $_ENV['STATINGTON_ENDPOINT'] ?? 'http://localhost:8123',
        ]);

        Statington::startRequest([
            'method' => $request->getMethod(),
            'uri' => $request->getUri(),
            'path' => $request->getPathInfo(),
        ]);
    }

    public function onKernelResponse($event): void
    {
        Statington::finishRequest($event->getResponse()->getStatusCode());
    }

    public function onKernelException($event): void
    {
        Statington::captureException($event->getThrowable());
        Statington::finishRequest(500);
    }
}
