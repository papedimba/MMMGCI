<?php declare(strict_types=1);
/**
 * Appel à l'action : page du site résolue par pagePath (hors ligne : pas de bouton) ou adresse revérifiée par Blocks::isSafeUrl.
 * @var array<string,mixed> $b @var \App\Modules\Cms\PublishedContent $content
 */
use App\Modules\Cms\Blocks;

$pageId = $b['page_id'] ?? null;
$href = is_int($pageId) || (is_string($pageId) && ctype_digit($pageId)) ? $content->pagePath((int) $pageId) : plain($b['url'] ?? '');
$href = $href !== null && $href !== '' && Blocks::isSafeUrl($href) ? $href : null;
$external = $href !== null && preg_match('~^https?://~i', $href) === 1;
?>
<section class="pub-block pub-cta">
<h2><?= e(plain($b['title'] ?? '')) ?></h2>
<?php if (plain($b['text'] ?? '') !== ''): ?><p><?= e_lines($b['text']) ?></p>
<?php endif; ?>
<?php if ($href !== null && plain($b['button_label'] ?? '') !== ''): ?>
<a class="btn btn-primary" href="<?= e($href) ?>"<?= $external ? ' rel="noopener noreferrer"' : '' ?>><span><?= e(plain($b['button_label'])) ?></span></a>
<?php endif; ?>
</section>
