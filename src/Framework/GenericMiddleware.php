<?php

declare(strict_types=1);

namespace Statington\Framework;

use Statington\Statington;
use Throwable;

final class GenericMiddleware
{
    /**
     * @template T
     * @param callable(): T $next
     * @return T
     */
    public static function handle(array $context, callable $next, ?callable $statusResolver = null): mixed
    {
        Statington::startRequest($context);

        try {
            $response = $next();
            Statington::finishRequest(self::resolveStatus($response, $statusResolver));

            return $response;
        } catch (Throwable $throwable) {
            Statington::captureException($throwable);
            Statington::finishRequest(500);
            throw $throwable;
        }
    }

    private static function resolveStatus(mixed $response, ?callable $statusResolver): int
    {
        if ($statusResolver !== null) {
            return (int) $statusResolver($response);
        }

        if (is_object($response) && method_exists($response, 'getStatusCode')) {
            return (int) $response->getStatusCode();
        }

        return 200;
    }
}
