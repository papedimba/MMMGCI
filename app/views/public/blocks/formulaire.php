<?php declare(strict_types=1);
/**
 * Les formulaires vivent sur leurs propres pages (session et jeton seulement là) : ici, un simple lien.
 * « adhesion » : bouton seulement si l'adhésion en ligne est ouverte ($adhesionOpen, ApplyFlow::isOpen), sinon une note honnête.
 * @var array<string,mixed> $b @var bool $adhesionOpen
 */
$key = plain($b['form_key'] ?? '');
if ($key === 'adhesion' && empty($adhesionOpen)) {
    echo '<p class="pub-block pub-form-note">Le formulaire en ligne ouvrira prochainement.</p>';
    return;
}
[$href, $label] = match ($key) {
    'adhesion' => ['/adhesion-en-ligne', 'Commencer ma demande'],
    'contact' => ['/contact', 'Nous écrire'],
    'partenariat' => ['/partenariat', 'Proposer un partenariat'],
    default => [null, null],
};
if ($href === null) {
    return;
}
?>
<p class="pub-block pub-form-link"><a class="btn btn-primary" href="<?= e($href) ?>"><span><?= e($label) ?></span></a></p>
