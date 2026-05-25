<?php

declare(strict_types=1);

namespace Statington;

use Statington\Util\Sanitizer;

final class Logger
{
    public function __construct(private Client $client, private RequestContext $request, private array $sanitizerOptions = [])
    {
    }

    public function log(string $message, array $context = [], string $level = 'info'): void
    {
        $this->client->emit('log', [
            'request_id' => $this->request->id(),
            'level' => strtolower($level),
            'message' => $message,
            'context' => Sanitizer::clean($context, $this->sanitizerOptions),
            'timestamp' => date('c'),
        ]);
    }
}
