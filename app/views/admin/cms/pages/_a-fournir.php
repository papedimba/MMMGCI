<?php declare(strict_types=1);
/** Rubriques sans source dans le cahier des charges : informatif seulement (cms.pages.manage). @var callable $can */
if (!$can('cms.pages.manage')) {
    return;
}
?>
<section class="panel" aria-labelledby="a-fournir-title">
<div class="panel-head"><h2 id="a-fournir-title">À fournir par la Mutuelle</h2></div>
<div class="panel-body">
<p class="field-hint">Ces rubriques n’ont pas été créées : le cahier des charges ne contient pas de texte à leur sujet. Elles apparaîtront quand la Mutuelle aura fourni et validé le contenu.</p>
<ul>
<?php foreach (\App\Modules\Site\ContentSeed::TO_PROVIDE as $label => $why): ?>
<li><strong><?= e($label) ?></strong> : <?= e($why) ?></li>
<?php endforeach; ?>
</ul>
</div>
</section>
