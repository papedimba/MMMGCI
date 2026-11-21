<?php declare(strict_types=1);
/**
 * Listes publiques : actualités, évènements (à venir puis passés), documents, galeries ; état vide honnête.
 * @var string $title @var string $kind @var list<array<string,mixed>> $items @var list<array<string,mixed>>|null $past
 */
?>
<article class="pub-page">
<h1><?= e($title) ?></h1>
<?php if ($kind === 'galeries'): ?>
<section class="pub-block">
<?php if ($items === []): ?>
<p class="pub-empty">Aucune galerie publiée pour le moment.</p>
<?php else: ?>
<ul class="pub-cards">
<?php foreach ($items as $g): ?>
<li class="pub-card">
<h2 class="pub-card-title"><a href="<?= e('/galeries/' . plain($g['slug'])) ?>"><?= e(plain($g['title'])) ?></a></h2>
<p class="pub-meta"><?= (int) $g['item_count'] ?> image<?= (int) $g['item_count'] > 1 ? 's' : '' ?></p>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
<?php elseif ($kind === 'evenements'): ?>
<h2>À venir</h2>
<?php include __DIR__ . '/../blocks/evenements.php'; ?>
<?php if (!empty($past)): ?>
<h2>Évènements passés</h2>
<?php $items = $past; include __DIR__ . '/../blocks/evenements.php'; ?>
<?php endif; ?>
<?php else: ?>
<?php include __DIR__ . '/../blocks/' . ($kind === 'documents' ? 'documents' : 'articles') . '.php'; ?>
<?php endif; ?>
</article>
