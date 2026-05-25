<?php

declare(strict_types=1);

namespace Statington\Server;

use PDO;

final class RequestStore
{
    public function __construct(private PDO $pdo)
    {
    }

    public function storeEvent(array $event): int
    {
        $type = (string) ($event['type'] ?? '');
        $createdAt = $this->timestamp($event);
        $requestId = $this->stringOrNull($this->field($event, 'request_id'));
        $payloadJson = $this->json($event);

        $stmt = $this->pdo->prepare(
            'INSERT INTO events (request_id, type, app, environment, payload, created_at)
            VALUES (:request_id, :type, :app, :environment, :payload, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':type' => $type !== '' ? $type : 'unknown',
            ':app' => $this->stringOrNull($event['app'] ?? null),
            ':environment' => $this->stringOrNull($event['environment'] ?? null),
            ':payload' => $payloadJson,
            ':created_at' => $createdAt,
        ]);

        $eventId = (int) $this->pdo->lastInsertId();

        try {
            match ($type) {
                'request_start' => $this->upsertRequestStart($event, $requestId, $createdAt),
                'request_end' => $this->upsertRequestEnd($event, $requestId, $createdAt),
                'request' => $this->upsertRequestEnd($event, $requestId, $createdAt),
                'log' => $this->insertLog($event, $requestId, $createdAt),
                'span' => $this->insertSpan($event, $requestId, $createdAt),
                'error', 'exception', 'fatal' => $this->insertError($event, $requestId, $createdAt),
                'db_query', 'database_query', 'db_query_error' => $this->insertDatabaseEvent($event, $requestId, $createdAt),
                default => null,
            };
        } catch (\Throwable $exception) {
            $this->recordNormalizationFailure($eventId, $requestId, $type, $exception, $createdAt);
        }

        return $eventId;
    }

    public function recentRequests(int $limit = 100): array
    {
        return $this->searchRequests(['per_page' => $limit])['requests'];
    }

