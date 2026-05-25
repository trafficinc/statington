<?php

declare(strict_types=1);

namespace Statington;

use Statington\Util\Sanitizer;

final class Client
{
    private Transport $transport;

    public function __construct(private Config $config)
    {
        $this->transport = new Transport($config);
    }

    public function emit(string $type, array $payload = []): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $event = [
            'type' => $type,
            'app' => (string) $this->config->get('app', 'default'),
            'environment' => (string) $this->config->get('environment', 'dev'),
            'timestamp' => date('c'),
        ];

        $event = array_merge($event, Sanitizer::clean($payload, $this->config->sanitizerOptions()));
        $this->transport->send($event);
    }
}
