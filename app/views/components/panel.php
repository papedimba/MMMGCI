<?php declare(strict_types=1);
/**
 * HTML DE CONFIANCE (jamais une saisie) : $slot et $props['actions'] ; le reste est échappé.
 * @var array<string,mixed> $props @var string $slot @var Closure $e
 */
?>
<section class="panel">
<?php if (!empty($props['title']) || !empty($props['actions'])): ?><header class="panel-head"><h2><?= $e($props['title'] ?? '') ?></h2><?= !empty($props['actions']) ? '<div class="panel-actions">' . $props['actions'] . '</div>' : '' ?></header>
<?php endif; ?>
<div class="panel-body"><?= $slot ?></div>
</section>
