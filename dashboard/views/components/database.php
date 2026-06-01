<?php

declare(strict_types=1);

$databaseEvents = $detail['database_events'] ?? [];
$mutationCount = count(array_filter($databaseEvents, static fn (array $event): bool => (int) ($event['is_mutation'] ?? 0) === 1));
$slowCount = count(array_filter($databaseEvents, static fn (array $event): bool => (int) ($event['is_slow'] ?? 0) === 1));
$errorCount = count(array_filter($databaseEvents, static fn (array $event): bool => (int) ($event['is_error'] ?? 0) === 1));
$totalDuration = array_sum(array_map(static fn (array $event): float => (float) ($event['duration_ms'] ?? 0), $databaseEvents));
$formatDuration = static fn (mixed $value): string => $value === null || $value === '' ? '-' : number_format((float) $value, 2, '.', '');

function db_sql_shape(string $sql): string
{
    $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql) ?? $sql;
    $sql = preg_replace('/"(?:\"\"|[^"])*"/', '?', $sql) ?? $sql;
    $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
    $sql = preg_replace('/\s+/', ' ', trim($sql)) ?? $sql;

    return strtolower($sql);
}

function db_binding_color_class(int $index): string
{
    return 'binding-color-' . (($index - 1) % 12 + 1);
}

function db_binding_key_name(mixed $key): string
{
    return ltrim((string) $key, ':@$');
}

function db_binding_color_map(array $bindings): array
{
    $map = [];
    $index = 1;
    foreach ($bindings as $key => $_value) {
        $map[db_binding_key_name($key)] = db_binding_color_class($index);
        $index++;
    }

    return $map;
}

function db_annotated_sql(string $sql, array $bindings): string
{
    $named = [];
    foreach ($bindings as $key => $_value) {
        if (!is_int($key) && !ctype_digit((string) $key)) {
            $named[db_binding_key_name($key)] = true;
        }
    }

    return $named === []
        ? db_annotated_positional_sql($sql)
        : db_annotated_named_sql($sql, db_binding_color_map($bindings), $named);
}

function db_annotated_positional_sql(string $sql): string
{
    $out = '';
    $length = strlen($sql);
    $placeholder = 0;
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote !== null) {
            $out .= e($char);
            if ($char === $quote) {
                if ($quote === "'" && $next === "'") {
                    $out .= e($next);
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $out .= e($char);
            continue;
        }

        if ($char === '?') {
            $placeholder++;
            $class = db_binding_color_class($placeholder);
            $out .= '<span class="sql-binding-token ' . e($class) . '">?' . $placeholder . '</span>';
            continue;
        }

        $out .= e($char);
    }

    return $out;
}

