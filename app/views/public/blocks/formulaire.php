<?php declare(strict_types=1);
/** Les formulaires vivent sur leurs propres pages (session et jeton seulement là) : ici, un simple lien. @var array<string,mixed> $b */
[$href, $label] = match (plain($b['form_key'] ?? '')) {
    'adhesion' => ['/adhesion-en-ligne', 'Demander mon adhésion en ligne'],
    'contact' => ['/contact', 'Nous écrire'],
    'partenariat' => ['/contact', 'Proposer un partenariat'],
    default => [null, null],
};
if ($href === null) {
    return;
}
?>
<p class="pub-block pub-form-link"><a class="btn btn-primary" href="<?= e($href) ?>"><span><?= e($label) ?></span></a></p>
