<?php declare(strict_types=1);
/**
 * Cadre commun des 5 étapes : titre, progression (texte et liste), résumé des erreurs (liens vers les champs), formulaire POST simple.
 * Le bouton « Continuer » précède « Retour » dans le code (touche Entrée = continuer) ; l'ordre visuel est inversé en CSS.
 * @var int $n @var array<string,string> $errors @var string $body @var string $csrfToken @var array<string,mixed> $draft
 */
$steps = \App\Modules\Site\ApplyFlow::STEPS;
$anchor = static fn (string $field): string => match ($field) {
    'form' => '#apply-form', 'piece' => '#f-piece', 'consent' => '#f-consent', 'sex' => '#f-sex-0', 'category_id' => '#f-category_id-0',
    default => \App\Modules\Site\ApplyFlow::stepOf($field) === $n ? '#f-' . $field : '/adhesion-en-ligne/etape/' . \App\Modules\Site\ApplyFlow::stepOf($field),
};
?>
<article class="pub-page apply">
<header class="pub-head">
<h1>Demande d'adhésion</h1>
<p class="apply-progress" id="apply-progress">Étape <?= (int) $n ?> sur <?= (int) count($steps) ?> : <?= e($steps[$n]) ?></p>
<ol class="apply-steps" aria-labelledby="apply-progress">
<?php foreach ($steps as $i => $label): ?>
<li<?= e($i < $n ? ' data-done' : '') ?><?php if ($i === $n): ?> aria-current="step"<?php endif; ?>><span class="visually-hidden"><?= e($label . ($i < $n ? ' (faite)' : ($i === $n ? ' (en cours)' : ''))) ?></span></li>
<?php endforeach; ?>
</ol>
</header>
<div class="pub-body">
<div class="apply-card">
<div class="cordage" aria-hidden="true"></div>
<div class="apply-in">
<?php if ($errors !== []): ?>
<div class="apply-errors" role="alert" tabindex="-1" id="apply-errors">
<h2>Vérifiez les points suivants</h2>
<ul>
<?php foreach ($errors as $field => $msg): ?>
<li><a href="<?= e($anchor((string) $field)) ?>"><?= e($msg) ?></a></li>
<?php endforeach; ?>
</ul>
</div>
<?php endif; ?>
<form method="post" action="/adhesion-en-ligne/etape/<?= (int) $n ?>" id="apply-form" class="apply-form"<?php if ($n === 4): ?> enctype="multipart/form-data"<?php endif; ?> novalidate>
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<?= $body ?>
<?php if ($n === 5): ?>
<input type="hidden" name="draft" value="<?= e((string) $draft['token']) ?>">
<input type="hidden" name="t" value="<?= e((string) $draft['stamp']) ?>">
<div class="apply-trap" aria-hidden="true"><label for="f-website">Ne pas remplir ce champ</label><input type="text" id="f-website" name="website" value="" tabindex="-1" autocomplete="off"></div>
<?php endif; ?>
<div class="apply-nav">
<button type="submit" class="btn btn-primary" name="op" value="<?= e($n === 5 ? 'submit' : 'next') ?>"><span><?= e($n === 5 ? 'Envoyer ma demande' : 'Continuer') ?></span></button>
<?php if ($n > 1): ?>
<button type="submit" class="btn btn-secondary" name="op" value="back" formnovalidate><span>Retour</span></button>
<?php endif; ?>
</div>
</form>
</div>
</div>
</div>
</article>
