<?php declare(strict_types=1);
/**
 * Panneau « Publication » des contenus à circuit simple : boutons d'état et suppression (sans JavaScript).
 * @var string $base @var array<string,mixed> $row @var list<string> $transitions @var bool $mayPublish @var bool $canDelete @var string $csrfToken @var string $noun @var string $extra @var int $version
 */
use App\Modules\Cms\SimpleContent;

$labels = ['publie' => 'Publier', 'archive' => 'Archiver', 'brouillon' => $row['status'] === 'archive' ? 'Restaurer en brouillon' : 'Dépublier'];
$forms = '';
foreach ($transitions as $to) {
    $forms .= '<form method="post" action="' . e($base . '/' . $row['id'] . '/etat') . '" class="transition-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="to" value="' . e($to) . '"><input type="hidden" name="version" value="' . (int) $version . '">' . ui('button', ['label' => $labels[$to], 'type' => 'submit', 'variant' => $to === 'publie' ? 'primary' : 'secondary']) . '</form>';
}
$state = '<p>État : <strong>' . e(SimpleContent::STATUS_LABELS[$row['status']] ?? (string) $row['status']) . '</strong>.</p>'
    . (!$mayPublish ? '<p class="field-hint">La publication est réservée aux publicateurs.</p>' : '')
    . ($extra !== '' ? '<p class="field-hint">' . e($extra) . '</p>' : '');
?>
<?= ui('panel', ['title' => 'Publication'], $state . ($forms !== '' ? '<div class="transition-list">' . $forms . '</div>' : '')) ?>
<?php if ($canDelete): ?>
<form method="post" action="<?= e($base . '/' . $row['id'] . '/suppression') ?>" class="delete-form">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<button class="btn btn-danger" type="submit" data-confirm-open="confirm-suppression"><span>Supprimer</span></button>
</form>
<?= ui('confirm-dialog', ['id' => 'confirm-suppression', 'title' => 'Supprimer ?', 'message' => 'Ce brouillon n\'a jamais été publié ; il sera effacé définitivement.',
    'confirm_label' => 'Supprimer', 'form_action' => $base . '/' . $row['id'] . '/suppression', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
