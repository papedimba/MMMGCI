<?php declare(strict_types=1);
/** @var array<string,mixed> $b */
$tag = plain($b['level'] ?? '') === '3' ? 'h3' : 'h2';
if (plain($b['text'] ?? '') === '') {
    return;
}
?>
<<?= $tag ?> class="pub-block pub-title"><?= e(plain($b['text'])) ?></<?= $tag ?>>
