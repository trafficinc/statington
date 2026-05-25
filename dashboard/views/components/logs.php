<?php

declare(strict_types=1);

?>
<section class="panel">
    <div class="panel-head">
        <h2>Logs</h2>
        <span><?= count($detail['logs']) ?> entries</span>
    </div>
    <div class="log-list">
        <?php foreach ($detail['logs'] as $log): ?>
            <article class="log-entry">
                <span class="level level-<?= e($log['level']) ?>"><?= e($log['level']) ?></span>
                <div>
                    <strong><?= e($log['message']) ?></strong>
                    <pre><?= e(json_pretty($log['context'])) ?></pre>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if ($detail['logs'] === []): ?>
            <p class="empty">No logs were recorded for this request.</p>
        <?php endif; ?>
    </div>
</section>