function db_annotated_named_sql(string $sql, array $colorMap, array $knownNames): string
{
    $out = '';
    $length = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($quote !== null) {
            $out .= e($char);
            if ($char === $quote) {
                if ($quote === "'" && $next === "'") {
                    $out .= e($next);
                    $i++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $out .= e($char);
            continue;
        }

        if (($char === ':' || $char === '@' || $char === '$') && $next !== '' && preg_match('/[A-Za-z_]/', $next) === 1) {
            if ($char === ':' && ($sql[$i - 1] ?? '') === ':') {
                $out .= e($char);
                continue;
            }

            $j = $i + 1;
            while ($j < $length && preg_match('/[A-Za-z0-9_]/', $sql[$j]) === 1) {
                $j++;
            }

            $token = substr($sql, $i, $j - $i);
            $name = db_binding_key_name($token);
            if (isset($knownNames[$name])) {
                $class = $colorMap[$name] ?? 'binding-color-1';
                $out .= '<span class="sql-binding-token ' . e($class) . '">' . e($token) . '</span>';
                $i = $j - 1;
                continue;
            }
        }

        $out .= e($char);
    }

    return $out;
}

$groups = [];
$sqlShapeCounts = [];
foreach ($databaseEvents as $event) {
    $tables = json_decode((string) ($event['tables'] ?? '[]'), true) ?: ['unknown'];
    $table = (string) ($tables[0] ?? 'unknown');
    $operation = (string) ($event['operation'] ?? 'OTHER');
    $groups[$table][$operation] = ($groups[$table][$operation] ?? 0) + 1;
    $shape = db_sql_shape((string) ($event['sql'] ?? ''));
    $sqlShapeCounts[$shape] = ($sqlShapeCounts[$shape] ?? 0) + 1;
}
$uniqueSqlShapeCount = count($sqlShapeCounts);
$duplicateSqlShapeCount = count(array_filter($sqlShapeCounts, static fn (int $count): bool => $count > 1));
$duplicateQueryCount = array_sum(array_map(static fn (int $count): int => $count > 1 ? $count : 0, $sqlShapeCounts));
?>
<section class="panel">
    <div class="panel-head">
        <h2>Database Impact</h2>
        <span><?= $mutationCount ?> mutations, <?= $slowCount ?> slow, <?= $errorCount ?> errors, <?= count($databaseEvents) ?> queries, <?= $uniqueSqlShapeCount ?> unique SQL shapes, <?= $duplicateSqlShapeCount ?> duplicate shapes, <?= $duplicateQueryCount ?> duplicate queries, <?= e($formatDuration($totalDuration)) ?> ms</span>
    </div>
    <?php if ($databaseEvents === []): ?>
        <p class="empty">No database impact was recorded for this request.</p>
    <?php endif; ?>
    <?php if ($groups !== []): ?>
        <div class="db-group-grid">
            <?php foreach ($groups as $table => $operations): ?>
                <div class="db-group">
                    <strong><?= e($table) ?></strong>
                    <?php foreach ($operations as $operation => $count): ?>
                        <span><code><?= e($operation) ?></code> <?= e($count) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="db-impact-list">
        <?php foreach ($databaseEvents as $event):
            $tables = json_decode((string) ($event['tables'] ?? '[]'), true) ?: [];
            $bindings = json_decode((string) ($event['bindings'] ?? '[]'), true) ?: [];
            $bindingColors = db_binding_color_map($bindings);
            $isSlow = (int) ($event['is_slow'] ?? 0) === 1;
            $isError = (int) ($event['is_error'] ?? 0) === 1;
            $sqlShape = db_sql_shape((string) ($event['sql'] ?? ''));
            $duplicateCount = $sqlShapeCounts[$sqlShape] ?? 1;
            $isDuplicateSql = $duplicateCount > 1;
        ?>
            <details class="db-impact-item <?= $isError ? 'db-impact-error' : ($isSlow ? 'db-impact-slow' : '') ?><?= $isDuplicateSql ? ' db-impact-duplicate' : '' ?>" data-db-query-text="<?= e(strtolower(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '')) ?>" <?= (int) ($event['is_mutation'] ?? 0) === 1 || $isError ? 'open' : '' ?>>
                <summary>
                    <span class="db-op db-op-<?= e(strtolower((string) $event['operation'])) ?>"><?= e($event['operation']) ?></span>
                    <strong><?= e($tables[0] ?? 'unknown') ?></strong>
                    <span>
                        <?php if ($isDuplicateSql): ?>
                            <span class="db-duplicate-badge">duplicate x<?= e((string) $duplicateCount) ?></span>
                        <?php endif; ?>
                    </span>
                    <span><?= e($event['driver'] ?? '-') ?></span>
                    <span><?= e($event['affected_rows'] ?? '-') ?> rows</span>
                    <span><?= e($formatDuration($event['duration_ms'] ?? null)) ?> ms<?= $isSlow ? ' slow' : '' ?><?= $isError ? ' error' : '' ?></span>
                </summary>
                <?php if ($isError): ?>
                    <div class="db-error">
                        <strong><?= e($event['error_class'] ?? 'Query error') ?></strong>
                        <span><?= e($event['error_message'] ?? '') ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($event['source_file'])): ?>
                    <div class="db-source">
                        Source:
                        <code><?= e($event['source_file']) ?><?= !empty($event['source_line']) ? ':' . e((string) $event['source_line']) : '' ?></code>
                        <span class="source-confidence source-confidence-<?= e($event['source_confidence'] ?? 'unknown') ?>"><?= e($event['source_confidence'] ?? 'unknown') ?></span>
                        <?php if (!empty($event['source_class']) || !empty($event['source_function'])): ?>
                            <span><?= e(trim((string) ($event['source_class'] ?? '') . '::' . (string) ($event['source_function'] ?? ''), ':')) ?></span>
                        <?php endif; ?>
                    </div>
                <?php elseif (($event['source_confidence'] ?? '') === 'unknown'): ?>
                    <div class="db-source">
                        Source:
                        <span class="source-confidence source-confidence-unknown">unknown</span>
                    </div>
                <?php endif; ?>
                <pre class="json sql-annotated"><?= db_annotated_sql((string) $event['sql'], $bindings) ?></pre>
                <?php if ($bindings !== []): ?>
                    <details class="db-bindings">
                        <summary>Bindings</summary>
                        <div class="binding-list">
                            <?php $bindingIndex = 1; foreach ($bindings as $key => $value):
                                $marker = is_int($key) || ctype_digit((string) $key) ? '?' . $bindingIndex : (string) $key;
                                $colorClass = is_int($key) || ctype_digit((string) $key)
                                    ? db_binding_color_class($bindingIndex)
                                    : ($bindingColors[db_binding_key_name($key)] ?? db_binding_color_class($bindingIndex));
                            ?>
                                <div class="<?= e($colorClass) ?>">
                                    <code><?= e($marker) ?></code>
                                    <span><?= e(is_scalar($value) || $value === null ? json_encode($value, JSON_UNESCAPED_SLASHES) : json_pretty($value)) ?></span>
                                </div>
                            <?php $bindingIndex++; endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    </div>
</section>
