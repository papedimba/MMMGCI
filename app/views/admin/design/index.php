<?php declare(strict_types=1);
/** Galerie du kit de composants : données de démonstration locales, aucune saisie utilisateur. @var string $csrfToken */
$rows = [
    ['name' => 'Aminata Traoré', 'role' => 'Secrétaire générale', 'status' => 'Actif', 'amount' => '125 000 FCFA'],
    ['name' => 'Yao Kouassi', 'role' => 'Trésorier général', 'status' => 'Invité', 'amount' => '8 500 FCFA'],
];
$badge = static fn (array $r): string => ui('badge', ['label' => $r['status'], 'kind' => $r['status'] === 'Actif' ? 'ok' : 'warn']);
$columns = [['name', 'Nom'], ['role', 'Rôle'], ['status', 'Statut', 'left', false, $badge], ['amount', 'Montant', 'right', true]];
$h = static fn (string $t): string => '<h2>' . e($t) . '</h2>';
?>
<?= ui('page-header', ['title' => 'Composants', 'subtitle' => 'Référence visuelle du kit ui(), dans tous ses états.', 'breadcrumbs' => [['Administration', '/admin'], ['Composants']]], ui('button', ['label' => 'Action principale', 'icon' => 'plus'])) ?>

<section class="gallery-section"><?= $h('Boutons') ?>
<div class="gallery-row">
<?= ui('button', ['label' => 'Primaire']) ?><?= ui('button', ['label' => 'Secondaire', 'variant' => 'secondary']) ?>
<?= ui('button', ['label' => 'Danger', 'variant' => 'danger']) ?><?= ui('button', ['label' => 'Discret', 'variant' => 'ghost']) ?>
<?= ui('button', ['label' => 'Avec icône', 'icon' => 'download', 'variant' => 'secondary']) ?><?= ui('button', ['label' => 'Petit', 'size' => 'sm']) ?>
<?= ui('button', ['label' => 'Désactivé', 'disabled' => true]) ?><?= ui('button', ['label' => 'Chargement', 'busy' => true, 'type' => 'submit']) ?>
<?= ui('button', ['label' => 'Lien', 'href' => '/admin', 'variant' => 'secondary']) ?>
</div>
<div class="gallery-row gallery-dark on-dark"><a href="/admin/composants">Lien sur fond marine (anneau de focus doré)</a></div>
</section>

<section class="gallery-section"><?= $h('Champs') ?>
<div class="gallery-grid">
<?= ui('field', ['name' => 'nom', 'label' => 'Nom', 'value' => 'Aminata Traoré', 'required' => true, 'autocomplete' => 'name']) ?>
<?= ui('field', ['name' => 'email', 'label' => 'Adresse e-mail', 'type' => 'email', 'hint' => 'Utilisée pour la connexion.', 'autocomplete' => 'email']) ?>
<?= ui('field', ['name' => 'email2', 'label' => 'E-mail (erreur)', 'type' => 'email', 'value' => 'pas-un-email', 'error' => 'Cette adresse e-mail n\'est pas valide.']) ?>
<?= ui('field', ['name' => 'role', 'label' => 'Rôle', 'type' => 'select', 'options' => ['sg' => 'Secrétaire général', 'tg' => 'Trésorier général'], 'value' => 'tg']) ?>
<?= ui('field', ['name' => 'note', 'label' => 'Observation', 'type' => 'textarea']) ?>
<?= ui('file-input', ['name' => 'piece', 'label' => 'Pièce justificative', 'accept' => '.pdf,.jpg,.png', 'hint' => 'PDF, JPG ou PNG.', 'max_mb' => 5]) ?>
<?= ui('file-input', ['name' => 'piece2', 'label' => 'Pièce (erreur)', 'error' => 'Le fichier dépasse 5 Mo.']) ?>
</div></section>

<section class="gallery-section"><?= $h('Alertes et badges') ?>
<?= ui('alert', ['kind' => 'info', 'title' => 'Information', 'message' => 'Les cotisations de novembre sont ouvertes.']) ?>
<?= ui('alert', ['kind' => 'ok', 'message' => 'L\'utilisateur a été enregistré.']) ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'Le lien d\'invitation expire dans 24 heures.']) ?>
<?= ui('alert', ['kind' => 'danger', 'title' => 'Échec', 'message' => 'L\'opération n\'a pas pu aboutir.']) ?>
<div class="gallery-row"><?php foreach (['ok' => 'Actif', 'warn' => 'En attente', 'danger' => 'Refusé', 'info' => 'Nouveau', 'neutral' => 'Désactivé'] as $k => $l): ?><?= ui('badge', ['kind' => $k, 'label' => $l]) ?><?php endforeach; ?></div>
</section>

<section class="gallery-section"><?= $h('Navigation') ?>
<?= ui('breadcrumbs', ['items' => [['Administration', '/admin'], ['Utilisateurs', '/admin/utilisateurs'], ['Aminata Traoré']]]) ?>
<?= ui('tabs', ['label' => 'Filtre', 'items' => [['Actifs', '#', true], ['Désactivés', '#', false], ['Invitations', '#', false]]]) ?>
<?= ui('pagination', ['page' => 4, 'pages' => 12, 'base' => '/admin/composants', 'query' => ['q' => 'traoré']]) ?>
</section>

<section class="gallery-section"><?= $h('Panneau et tableau') ?>
<?= ui('panel', ['title' => 'Utilisateurs', 'actions' => ui('button', ['label' => 'Exporter', 'variant' => 'secondary', 'size' => 'sm', 'icon' => 'download'])],
    ui('table', ['caption' => 'Comptes récents', 'columns' => $columns, 'rows' => $rows,
        'row_actions' => static fn (array $r): string => ui('button', ['label' => 'Ouvrir', 'variant' => 'ghost', 'size' => 'sm', 'href' => '#'])])) ?>
<?= ui('panel', ['title' => 'Tableau vide'], ui('table', ['columns' => $columns, 'rows' => [],
    'empty' => ['title' => 'Aucun utilisateur', 'message' => 'Invitez le premier membre du personnel.', 'action' => ['label' => 'Nouvel utilisateur', 'icon' => 'user-plus', 'href' => '#']]])) ?>
</section>

<section class="gallery-section"><?= $h('Confirmation') ?>
<form method="post" action="/admin/composants" class="gallery-row">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-danger" type="submit" data-confirm-open="demo-confirm"><span>Ouvrir la confirmation</span></button>
</form>
<?= ui('confirm-dialog', ['id' => 'demo-confirm', 'title' => 'Désactiver ce compte ?', 'message' => 'L\'utilisateur ne pourra plus se connecter.', 'confirm_label' => 'Désactiver', 'form_action' => '/admin/composants', 'csrf' => $csrfToken]) ?>
</section>

<section class="gallery-section"><?= $h('Circuit de validation') ?>
<?= ui('validation-steps', ['steps' => [
    ['label' => 'Saisie', 'who' => 'Aïcha Koné', 'when' => '12/11/2026', 'state' => 'done'],
    ['label' => 'Contrôle', 'who' => 'Marc Bamba', 'when' => '13/11/2026', 'state' => 'rejected'],
    ['label' => 'Validation du trésorier', 'who' => 'Yao Kouassi', 'when' => '', 'state' => 'current'],
    ['label' => 'Approbation du secrétariat', 'who' => '', 'when' => '', 'state' => 'todo'],
]]) ?>
</section>
