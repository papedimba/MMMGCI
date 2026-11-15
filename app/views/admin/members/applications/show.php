<?php declare(strict_types=1);
/** @var array $app @var array $payload @var bool $canDocuments @var array $revealed @var ?array $dup @var string $zoneName @var string $categoryName @var string $professionName
 *  @var array $files @var array $docTypes @var string $reviewer @var string $csrfToken @var Closure $can */
use App\Modules\Members\ApplicationService;
$base = '/admin/adherents/demandes/' . $app['id'];
$st = $app['status'];
$open = !in_array($st, ['validee', 'rejetee'], true);
$kinds = ['recue' => 'info', 'en_verification' => 'info', 'complements_demandes' => 'warn', 'validee' => 'ok', 'rejetee' => 'danger'];
$s3 = ['label' => 'Décision', 'state' => 'todo'];
if ($st === 'validee') { $s3 = ['label' => 'Validée', 'state' => 'done', 'who' => $reviewer, 'when' => date_fr($app['updated_at'])]; }
if ($st === 'rejetee') { $s3 = ['label' => 'Rejetée', 'state' => 'rejected', 'who' => $reviewer, 'when' => date_fr($app['updated_at'])]; }
$steps = [
    ['label' => 'Demande reçue', 'state' => 'done', 'when' => date_fr($app['created_at'])],
    ['label' => $st === 'complements_demandes' ? 'Compléments demandés' : 'Vérification du dossier', 'state' => in_array($st, ['validee', 'rejetee'], true) ? 'done' : ($st === 'recue' ? 'todo' : 'current')],
    $s3,
];
$val = static fn (mixed $v): string => $v === null || $v === '' ? '—' : (string) $v;
$fmt = static fn (mixed $d): string => $d === null || $d === '' ? '—' : date('d/m/Y', strtotime((string) $d));
$dl = static function (array $pairs): string {
    $out = '<dl class="detail-list">';
    foreach ($pairs as $k => $v) {
        $out .= '<dt>' . e($k) . '</dt><dd>' . e($v) . '</dd>';
    }
    return $out . '</dl>';
};
$pairs = ['Nom' => $val($payload['last_name'] ?? ''), 'Prénoms' => $val($payload['first_names'] ?? ''), 'Sexe' => $val($payload['sex'] ?? ''),
    'Date de naissance' => $fmt($payload['birth_date'] ?? ''), 'Lieu de naissance' => $val($payload['birth_place'] ?? ''), 'Nationalité' => $val($payload['nationality'] ?? ''),
    'Téléphone' => $val($payload['phone'] ?? ''), 'Autre téléphone' => $val($payload['phone_alt'] ?? ''), 'E-mail' => $val($payload['email'] ?? ''), 'Adresse' => $val($payload['address'] ?? ''),
    'Zone' => $val($zoneName), 'Catégorie' => $val($categoryName), 'Profession' => $val($professionName), 'Situation professionnelle' => $val($payload['employment_status'] ?? ''), 'Employeur' => $val($payload['employer'] ?? '')];
if ($canDocuments) {
    $pairs['Pièce d\'identité'] = $val($payload['id_document_type'] ?? '');
    $pairs['Numéro de pièce'] = isset($revealed['id_document_number']) ? (string) $payload['id_document_number'] : '—';
    $pairs['Numéro de livret maritime'] = isset($revealed['seaman_book_number']) ? (string) $payload['seaman_book_number'] : '—';
}
?>
<?= ui('page-header', ['title' => 'Demande ' . $app['reference'], 'subtitle' => trim(($payload['last_name'] ?? '') . ' ' . ($payload['first_names'] ?? '')),
    'breadcrumbs' => [['Adhérents', '/admin/adherents'], ['Demandes d\'adhésion', '/admin/adherents/demandes'], [$app['reference'], null]]],
    ui('badge', ['label' => ApplicationService::STATES[$st] ?? $st, 'kind' => $kinds[$st] ?? 'neutral'])) ?>
<?php if ($dup !== null): ?>
<?= ui('alert', ['kind' => 'warn', 'message' => 'Des homonymes existent déjà : vérifiez qu\'il ne s\'agit pas d\'une personne déjà enregistrée avant de valider.']) ?>
<?php if ($dup['visible'] !== []): ?>
<ul>
<?php foreach ($dup['visible'] as $d): ?><li><a class="cell-link" href="/admin/adherents/<?= e((string) $d['id']) ?>"><?= e($d['last_name'] . ' ' . $d['first_names']) ?></a> (<?= e($d['member_number']) ?>)</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
<?php if ($dup['hidden_count'] > 0): ?><p><?= e((string) $dup['hidden_count']) ?> homonyme<?= $dup['hidden_count'] > 1 ? 's' : '' ?> hors de votre périmètre.</p><?php endif; ?>
<?php if ($can('members.validate')): ?>
<form method="post" action="<?= e($base) ?>/valider">
<input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>">
<input type="hidden" name="confirm_duplicate" value="1">
<div class="form-actions"><button class="btn btn-primary" type="submit"><span>Valider quand même</span></button></div>
</form>
<?php endif; ?>
<?php endif; ?>
<?= ui('panel', ['title' => 'Avancement'], ui('validation-steps', ['steps' => $steps])
    . ($st === 'rejetee' && (string) $app['rejection_reason'] !== '' ? '<p><strong>Motif du rejet :</strong> ' . e($app['rejection_reason']) . '</p>' : '')
    . ($st === 'validee' && $app['member_id'] !== null && $can('members.view') ? '<p><a href="/admin/adherents/' . e((string) $app['member_id']) . '">Voir la fiche adhérente</a></p>' : '')) ?>
