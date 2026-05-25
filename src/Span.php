<?php

declare(strict_types=1);

namespace Statington;

final class Span
{
    private string $spanId;
    private float $startedAt;
    private ?float $endedAt = null;

    public function __construct(
        private string $requestId,
        private string $name,
        private \Closure $onEnd,
    )
    {
        $this->spanId = bin2hex(random_bytes(8));
        $this->startedAt = microtime(true);
    }

    public function id(): string
    {
        return $this->spanId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function end(): void
    {
        if ($this->endedAt !== null) {
            return;
        }

        $this->endedAt = microtime(true);
        ($this->onEnd)($this);
    }

    public function event(): array
    {
        $this->endedAt ??= microtime(true);

        return [
            'request_id' => $this->requestId,
            'span_id' => $this->spanId,
            'name' => $this->name,
            'started_at' => date('c', (int) $this->startedAt),
            'ended_at' => date('c', (int) $this->endedAt),
            'duration_ms' => round(($this->endedAt - $this->startedAt) * 1000, 2),
        ];
    }
}
