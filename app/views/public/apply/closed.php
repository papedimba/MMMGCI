<?php declare(strict_types=1);
/**
 * Adhésion en ligne pas encore ouverte : message honnête, pièces de l'art. 6 (seulement si la page Adhésion est publiée), contact validé.
 * Aucun réglage interne n'est cité. @var list<string> $pieces @var array<string,string> $contacts
 */
?>
<article class="pub-page apply">
<header class="pub-head">
<h1>Adhésion en ligne</h1>
</header>
<div class="pub-body">
<div class="apply-card">
<div class="cordage" aria-hidden="true"></div>
<div class="apply-in">
<p class="apply-lead">Les adhésions en ligne ouvriront prochainement.</p>
<?php if ($pieces !== []): ?>
<h2 class="apply-sub">Pièces du dossier d'adhésion</h2>
<ul class="pub-dossier-pieces">
<?php foreach ($pieces as $label): ?><li><?= e($label) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if (isset($contacts['contact_phone']) || isset($contacts['contact_email'])): ?>
<h2 class="apply-sub">Nous contacter</h2>
<?php if (isset($contacts['contact_phone'])): ?><p><a href="<?= e('tel:' . preg_replace('~[^+0-9]~', '', $contacts['contact_phone'])) ?>"><?= e($contacts['contact_phone']) ?></a></p>
<?php endif; ?>
<?php if (isset($contacts['contact_email'])): ?><p><a href="<?= e('mailto:' . $contacts['contact_email']) ?>"><?= e($contacts['contact_email']) ?></a></p>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>
</article>
