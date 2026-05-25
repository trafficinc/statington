<?php

declare(strict_types=1);

if (!$detail): ?>
<section class="page-head"><h1>Request not found</h1></section>
<?php return; endif;

$request = $detail['request'];
$requestContext = null;
foreach ($detail['events'] as $event) {
    if (($event['type'] ?? '') === 'request_start') {
        $requestContext = json_decode((string) $event['payload'], true);
        break;
    }
}
$requestContext ??= $detail['events'][0]['payload'] ?? [];
$requestContext = is_string($requestContext) ? (json_decode($requestContext, true) ?: []) : $requestContext;

$removeRedacted = static function (mixed $value) use (&$removeRedacted): mixed {
    if (is_array($value)) {
        $clean = [];
        foreach ($value as $key => $item) {
            if ($item === '[REDACTED]') {
                continue;
            }

            $clean[$key] = $removeRedacted($item);
        }

        return $clean;
    }

    return $value;
};

$headers = is_array($requestContext['headers'] ?? null) ? $requestContext['headers'] : [];
$safeHeaders = [];
foreach ($headers as $name => $value) {
    $normalized = strtolower((string) $name);
    if (in_array($normalized, ['authorization', 'cookie', 'set-cookie'], true) || $value === '[REDACTED]') {
        continue;
    }

    $safeHeaders[$name] = $value;
}

$queryParams = $removeRedacted(is_array($requestContext['query_params'] ?? null) ? $requestContext['query_params'] : []);
$path = (string) ($requestContext['path'] ?? $request['path'] ?? '/');
$query = http_build_query($queryParams);
$safeUrl = $path . ($query !== '' ? '?' . $query : '');
$method = strtoupper((string) ($requestContext['method'] ?? $request['method'] ?? 'GET'));
$body = null;
if ($method !== 'GET') {
    if (is_array($requestContext['json_body'] ?? null)) {
        $body = json_encode($removeRedacted($requestContext['json_body']), JSON_UNESCAPED_SLASHES);
    } elseif (is_array($requestContext['post_params'] ?? null) && $requestContext['post_params'] !== []) {
        $body = http_build_query($removeRedacted($requestContext['post_params']));
    } elseif (isset($requestContext['body']) && is_string($requestContext['body']) && !str_contains($requestContext['body'], '[REDACTED]')) {
        $body = $requestContext['body'];
    }
}

$curlParts = ['curl', '-X', escapeshellarg($method), escapeshellarg('http://localhost:8080' . $safeUrl)];
foreach ($safeHeaders as $name => $value) {
    $curlParts[] = '-H';
    $curlParts[] = escapeshellarg($name . ': ' . $value);
}
if ($body !== null && $body !== '' && $body !== '[]') {
    $curlParts[] = '--data';
    $curlParts[] = escapeshellarg($body);
}
$curlCommand = implode(' ', $curlParts);
$replayContext = [
    'request' => [
        'request_id' => $request['request_id'],
        'method' => $method,
        'url' => $safeUrl,
        'path' => $path,
        'query_params' => $queryParams,
        'input_payload' => $requestContext['json_body'] ?? $requestContext['post_params'] ?? $requestContext['body'] ?? null,
        'headers' => $headers,
    ],
    'logs' => $detail['logs'],
    'spans' => $detail['spans'],
    'errors' => $detail['errors'],
    'timing' => [
        'started_at' => $request['started_at'] ?? null,
        'ended_at' => $request['ended_at'] ?? null,
        'duration_ms' => $request['duration_ms'] ?? null,
        'is_slow' => (bool) ($request['is_slow'] ?? false),
        'memory_peak' => $request['memory_peak'] ?? null,
    ],
];
?>
<section class="detail-head">
    <a class="back" href="/">Back</a>
    <div>
        <h1><span class="method"><?= e($request['method']) ?></span> <?= e($request['path']) ?></h1>
        <p><?= e($request['request_id']) ?></p>
        <div class="request-nav">
            <?php if (!empty($navigation['previous'])): ?>
                <a href="/request.php?id=<?= e($navigation['previous']['request_id']) ?>">Previous: <?= e(($navigation['previous']['method'] ?? '-') . ' ' . ($navigation['previous']['path'] ?? '-')) ?></a>
            <?php endif; ?>
            <?php if (!empty($navigation['next'])): ?>
                <a href="/request.php?id=<?= e($navigation['next']['request_id']) ?>">Next: <?= e(($navigation['next']['method'] ?? '-') . ' ' . ($navigation['next']['path'] ?? '-')) ?></a>
            <?php endif; ?>
        </div>
    </div>
    <div class="metrics">
        <div><span>Status</span><strong><span class="code code-<?= e(status_class($request['status'] ?? 0)) ?>"><?= e($request['status'] ?? '-') ?></span></strong></div>
        <div><span>Duration</span><strong><?= e($request['duration_ms']) ?> ms</strong></div>
        <div><span>Memory</span><strong><?= e(bytes_human($request['memory_peak'] ?? 0)) ?></strong></div>
    </div>
