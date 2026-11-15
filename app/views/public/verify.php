<?php declare(strict_types=1);
/** @var array<string,mixed>|null $card null = page neutre (même balisage pour tous les cas invalides) */
?>
<section class="verify">
<div class="verify-card">
<?php if ($card === null): ?>
<h1>Carte non valide</h1>
<p>Cette carte n'a pas pu être vérifiée. Adressez-vous à la Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire.</p>
<?php else: ?>
<h1>Carte valide</h1>
<dl class="verify-list">
<dt>Nom</dt><dd><?= e($card['last_name'] . ' ' . $card['first_names']) ?></dd>
<dt>N° de membre</dt><dd><?= e($card['member_number']) ?></dd>
<dt>Statut</dt><dd><?= e($card['status_label'] ?? $card['status']) ?></dd>
<dt>Validité</dt><dd><?= $card['expires_at'] === null ? 'Validité non définie' : 'Valide jusqu\'au ' . e((new DateTimeImmutable($card['expires_at']))->format('d/m/Y')) ?></dd>
</dl>
<?php endif; ?>
</div>
</section>
