<?php declare(strict_types=1);
/** Données de santé : jamais affichées sans clic ; chaque lecture est journalisée par le service. @var bool $medicalRevealed @var string $medicalDetails @var bool $canWriteMedical @var array $req @var string $base @var string $csrf */
?>
<?php if (!$medicalRevealed): ?>
<?= ui('panel', ['title' => 'Données de santé'], '<p>Les données de santé ne s\'affichent qu\'à la demande. Chaque consultation est enregistrée dans le journal des accès sensibles.</p>'
    . '<form method="post" action="' . e($base) . '/medical/afficher">' . $csrf . ui('button', ['label' => 'Afficher les données médicales', 'type' => 'submit', 'icon' => 'eye']) . '</form>') ?>
<?php else: ?>
<?= ui('panel', ['title' => 'Données de santé'], $medicalDetails === '' ? '<p>Aucune donnée de santé saisie.</p>' : '<p class="prewrap">' . e($medicalDetails) . '</p>') ?>
<?php if ($canWriteMedical && $req['status'] !== 'cloturee'): ?>
<form method="post" action="<?= e($base) ?>/medical">
<?= $csrf ?>
<?= ui('panel', ['title' => 'Modifier les données de santé'], ui('field', ['name' => 'details', 'label' => 'Données de santé', 'type' => 'textarea', 'value' => $medicalDetails, 'required' => true,
    'attrs' => ['maxlength' => 5000], 'hint' => 'Remplace le texte actuel. 5 000 caractères au maximum.'])
    . '<div class="form-actions">' . ui('button', ['label' => 'Enregistrer', 'type' => 'submit']) . '</div>') ?>
</form>
<?php endif; ?>
<?php endif; ?>