</section>

<section class="panel replay-panel">
    <div class="panel-head">
        <h2>Replay Context</h2>
        <div class="button-row">
            <button class="copy-button" type="button" data-copy-target="replay-curl">Copy cURL</button>
            <button class="copy-button" type="button" data-copy-target="replay-json">Copy JSON Context</button>
            <a class="copy-button" href="/api/request/export?id=<?= e($request['request_id']) ?>">Export JSON</a>
        </div>
    </div>
    <div class="replay-grid">
        <div class="replay-card">
            <span>Method</span>
            <strong><?= e($method) ?></strong>
        </div>
        <div class="replay-card">
            <span>URL / Path</span>
            <strong><?= e($safeUrl) ?></strong>
        </div>
        <div class="replay-card">
            <span>Timing</span>
            <strong><?= e($request['duration_ms'] ?? '-') ?> ms</strong>
        </div>
        <div class="replay-card">
            <span>Signals</span>
            <strong><?= count($detail['logs']) ?> logs, <?= count($detail['spans']) ?> spans, <?= count($detail['errors']) ?> errors</strong>
        </div>
    </div>

    <h3>Query Params</h3>
    <pre class="json"><?= e(json_pretty($queryParams)) ?></pre>

    <h3>Sanitized Input Payload</h3>
    <pre class="json"><?= e(json_pretty($requestContext['json_body'] ?? $requestContext['post_params'] ?? $requestContext['body'] ?? null)) ?></pre>

    <h3>Headers</h3>
    <pre class="json"><?= e(json_pretty($headers)) ?></pre>

    <h3>Timing Summary</h3>
    <pre class="json"><?= e(json_pretty($replayContext['timing'])) ?></pre>

    <h3>Logs</h3>
    <pre class="json"><?= e(json_pretty($detail['logs'])) ?></pre>

    <h3>Spans</h3>
    <pre class="json"><?= e(json_pretty($detail['spans'])) ?></pre>

    <h3>Errors</h3>
    <pre class="json"><?= e(json_pretty($detail['errors'])) ?></pre>

    <textarea id="replay-curl" class="copy-source" readonly><?= e($curlCommand) ?></textarea>
    <textarea id="replay-json" class="copy-source" readonly><?= e(json_pretty($replayContext)) ?></textarea>
</section>

<?php require __DIR__ . '/components/timeline.php'; ?>
<section class="panel query-search-panel">
    <div class="panel-head">
        <h2>Database Search</h2>
        <span>Filter queries by SQL, binding, table, source, or error</span>
    </div>
    <input type="search" class="detail-search" data-db-query-search placeholder="Search database impact">
</section>
<?php require __DIR__ . '/components/database.php'; ?>
<?php require __DIR__ . '/components/errors.php'; ?>
<?php require __DIR__ . '/components/logs.php'; ?>

<section class="panel">
    <div class="panel-head">
        <h2>Raw Request Context</h2>
        <span><?= count($detail['events']) ?> raw events</span>
    </div>
    <pre class="json"><?= e(json_pretty($requestContext)) ?></pre>
</section>

<section class="panel">
    <details class="raw-events">
        <summary>
            <strong>Raw Event Viewer</strong>
            <span><?= count($detail['events']) ?> events</span>
        </summary>
        <pre class="json"><?= e(json_pretty($detail['events'])) ?></pre>
    </details>
</section>
