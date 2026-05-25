<?php

declare(strict_types=1);

?>
<section class="page-head">
    <div>
        <h1>Requests</h1>
        <p>Recent local PHP requests captured by Statington.</p>
    </div>
    <button type="button" class="status-pill live-toggle" data-live-toggle data-live-indicator>Live On</button>
</section>

<section class="filters">
    <form method="get" class="filter-form">
        <input type="search" name="q" value="<?= e($query['q'] ?? '') ?>" placeholder="Search path or request id">
        <select name="method">
            <?php foreach (['' => 'Any method', 'GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'PATCH' => 'PATCH', 'DELETE' => 'DELETE', 'CLI' => 'CLI'] as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($query['method'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="status" value="<?= e($query['status'] ?? '') ?>" placeholder="Status">
        <input type="text" name="app" value="<?= e($query['app'] ?? '') ?>" placeholder="App" list="app-options">
        <datalist id="app-options">
            <?php foreach (($filterOptions['apps'] ?? []) as $app): ?>
                <option value="<?= e($app) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <input type="text" name="environment" value="<?= e($query['environment'] ?? '') ?>" placeholder="Env" list="environment-options">
        <datalist id="environment-options">
            <?php foreach (($filterOptions['environments'] ?? []) as $environment): ?>
                <option value="<?= e($environment) ?>"></option>
            <?php endforeach; ?>
        </datalist>
        <label class="check"><input type="checkbox" name="has_errors" value="1" <?= !empty($query['has_errors']) ? 'checked' : '' ?>> Errors</label>
        <label class="check"><input type="checkbox" name="is_slow" value="1" <?= !empty($query['is_slow']) ? 'checked' : '' ?>> Slow</label>
        <label class="check"><input type="checkbox" name="db_slow" value="1" <?= !empty($query['db_slow']) ? 'checked' : '' ?>> Slow DB</label>
        <label class="check"><input type="checkbox" name="db_failed" value="1" <?= !empty($query['db_failed']) ? 'checked' : '' ?>> Failed DB</label>
        <label class="check"><input type="checkbox" name="hide_noise" value="1" <?= !empty($query['hide_noise']) ? 'checked' : '' ?>> Hide static</label>
        <select name="per_page">
            <?php foreach ([25, 50, 100, 250] as $size): ?>
                <option value="<?= $size ?>" <?= (int) ($result['per_page'] ?? 50) === $size ? 'selected' : '' ?>><?= $size ?>/page</option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
        <a class="button-secondary" href="/">Reset</a>
    </form>
    <form method="post" action="/api/clear" onsubmit="return confirm('Clear all Statington data?');">
        <button type="submit" class="danger-button">Clear Data</button>
    </form>
</section>

<section class="table-wrap">
    <table class="request-table" data-request-table>
        <thead>
            <tr>
                <th>Time</th>
                <th>Method</th>
                <th>Path</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Memory</th>
                <th>Logs</th>
                <th>Errors</th>
                <th>DB</th>
                <th>View</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $request): ?>
            <?php
                $isSlow = (float) ($request['duration_ms'] ?? 0) > 200 || (int) ($request['is_slow'] ?? 0) === 1;
                $hasErrors = (int) ($request['error_count'] ?? 0) > 0 || (int) ($request['status'] ?? 0) >= 500;
            ?>
            <tr class="<?= $hasErrors ? 'row-error' : ($isSlow ? 'row-slow' : '') ?>">
                <td class="muted"><?= e(date('H:i:s', strtotime((string) ($request['ended_at'] ?? $request['created_at'])))) ?></td>
                <td><span class="method"><?= e($request['method'] ?? '-') ?></span></td>
                <td>
                    <a class="request-link" href="/request.php?id=<?= e($request['request_id']) ?>">
                        <?= e($request['path'] ?? $request['uri'] ?? '-') ?>
                    </a>
                    <div class="subtle"><?= e($request['request_id']) ?></div>
                </td>
                <td><span class="code code-<?= e(status_class($request['status'] ?? 0)) ?>"><?= e($request['status'] ?? '-') ?></span></td>
                <td><strong><?= e($request['duration_ms'] ?? '-') ?> ms</strong><?= $isSlow ? '<span class="chip chip-slow">slow</span>' : '' ?></td>
                <td><?= e(bytes_human($request['memory_peak'] ?? 0)) ?></td>
                <td><?= e($request['log_count'] ?? 0) ?></td>
                <td><?= $hasErrors ? '<span class="chip chip-error">' . e($request['error_count'] ?? 0) . '</span>' : e($request['error_count'] ?? 0) ?></td>
                <td>
                    <?= e($request['db_query_count'] ?? 0) ?>
                    <?php if ((int) ($request['db_slow_count'] ?? 0) > 0): ?><span class="chip chip-slow"><?= e($request['db_slow_count']) ?> slow</span><?php endif; ?>
                    <?php if ((int) ($request['db_error_count'] ?? 0) > 0): ?><span class="chip chip-error"><?= e($request['db_error_count']) ?> failed</span><?php endif; ?>
                </td>
                <td><a class="view-link" href="/request.php?id=<?= e($request['request_id']) ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($requests === []): ?>
            <tr>
                <td colspan="10" class="empty">No requests yet. Run your app and refresh this page.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</section>

<?php
    $page = (int) ($result['page'] ?? 1);
    $pages = (int) ($result['pages'] ?? 1);
    $total = (int) ($result['total'] ?? count($requests));
    $params = $_GET;
?>
<nav class="pagination">
    <span><?= e($total) ?> requests</span>
    <?php if ($page > 1): ?>
        <?php $params['page'] = $page - 1; ?>
        <a href="/?<?= e(http_build_query($params)) ?>">Previous</a>
    <?php endif; ?>
    <strong>Page <?= e($page) ?> of <?= e($pages) ?></strong>
    <?php if ($page < $pages): ?>
        <?php $params['page'] = $page + 1; ?>
        <a href="/?<?= e(http_build_query($params)) ?>">Next</a>
    <?php endif; ?>
</nav>
