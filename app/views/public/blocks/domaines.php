<?php declare(strict_types=1);
/**
 * Domaines d'intervention rendus en carte de menu : le domaine à gauche, la nature de l'aide (et sa réserve) à droite, reliés par des pointillés.
 * Le premier bloc de la page porte l'ancre #aides (bouton « Voir les aides » de l'accueil). @var array<string,mixed> $b @var bool $first
 */
$items = array_filter(is_array($b['items'] ?? null) ? $b['items'] : [], 'is_array');
if ($items === []) {
    return;
}
?>
<div class="pub-block pub-menu-card"<?php if (!empty($first)): ?> id="aides"<?php endif; ?>>
<div class="cordage" aria-hidden="true"></div>
<ul class="pub-domains">
<?php foreach ($items as $it): ?>
<li>
<p class="pub-domain-row"><strong class="pub-domain-name"><?= e(plain($it['name'] ?? '')) ?></strong><?php if (plain($it['nature'] ?? '') !== ''): ?><span class="pub-leader" aria-hidden="true"></span><span class="pub-domain-nature"><?= e(plain($it['nature'])) ?></span><?php endif; ?></p>
<?php if (plain($it['description'] ?? '') !== ''): ?><p class="pub-domain-desc"><?= e_lines($it['description']) ?></p>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</div>
