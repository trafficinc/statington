<?php

declare(strict_types=1);

namespace Statington\Framework;

final class LaravelMiddleware
{
    public function handle(mixed $request, callable $next): mixed
    {
        return GenericMiddleware::handle([
            'method' => method_exists($request, 'method') ? $request->method() : null,
            'uri' => method_exists($request, 'fullUrl') ? $request->fullUrl() : null,
            'path' => method_exists($request, 'path') ? '/' . ltrim((string) $request->path(), '/') : null,
        ], static fn (): mixed => $next($request));
    }
}
