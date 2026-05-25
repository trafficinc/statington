<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Statington\Config;
use Statington\RequestContext;
use Statington\Span;

$config = new Config(['slow_request_ms' => 1]);
$request = new RequestContext(context: ['example' => 'request-id-proof']);
$requestId = $request->id();

$span = new Span($requestId, 'proof_span', static function (): void {
});
usleep(1000);
$span->end();

$events = [
    $request->startPayload(),
    $span->event(),
    [
        'request_id' => $requestId,
        'kind' => 'exception',
        'message' => 'Proof exception',
    ],
    $request->endPayload($config),
];

foreach ($events as $event) {
    if (($event['request_id'] ?? null) !== $requestId) {
        fwrite(STDERR, "Request ID mismatch\n");
        exit(1);
    }
}

echo "All lifecycle events share request_id: {$requestId}\n";