    public function searchRequests(array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(250, max(10, (int) ($filters['per_page'] ?? 50)));
        $offset = ($page - 1) * $perPage;
        [$where, $params] = $this->requestFilterSql($filters);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM requests r ' . $where);
        foreach ($params as $key => $value) {
            $count->bindValue($key, $value);
        }
        $count->execute();
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT r.*,
                r.status AS status_code,
                r.memory_peak AS memory_peak_bytes,
                "" AS payload,
                COALESCE((SELECT COUNT(*) FROM logs l WHERE l.request_id = r.request_id), 0) AS log_count,
                COALESCE((SELECT COUNT(*) FROM spans s WHERE s.request_id = r.request_id), 0) AS span_count,
                COALESCE((SELECT COUNT(*) FROM errors e WHERE e.request_id = r.request_id), 0) AS error_count,
                COALESCE((SELECT COUNT(*) FROM database_events de WHERE de.request_id = r.request_id), 0) AS db_query_count,
                COALESCE((SELECT COUNT(*) FROM database_events de WHERE de.request_id = r.request_id AND de.is_slow = 1), 0) AS db_slow_count,
                COALESCE((SELECT COUNT(*) FROM database_events de WHERE de.request_id = r.request_id AND de.is_error = 1), 0) AS db_error_count
            FROM requests r
            ' . $where . '
            ORDER BY COALESCE(r.ended_at, r.started_at, r.created_at) DESC, r.id DESC
            LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'requests' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'filters' => $filters,
        ];
    }

    public function requests(int $limit = 100): array
    {
        return $this->recentRequests($limit);
    }

    public function requestDetail(string $requestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT *, status AS status_code, memory_peak AS memory_peak_bytes, "" AS payload FROM requests WHERE request_id = :request_id');
        $stmt->execute([':request_id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            return null;
        }

        return [
            'request' => $request,
            'logs' => $this->fetchByRequest('SELECT * FROM logs WHERE request_id = :request_id ORDER BY created_at ASC, id ASC', $requestId),
            'spans' => $this->fetchByRequest('SELECT * FROM spans WHERE request_id = :request_id ORDER BY started_at ASC, id ASC', $requestId),
            'errors' => $this->fetchByRequest('SELECT * FROM errors WHERE request_id = :request_id ORDER BY created_at ASC, id ASC', $requestId),
            'database_events' => $this->fetchByRequest('SELECT * FROM database_events WHERE request_id = :request_id ORDER BY created_at ASC, id ASC', $requestId),
            'events' => $this->fetchByRequest('SELECT * FROM events WHERE request_id = :request_id ORDER BY id ASC', $requestId),
        ];
    }

    public function detail(string $requestId): ?array
    {
        return $this->requestDetail($requestId);
    }

    public function eventsSince(int $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM events WHERE id > :id ORDER BY id ASC LIMIT 250');
        $stmt->bindValue(':id', $eventId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function live(int $since = 0): array
    {
        return [
            'events' => $this->eventsSince($since),
            'requests' => $this->recentRequests(25),
            'counts' => [
                'requests' => (int) $this->pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn(),
                'errors' => (int) $this->pdo->query('SELECT COUNT(*) FROM errors')->fetchColumn(),
                'logs' => (int) $this->pdo->query('SELECT COUNT(*) FROM logs')->fetchColumn(),
                'spans' => (int) $this->pdo->query('SELECT COUNT(*) FROM spans')->fetchColumn(),
                'database_events' => (int) $this->pdo->query('SELECT COUNT(*) FROM database_events')->fetchColumn(),
            ],
        ];
    }

    public function filterOptions(): array
    {
        return [
            'apps' => $this->distinctRequestValues('app'),
            'environments' => $this->distinctRequestValues('environment'),
        ];
    }

    public function clear(): void
    {
        $this->pdo->exec('DELETE FROM database_events');
        $this->pdo->exec('DELETE FROM errors');
        $this->pdo->exec('DELETE FROM spans');
        $this->pdo->exec('DELETE FROM logs');
        $this->pdo->exec('DELETE FROM requests');
        $this->pdo->exec('DELETE FROM events');
        $this->pdo->exec('DELETE FROM sqlite_sequence WHERE name IN ("database_events", "errors", "spans", "logs", "requests", "events")');
    }

    private function requestFilterSql(array $filters): array
    {
        $where = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(r.path LIKE :q OR r.uri LIKE :q OR r.request_id LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        foreach (['method', 'app', 'environment'] as $field) {
            $value = trim((string) ($filters[$field] ?? ''));
            if ($value !== '') {
                $where[] = 'r.' . $field . ' = :' . $field;
                $params[':' . $field] = $value;
            }
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && ctype_digit($status)) {
            $where[] = 'r.status = :status';
            $params[':status'] = (int) $status;
        }

        if (!empty($filters['has_errors'])) {
            $where[] = '(r.status >= 500 OR EXISTS (SELECT 1 FROM errors er WHERE er.request_id = r.request_id))';
        }

        if (!empty($filters['is_slow'])) {
            $where[] = '(r.is_slow = 1 OR r.duration_ms >= 200)';
        }

        if (!empty($filters['db_slow'])) {
            $where[] = 'EXISTS (SELECT 1 FROM database_events de_slow WHERE de_slow.request_id = r.request_id AND de_slow.is_slow = 1)';
        }

        if (!empty($filters['db_failed'])) {
            $where[] = 'EXISTS (SELECT 1 FROM database_events de_failed WHERE de_failed.request_id = r.request_id AND de_failed.is_error = 1)';
        }

        if (!empty($filters['hide_noise'])) {
            foreach ($this->noisePathPatterns() as $index => $pattern) {
                $key = ':noise_' . $index;
                $where[] = 'COALESCE(r.path, r.uri, "") NOT LIKE ' . $key;
                $params[$key] = $pattern;
            }
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $params];
    }

    public function requestNavigation(string $requestId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM requests WHERE request_id = :request_id');
        $stmt->execute([':request_id' => $requestId]);
        $id = $stmt->fetchColumn();

        if ($id === false) {
            return ['previous' => null, 'next' => null];
        }

        $previous = $this->neighborRequest((int) $id, '<', 'DESC');
        $next = $this->neighborRequest((int) $id, '>', 'ASC');

        return ['previous' => $previous, 'next' => $next];
    }

    private function neighborRequest(int $id, string $operator, string $direction): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT request_id, method, path, status
            FROM requests
            WHERE id ' . $operator . ' :id
            ORDER BY id ' . $direction . '
            LIMIT 1'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($request) ? $request : null;
    }

    private function noisePathPatterns(): array
    {
        return [
            '/favicon.ico',
            '/robots.txt',
            '/assets/%',
            '/build/%',
            '/css/%',
            '/js/%',
            '/images/%',
            '/img/%',
            '/fonts/%',
        ];
    }

    private function distinctRequestValues(string $column): array
    {
        if (!in_array($column, ['app', 'environment', 'method'], true)) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT DISTINCT ' . $column . ' FROM requests WHERE ' . $column . ' IS NOT NULL AND ' . $column . ' != "" ORDER BY ' . $column . ' ASC LIMIT 100');

        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function upsertRequestStart(array $event, ?string $requestId, string $createdAt, bool $defaultStartedAt = true): void
    {
        if ($requestId === null || $requestId === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO requests (request_id, app, environment, method, path, uri, memory_peak, started_at, created_at)
            VALUES (:request_id, :app, :environment, :method, :path, :uri, :memory_peak, :started_at, :created_at)
            ON CONFLICT(request_id) DO UPDATE SET
                app = COALESCE(excluded.app, requests.app),
                environment = COALESCE(excluded.environment, requests.environment),
                method = COALESCE(excluded.method, requests.method),
                path = COALESCE(excluded.path, requests.path),
                uri = COALESCE(excluded.uri, requests.uri),
                memory_peak = COALESCE(excluded.memory_peak, requests.memory_peak),
                started_at = COALESCE(excluded.started_at, requests.started_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':app' => $this->stringOrNull($event['app'] ?? null),
            ':environment' => $this->stringOrNull($event['environment'] ?? null),
            ':method' => $this->stringOrNull($this->field($event, 'method')),
            ':path' => $this->stringOrNull($this->field($event, 'path')),
            ':uri' => $this->stringOrNull($this->field($event, 'uri')),
            ':memory_peak' => $this->intOrNull($this->field($event, 'memory_peak_bytes') ?? $this->field($event, 'memory_peak')),
            ':started_at' => $this->stringOrNull($this->field($event, 'started_at')) ?? ($defaultStartedAt ? $createdAt : null),
            ':created_at' => $createdAt,
        ]);
    }

    private function upsertRequestEnd(array $event, ?string $requestId, string $createdAt): void
    {
        if ($requestId === null || $requestId === '') {
            return;
        }

        $this->upsertRequestStart($event, $requestId, $createdAt, false);

        $stmt = $this->pdo->prepare(
            'UPDATE requests
            SET status = COALESCE(:status, status),
                duration_ms = COALESCE(:duration_ms, duration_ms),
                memory_peak = COALESCE(:memory_peak, memory_peak),
                is_slow = :is_slow,
                ended_at = COALESCE(:ended_at, ended_at)
            WHERE request_id = :request_id'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':status' => $this->intOrNull($this->field($event, 'status_code') ?? $this->field($event, 'status')),
            ':duration_ms' => $this->floatOrNull($this->field($event, 'duration_ms')),
            ':memory_peak' => $this->intOrNull($this->field($event, 'memory_peak_bytes') ?? $this->field($event, 'memory_peak')),
            ':is_slow' => !empty($this->field($event, 'is_slow')) ? 1 : 0,
            ':ended_at' => $this->stringOrNull($this->field($event, 'ended_at')) ?? $createdAt,
        ]);
    }

    private function insertLog(array $event, ?string $requestId, string $createdAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO logs (request_id, level, message, context, created_at)
            VALUES (:request_id, :level, :message, :context, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':level' => (string) ($this->field($event, 'level') ?? 'info'),
            ':message' => (string) ($this->field($event, 'message') ?? ''),
            ':context' => $this->json($this->field($event, 'context') ?? []),
            ':created_at' => $createdAt,
        ]);
    }

    private function insertSpan(array $event, ?string $requestId, string $createdAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO spans (request_id, span_id, name, duration_ms, started_at, ended_at, created_at)
            VALUES (:request_id, :span_id, :name, :duration_ms, :started_at, :ended_at, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':span_id' => $this->stringOrNull($this->field($event, 'span_id')),
            ':name' => (string) ($this->field($event, 'name') ?? 'span'),
            ':duration_ms' => $this->floatOrNull($this->field($event, 'duration_ms')),
            ':started_at' => $this->stringOrNull($this->field($event, 'started_at')),
            ':ended_at' => $this->stringOrNull($this->field($event, 'ended_at')),
            ':created_at' => $createdAt,
        ]);
    }

    private function insertError(array $event, ?string $requestId, string $createdAt): void
    {
        $trace = $this->field($event, 'stacktrace') ?? $this->field($event, 'trace');
        $stmt = $this->pdo->prepare(
            'INSERT INTO errors (request_id, type, message, file, line, stacktrace, context, created_at)
            VALUES (:request_id, :type, :message, :file, :line, :stacktrace, :context, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':type' => $this->stringOrNull($this->field($event, 'kind') ?? $event['type'] ?? 'error'),
            ':message' => (string) ($this->field($event, 'message') ?? ''),
            ':file' => $this->stringOrNull($this->field($event, 'file')),
            ':line' => $this->intOrNull($this->field($event, 'line')),
            ':stacktrace' => is_array($trace) ? $this->json($trace) : $this->stringOrNull($trace),
            ':context' => $this->json($this->field($event, 'context') ?? []),
            ':created_at' => $createdAt,
        ]);
    }

    private function insertDatabaseEvent(array $event, ?string $requestId, string $createdAt): void
    {
        $source = $this->field($event, 'source');
        $source = is_array($source) ? $source : [];
        $tables = $this->field($event, 'tables');
        $bindings = $this->field($event, 'bindings');

        $stmt = $this->pdo->prepare(
            'INSERT INTO database_events (request_id, driver, operation, tables, sql, bindings, source_file, source_line, source_class, source_function, source_confidence, affected_rows, duration_ms, is_mutation, is_slow, is_error, error_class, error_message, error_code, created_at)
            VALUES (:request_id, :driver, :operation, :tables, :sql, :bindings, :source_file, :source_line, :source_class, :source_function, :source_confidence, :affected_rows, :duration_ms, :is_mutation, :is_slow, :is_error, :error_class, :error_message, :error_code, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':driver' => $this->stringOrNull($this->field($event, 'driver')),
            ':operation' => (string) ($this->field($event, 'operation') ?? 'OTHER'),
            ':tables' => $this->json(is_array($tables) ? $tables : []),
            ':sql' => (string) ($this->field($event, 'sql') ?? ''),
            ':bindings' => $this->json(is_array($bindings) ? $bindings : []),
            ':source_file' => $this->stringOrNull($source['file'] ?? null),
            ':source_line' => $this->intOrNull($source['line'] ?? null),
            ':source_class' => $this->stringOrNull($source['class'] ?? null),
            ':source_function' => $this->stringOrNull($source['function'] ?? null),
            ':source_confidence' => $this->stringOrNull($this->field($event, 'source_confidence') ?? $source['confidence'] ?? 'unknown'),
            ':affected_rows' => $this->intOrNull($this->field($event, 'affected_rows')),
            ':duration_ms' => $this->floatOrNull($this->field($event, 'duration_ms')),
            ':is_mutation' => !empty($this->field($event, 'is_mutation')) ? 1 : 0,
            ':is_slow' => !empty($this->field($event, 'is_slow')) ? 1 : 0,
            ':is_error' => !empty($this->field($event, 'is_error')) || ($event['type'] ?? '') === 'db_query_error' ? 1 : 0,
            ':error_class' => $this->stringOrNull($this->field($event, 'error_class')),
            ':error_message' => $this->stringOrNull($this->field($event, 'error_message')),
            ':error_code' => $this->stringOrNull($this->field($event, 'error_code')),
            ':created_at' => $createdAt,
        ]);
    }

    private function recordNormalizationFailure(int $eventId, ?string $requestId, string $type, \Throwable $exception, string $createdAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO errors (request_id, type, message, file, line, stacktrace, context, created_at)
            VALUES (:request_id, :type, :message, :file, :line, :stacktrace, :context, :created_at)'
        );
        $stmt->execute([
            ':request_id' => $requestId,
            ':type' => 'normalization_error',
            ':message' => 'Failed to normalize event [' . $type . ']: ' . $exception->getMessage(),
            ':file' => $exception->getFile(),
            ':line' => $exception->getLine(),
            ':stacktrace' => $exception->getTraceAsString(),
            ':context' => $this->json(['event_id' => $eventId, 'event_type' => $type]),
            ':created_at' => $createdAt,
        ]);
    }

    private function fetchByRequest(string $sql, string $requestId): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':request_id' => $requestId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function field(array $event, string $key): mixed
    {
        if (array_key_exists($key, $event)) {
            return $event[$key];
        }

        return is_array($event['payload'] ?? null) && array_key_exists($key, $event['payload'])
            ? $event['payload'][$key]
            : null;
    }

    private function timestamp(array $event): string
    {
        return (string) ($event['timestamp'] ?? $event['created_at'] ?? date('c'));
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{}' : $json;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
