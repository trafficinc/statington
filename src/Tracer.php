<?php

declare(strict_types=1);

namespace Statington;

final class Tracer
{
    /** @var Span[] */
    private array $stack = [];

    public function __construct(private Client $client, private RequestContext $request)
    {
    }

    public function startSpan(string $name): Span
    {
        $span = new Span(
            $this->request->id(),
            $name,
            function (Span $span): void {
                $this->finishSpan($span);
            }
        );
        $this->stack[] = $span;

        return $span;
    }

    public function finishSpan(Span $span): void
    {
        foreach ($this->stack as $index => $candidate) {
            if ($candidate === $span) {
                unset($this->stack[$index]);
                $this->stack = array_values($this->stack);
                break;
            }
        }

        $this->client->emit('span', $span->event());
    }

    public function span(string $name, callable $callback): mixed
    {
        $span = $this->startSpan($name);

        try {
            return $callback($span);
        } finally {
            $span->end();
        }
    }

    public function flushOpenSpans(): void
    {
        while ($this->stack !== []) {
            $span = array_pop($this->stack);
            if ($span instanceof Span) {
                $span->end();
            }
        }
    }
}
