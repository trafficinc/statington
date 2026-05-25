<?php

declare(strict_types=1);

$spans = $detail['spans'];
$total = array_sum(array_map(static fn (array $span): float => (float) ($span['duration_ms'] ?? 0), $spans));
?>
<section class="panel">
    <div class="panel-head">
        <h2>Timeline</h2>
        <span><?= count($spans) ?> spans</span>
    </div>
    <?php if ($spans === []): ?>
        <p class="empty">No manual spans were recorded for this request.</p>
    <?php endif; ?>
    <div class="timeline">
        <?php foreach ($spans as $span): ?>
            <div class="span-row">
                <div class="span-name"><?= e($span['name']) ?></div>
                <div class="span-duration"><?= e($span['duration_ms']) ?> ms</div>
            </div>
        <?php endforeach; ?>
        <?php if ($spans !== []): ?>
            <div class="span-row span-total">
                <div class="span-name">total</div>
                <div class="span-duration"><?= e(round($total, 2)) ?> ms</div>
            </div>
        <?php endif; ?>
    </div>
</section>