<?= ui('panel', ['title' => 'Informations saisies'], $dl($pairs)) ?>
<?= ui('panel', ['title' => 'Pièces du dossier (aide à l\'instruction)'],
    '<p>Pièces prévues à l\'article 6 du cahier des charges. Cette liste est une aide : elle ne bloque pas la validation.</p><ul>'
    . '<li>Fiche individuelle d\'adhésion dûment renseignée</li><li>Copie d\'une pièce d\'identité valide</li><li>Copie du livret maritime, lorsque disponible ou pertinent</li>'
    . '<li>Photo d\'identité, si elle est requise pour la carte</li><li>Coordonnées téléphoniques et autres moyens de contact</li><li>Informations relatives aux ayants droit, lorsque le dispositif le prévoit</li></ul>') ?>
<?php if ($canDocuments): ?>
<?= ui('table', ['caption' => 'Pièces jointes à la demande', 'rows' => $files, 'columns' => [
    ['type_label', 'Type', 'left', false, static fn (array $r): string => '<strong>' . e($r['type_label']) . '</strong>'],
    ['original_name', 'Fichier', 'left', false, static fn (array $r): string => '<a href="' . e($base . '/pieces/' . $r['file_id']) . '">' . e($r['original_name']) . '</a>'],
], 'empty' => ['title' => 'Aucune pièce jointe', 'message' => 'Les pièces déposées apparaîtront ici ; leur consultation est journalisée.']]) ?>
<?php if ($open): ?>
<?= ui('panel', ['title' => 'Joindre une pièce'], '<form method="post" enctype="multipart/form-data" action="' . e($base) . '/pieces"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
    . ui('field', ['name' => 'type', 'label' => 'Type de pièce', 'type' => 'select', 'options' => $docTypes, 'required' => true])
    . ui('file-input', ['name' => 'piece', 'label' => 'Fichier', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'PDF, JPG ou PNG.', 'max_mb' => 5])
    . '<div class="form-actions">' . ui('button', ['label' => 'Joindre', 'type' => 'submit']) . '</div></form>') ?>
<?php endif; ?>
<?php endif; ?>
<?php if ($open && $can('members.validate')): ?>
<?php
$actions = '';
if ($st !== 'en_verification') {
    $actions .= '<form method="post" action="' . e($base) . '/prise-en-charge"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<div class="form-actions">' . ui('button', ['label' => $st === 'complements_demandes' ? 'Pièces reçues : reprendre la vérification' : 'Prendre en charge', 'type' => 'submit', 'variant' => 'secondary']) . '</div></form>';
}
if ($st !== 'complements_demandes') {
    $actions .= '<form method="post" action="' . e($base) . '/complements"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . ui('field', ['name' => 'note', 'label' => 'Compléments demandés', 'type' => 'textarea', 'required' => true, 'attrs' => ['maxlength' => 1000]])
        . '<div class="form-actions">' . ui('button', ['label' => 'Demander des compléments', 'type' => 'submit', 'variant' => 'secondary']) . '</div></form>';
}
if (in_array($st, ['recue', 'en_verification'], true)) {
    $actions .= '<form method="post" action="' . e($base) . '/valider"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<div class="form-actions"><button class="btn btn-primary" type="submit" data-confirm-open="confirm-valider"><span>Valider la demande</span></button></div></form>';
}
$actions .= '<form method="post" action="' . e($base) . '/rejeter"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
    . ui('field', ['name' => 'reason', 'label' => 'Motif du rejet', 'type' => 'textarea', 'required' => true, 'attrs' => ['maxlength' => 1000]])
    . '<label class="check"><input type="checkbox" name="confirm" value="1" required><span>Je confirme le rejet (décision définitive)</span></label>'
    . '<div class="form-actions">' . ui('button', ['label' => 'Rejeter la demande', 'type' => 'submit', 'variant' => 'danger']) . '</div></form>';
?>
<?= ui('panel', ['title' => 'Instruction'], $actions) ?>
<?= ui('confirm-dialog', ['id' => 'confirm-valider', 'title' => 'Valider cette demande ?', 'message' => 'La fiche adhérent sera créée avec son numéro. L\'opération est consignée au journal.',
    'confirm_label' => 'Valider', 'form_action' => $base . '/valider', 'csrf' => $csrfToken]) ?>
<?php endif; ?>
