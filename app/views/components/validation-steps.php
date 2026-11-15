<?php declare(strict_types=1);
/** @var array<string,mixed> $props @var Closure $e */
$states = ['done' => ['Validé', 'check'], 'current' => ['En attente', 'clock'], 'todo' => ['À venir', 'clock'], 'rejected' => ['Refusé', 'x']];
?>
<ol class="steps" aria-label="Circuit de validation">
<?php foreach ((array) ($props['steps'] ?? []) as $s): $st = isset($states[$s['state'] ?? '']) ? $s['state'] : 'todo'; ?>
<li class="step step-<?= $st ?>"<?= $st === 'current' ? ' aria-current="step"' : '' ?>>
<span class="step-mark"><?= ui_icon($states[$st][1]) ?></span>
<div><strong><?= $e($s['label'] ?? '') ?></strong>
<span class="step-state"><?= $states[$st][0] ?></span>
<?php if (!empty($s['who']) || !empty($s['when'])): ?><span class="step-meta"><?= $e(trim(($s['who'] ?? '') . (!empty($s['who']) && !empty($s['when']) ? ' · ' : '') . ($s['when'] ?? ''))) ?></span>
<?php endif; ?></div>
</li>
<?php endforeach; ?>
</ol>
