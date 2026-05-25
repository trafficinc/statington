<?php

declare(strict_types=1);

?>
<section class="panel panel-error">
    <div class="panel-head">
        <h2>Errors</h2>
        <span><?= count($detail['errors']) ?> captured</span>
    </div>
    <?php foreach ($detail['errors'] as $error): ?>
        <article class="error-entry">
            <div>
                <span class="level level-error"><?= e($error['type'] ?? 'error') ?></span>
                <strong><?= e($error['message']) ?></strong>
            </div>
            <code><?= e($error['file']) ?>:<?= e($error['line']) ?></code>
            <?php if (!empty($error['stacktrace'])): ?>
                <pre><?= e(json_pretty($error['stacktrace'])) ?></pre>
            <?php endif; ?>
            <?php if (!empty($error['context'])): ?>
                <pre><?= e(json_pretty($error['context'])) ?></pre>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if ($detail['errors'] === []): ?>
        <p class="empty">No errors were recorded for this request.</p>
    <?php endif; ?>
</section>
