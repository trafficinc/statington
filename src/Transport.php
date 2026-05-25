<?php

declare(strict_types=1);

namespace Statington;

use Statington\Util\Sanitizer;

final class Transport
{
    public function __construct(private Config $config)
    {
    }

    public function send(array $event): void
    {
        if (!$this->config->enabled()) {
            return;
        }

        $json = Sanitizer::jsonEncode($event, $this->config->sanitizerOptions());

        try {
            function_exists('curl_init')
                ? $this->sendWithCurl($json)
                : $this->sendWithStreams($json);
        } catch (\Throwable) {
            // Observability must never break the observed app.
        }
    }

    private function sendWithCurl(string $json): void
    {
        $ch = curl_init($this->config->get('endpoint') . '/event');
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 150,
            CURLOPT_TIMEOUT_MS => 250,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }

    private function sendWithStreams(string $json): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $json,
                'timeout' => 0.25,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($this->config->get('endpoint') . '/event', false, $context);
    }
}
