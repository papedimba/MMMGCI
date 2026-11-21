<?php declare(strict_types=1);
/** Image de la médiathèque, disque public seulement (mediaUrl) ; introuvable ou privée : bloc omis. @var array<string,mixed> $b @var \App\Modules\Cms\PublishedContent $content */
$id = $b['media_id'] ?? null;
$m = is_int($id) || (is_string($id) && ctype_digit($id)) ? $content->mediaUrl((int) $id) : null;
if ($m === null || !preg_match('~^/media/~', $m['url']) || preg_match('~\.pdf$~i', $m['url'])) {
    return;
}
?>
<figure class="pub-block pub-figure">
<img src="<?= e($m['url']) ?>" alt="<?= e($m['alt'] ?? '') ?>"<?= $m['width'] !== null && $m['height'] !== null ? ' width="' . (int) $m['width'] . '" height="' . (int) $m['height'] . '"' : '' ?> loading="lazy" decoding="async">
<?php if (plain($b['caption'] ?? '') !== ''): ?><figcaption><?= e(plain($b['caption'])) ?></figcaption>
<?php endif; ?>
</figure>
