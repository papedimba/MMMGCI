<?php declare(strict_types=1);
/** @var array<string,mixed> $b */
?>
<section class="pub-block pub-intro">
<h2><?= e(plain($b['title'] ?? '')) ?></h2>
<?php if (plain($b['note'] ?? '') !== ''): ?><p><?= e_lines($b['note']) ?></p>
<?php endif; ?>
</section>
