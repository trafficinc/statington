<?php

declare(strict_types=1);

namespace Statington\Framework;

final class SlimMiddleware
{
    public function __invoke(mixed $request, mixed $handler): mixed
    {
        $uri = method_exists($request, 'getUri') ? (string) $request->getUri() : null;

        return GenericMiddleware::handle([
            'method' => method_exists($request, 'getMethod') ? $request->getMethod() : null,
            'uri' => $uri,
            'path' => $uri !== null ? (parse_url($uri, PHP_URL_PATH) ?: $uri) : null,
        ], static fn (): mixed => $handler->handle($request));
    }
}
