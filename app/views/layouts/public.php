<?php declare(strict_types=1);
/**
 * Gabarit du site public. $site (SiteController) : menus publiés 'header'/'footer', 'contacts' validés seulement, 'adhesion' (chemin ou null).
 * Sans $site (aperçu, vérification de carte, base injoignable) : logo seul.
 */
$site = isset($site) && is_array($site) ? $site : [];
$header = $site['header'] ?? [];
$footer = $site['footer'] ?? [];
$contacts = $site['contacts'] ?? [];
// Adresses déjà validées par MenuService ; revérifiées ici (défense en profondeur) : une adresse douteuse n'est pas affichée.
$link = static function (array $item, string $class = ''): string {
    if (!\App\Modules\Cms\Blocks::isSafeUrl(plain($item['href'] ?? ''))) {
        return '';
    }
    return '<a' . ($class !== '' ? ' class="' . $class . '"' : '') . ' href="' . e(plain($item['href'] ?? '')) . '"'
        . (plain($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . e(plain($item['label'] ?? '')) . '</a>';
};
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'MMMGCI') ?></title>
<?php if (!empty($description) && is_string($description)): ?><meta name="description" content="<?= e($description) ?>">
<?php endif; ?>
<?php if (!empty($noindex)): ?><meta name="robots" content="noindex">
<?php endif; ?>
<?php if (isset($csrfToken)): ?><meta name="csrf-token" content="<?= e($csrfToken) ?>">
<?php endif; ?>
<link rel="icon" type="image/png" href="/assets/img/emblem-40.png">
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/public.css">
<script src="/assets/js/js-flag.js"></script>
<script src="/assets/js/app.js" defer></script>
<script src="/assets/js/site.js" defer></script>
</head>
<body class="public">
<a class="skip-link" href="#contenu">Aller au contenu</a>
<header class="pub-top">
<a class="pub-brand" href="/">
<img class="pub-logo" src="/assets/img/logo-complet.jpg" srcset="/assets/img/logo-complet.jpg 1x, /assets/img/logo-complet@2x.jpg 2x" alt="Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire, Maritime Oceans and Hospitality Services" width="118" height="104">
<img class="pub-emblem" src="/assets/img/emblem-80.png" alt="Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire" width="56" height="56">
</a>
<?php if ($header !== []): ?>
<button type="button" class="pub-menu-btn" data-menu-toggle aria-controls="menu-principal" aria-expanded="false" aria-label="Ouvrir le menu"><?= ui_icon('menu') ?><span>Menu</span></button>
<nav class="pub-menu" id="menu-principal" aria-label="Menu principal">
<ul>
<?php foreach ($header as $item): ?>
<li><?= $link($item) ?>
<?php if (!empty($item['children']) && is_array($item['children'])): ?>
<ul>
<?php foreach ($item['children'] as $child): ?><li><?= $link($child) ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</nav>
<?php elseif (!empty($site['adhesion'])): ?>
<a class="btn btn-primary pub-cta-top" href="<?= e((string) $site['adhesion']) ?>"><span>Adhésion</span></a>
<?php endif; ?>
</header>
<div class="flag" aria-hidden="true"><i class="flag-orange"></i><i class="flag-white"></i><i class="flag-green"></i></div>
<main id="contenu" tabindex="-1">
<?= $content ?>
</main>
<footer class="pub-foot">
<div class="pub-foot-card">
<div class="cordage" aria-hidden="true"></div>
<div class="pub-foot-in">
<p class="pub-foot-name">Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire</p>
<p class="pub-foot-motto">Un nouveau jour se lève</p>
<img class="pub-foot-logo" src="/assets/img/logo-complet.jpg" srcset="/assets/img/logo-complet.jpg 1x, /assets/img/logo-complet@2x.jpg 2x" alt="MMMGCI et MOHS, Maritime Oceans and Hospitality Services" width="118" height="104">
<?php if ($contacts !== []): ?>
<address class="pub-contacts">
<?php if (isset($contacts['address'])): ?><p><?= e_lines($contacts['address']) ?></p>
<?php endif; ?>
<?php if (isset($contacts['contact_phone'])): ?><p><a href="<?= e('tel:' . preg_replace('~[^+0-9]~', '', $contacts['contact_phone'])) ?>"><?= e($contacts['contact_phone']) ?></a></p>
<?php endif; ?>
<?php if (isset($contacts['contact_email'])): ?><p><a href="<?= e('mailto:' . $contacts['contact_email']) ?>"><?= e($contacts['contact_email']) ?></a></p>
<?php endif; ?>
<?php if (isset($contacts['social_facebook']) && preg_match('~^https://~i', $contacts['social_facebook'])): ?><p><a href="<?= e($contacts['social_facebook']) ?>" rel="noopener noreferrer">Facebook</a></p>
<?php endif; ?>
<?php if (isset($contacts['social_whatsapp'])): ?><p><?php if (preg_match('~^https://wa\.me/~i', $contacts['social_whatsapp'])): ?><a href="<?= e($contacts['social_whatsapp']) ?>" rel="noopener noreferrer">WhatsApp</a><?php else: ?>WhatsApp : <?= e($contacts['social_whatsapp']) ?><?php endif; ?></p>
<?php endif; ?>
</address>
<?php endif; ?>
<?php if ($footer !== []): ?>
<nav class="pub-foot-menu" aria-label="Pied de page">
<ul>
<?php foreach ($footer as $item): ?><li><?= $link($item) ?></li>
<?php endforeach; ?>
</ul>
</nav>
<?php endif; ?>
<?php if (!empty($imageCredits)): ?><p class="pub-credits"><?= e($imageCredits) ?></p>
<?php endif; ?>
</div>
</div>
</footer>
</body>
</html>
