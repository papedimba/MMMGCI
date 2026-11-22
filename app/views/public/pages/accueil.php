<?php declare(strict_types=1);
/**
 * Accueil (gabarit « accueil », servi à /) : le héro est la carte imprimée du livret (bande de cordage, titre et extrait de la page,
 * actions), à côté de l'image à la une ou, à défaut, d'un dessin du métier présenté comme illustration. Puis les blocs de la page.
 * @var array<string,mixed> $page @var string $body @var array{url:string,alt:?string,width:?int,height:?int}|null $hero
 * @var ?string $adhesion chemin de la page d'adhésion en ligne @var bool $aides un bloc « domaines » existe (ancre #aides)
 */
$motto = 'Un nouveau jour se lève'; // devise du gabarit (pied de page), reprise sur la carte sauf si elle est déjà le titre
$title = plain($page['title']);
$excerpt = plain($page['excerpt'] ?? '');
$hero ??= null;
?>
<article class="pub-home">
<section class="home-hero" aria-labelledby="titre-accueil">
<div class="home-card">
<div class="cordage" aria-hidden="true"></div>
<div class="home-card-in">
<h1 id="titre-accueil"><?= e($title) ?></h1>
<?php if ($excerpt !== ''): ?><p class="home-lead"><?= e($excerpt) ?></p>
<?php endif; ?>
<?php if (!empty($adhesion) || !empty($aides)): ?>
<p class="home-actions">
<?php if (!empty($adhesion)): ?><a class="btn btn-primary" href="<?= e($adhesion) ?>"><span>Demander mon adhésion</span></a>
<?php endif; ?>
<?php if (!empty($aides)): ?><a class="home-link" href="#aides">Voir les aides</a>
<?php endif; ?>
</p>
<?php endif; ?>
<p class="home-issuer"><img src="/assets/img/emblem-80.png" alt="" width="56" height="56"><span>Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire<?php if (mb_strtolower($title) !== mb_strtolower($motto)): ?><em><?= e($motto) ?></em><?php endif; ?></span></p>
</div>
</div>
<figure class="home-figure">
<?php if ($hero !== null): ?>
<?= responsive_img($hero, '(min-width: 60em) 40vw, 100vw', true) ?>
<?php else: ?>
<img class="home-art" src="/assets/img/art/livret.svg" alt="Illustration d'un livret maritime ouvert, tamponné d'une ancre" width="480" height="360">
<figcaption>Illustration : le livret maritime</figcaption>
<?php endif; ?>
</figure>
</section>
<div class="home-body">
<?= $body ?>
</div>
</article>
