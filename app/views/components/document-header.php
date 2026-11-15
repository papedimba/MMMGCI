<?php declare(strict_types=1);
/** Props : title, reference (optionnelle), date (texte déjà formaté, Africa/Abidjan). @var array<string,mixed> $props @var Closure $e */
?>
<header class="doc-header">
<img class="doc-logo" src="/assets/img/logo-complet@2x.jpg" alt="Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire" width="118" height="104">
<div class="doc-meta">
<h1 class="doc-title"><?= $e($props['title'] ?? '') ?></h1>
<?php if (!empty($props['reference'])): ?><p>Référence : <strong><?= $e($props['reference']) ?></strong></p>
<?php endif; ?>
<p>Date : <?= $e($props['date'] ?? '') ?></p>
</div>
</header>
