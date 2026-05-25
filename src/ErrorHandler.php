<?php

declare(strict_types=1);

namespace Statington;

use Statington\Util\Sanitizer;

final class ErrorHandler
{
    private $previousErrorHandler = null;
    private $previousExceptionHandler = null;
    private bool $capturedUncaughtException = false;

    public function __construct(
        private Client $client,
        private RequestContext $request,
        private ?\Closure $onShutdown = null,
        private array $sanitizerOptions = [],
    )
    {
    }

    public function register(): void
    {
        $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function replaceContext(Client $client, RequestContext $request): void
    {
        $this->client = $client;
        $this->request = $request;
        $this->capturedUncaughtException = false;
    }

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        $this->capture('error', $message, $file, $line, [
            'severity' => $severity,
            'name' => $this->severityName($severity),
        ]);

        if ($this->previousErrorHandler) {
            return (bool) call_user_func($this->previousErrorHandler, $severity, $message, $file, $line);
        }

        return false;
    }

    public function handleException(\Throwable $exception): void
    {
        $this->capturedUncaughtException = true;
        $this->capture('exception', $exception->getMessage(), $exception->getFile(), $exception->getLine(), [
            'class' => $exception::class,
            'trace' => Sanitizer::stacktrace(array_slice($exception->getTrace(), 0, 20), $this->sanitizerOptions),
        ]);

        if ($this->previousExceptionHandler) {
            call_user_func($this->previousExceptionHandler, $exception);
        }
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        if ($this->capturedUncaughtException && str_contains((string) $error['message'], 'Uncaught')) {
            return;
        }

        $this->request->forceStatusCode(500);
        $this->capture('fatal', (string) $error['message'], (string) $error['file'], (int) $error['line'], [
            'severity' => $error['type'],
            'name' => $this->severityName((int) $error['type']),
        ]);

        if ($this->onShutdown) {
            ($this->onShutdown)();
        }
    }

    public function captureException(\Throwable $exception): void
    {
        $this->capture('exception', $exception->getMessage(), $exception->getFile(), $exception->getLine(), [
            'class' => $exception::class,
            'trace' => Sanitizer::stacktrace(array_slice($exception->getTrace(), 0, 20), $this->sanitizerOptions),
        ]);
    }

    private function capture(string $kind, string $message, string $file, int $line, array $extra = []): void
    {
        $this->client->emit('error', Sanitizer::clean(array_merge([
            'request_id' => $this->request->id(),
            'kind' => $kind,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'timestamp' => date('c'),
        ], $extra), $this->sanitizerOptions));
    }

    private function severityName(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN',
        };
    }
}
