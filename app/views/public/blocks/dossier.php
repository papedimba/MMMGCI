<?php declare(strict_types=1);
/**
 * « Préparer mon dossier » : cases de situation (affichées seulement avec JavaScript, html.js) qui filtrent la liste des pièces (site.js).
 * Sans JavaScript, toute la liste est visible, chaque pièce conditionnelle portant « selon votre situation ». Rien n'est envoyé ni stocké.
 * @var array<string,mixed> $b
 */
$situations = array_values(array_filter(is_array($b['situations'] ?? null) ? $b['situations'] : [], static fn ($s): bool => is_array($s)
    && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', plain($s['key'] ?? '')) === 1 && plain($s['label'] ?? '') !== ''));
$pieces = array_values(array_filter(is_array($b['pieces'] ?? null) ? $b['pieces'] : [], static fn ($p): bool => is_array($p) && plain($p['label'] ?? '') !== ''));
if ($pieces === []) {
    return;
}
$known = array_column($situations, 'key');
?>
<section class="pub-block pub-dossier" data-dossier>
<h2><?= e(plain($b['title'] ?? '')) ?></h2>
<?php if (plain($b['intro'] ?? '') !== ''): ?><p class="pub-dossier-intro"><?= e_lines($b['intro']) ?></p>
<?php endif; ?>
<?php if ($situations !== []): ?>
<fieldset class="pub-dossier-choices">
<legend>Votre situation</legend>
<?php foreach ($situations as $s): ?>
<label class="pub-check"><input type="checkbox" value="<?= e(plain($s['key'])) ?>" data-situation> <span><?= e(plain($s['label'])) ?></span></label>
<?php endforeach; ?>
</fieldset>
<?php endif; ?>
<ul class="pub-dossier-pieces">
<?php foreach ($pieces as $p):
    $keys = array_values(array_intersect(array_map('trim', explode(',', plain($p['situations'] ?? ''))), $known)); ?>
<li<?php if ($keys !== []): ?> data-for="<?= e(implode(' ', $keys)) ?>"<?php endif; ?>><span><?= e(plain($p['label'])) ?></span><?php if ($keys !== []): ?> <em class="pub-dossier-cond">(selon votre situation)</em><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<p class="pub-dossier-count" aria-live="polite" data-dossier-count></p>
</section>
