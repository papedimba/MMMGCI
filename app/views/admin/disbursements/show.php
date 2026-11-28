<?php declare(strict_types=1);
/**
 * Page d'une demande. Chaque bouton d'action n'apparaît que si DisbursementService::allowedActions l'autorise ($actions) ; le service reste seul juge au POST.
 * @var array $d @var ?string $error @var array $attachments @var array $approvals @var array $revisions @var ?array $payment @var list<string> $actions
 * @var array{required:int, valid:int, other_eligible_approvers:int, stalled:bool} $circuit @var bool $operational @var array $methods @var string $csrfToken @var Closure $can
 */
use App\Core\Money;
use App\Modules\Disbursements\DisbursementController;
$base = '/admin/decaissements/' . $d['id'];
$st = $d['status'];
$rev = (int) $d['revision'];
$kinds = ['brouillon' => 'neutral', 'soumis' => 'info', 'approuve_1' => 'info', 'approuve_2' => 'warn', 'paye' => 'ok', 'rejete' => 'danger', 'annule' => 'neutral'];
$allowed = static fn (string $a): bool => in_array($a, $actions, true);

// Étapes : état réel (approbations valides de la révision courante, dans l'ordre) et noms des acteurs.
$valid = array_values(array_filter($approvals, static fn (array $a): bool => (int) $a['revision'] === $rev && $a['decision'] === 'approve' && $a['invalidated_at'] === null
    && (int) $a['approver_id'] !== (int) $d['initiated_by']));
$steps = [['label' => 'Demande initiée', 'state' => $st === 'brouillon' ? 'current' : 'done', 'who' => (string) $d['initiator_name'], 'when' => date_fr($d['submitted_at'] ?? $d['created_at'])]];
$open = true; // la première étape d'approbation manquante porte l'état courant (en attente, rejetée, annulée)
for ($i = 0; $i < max(2, $circuit['required'], count($valid)); $i++) {
    $label = ($i === 0 ? '1re' : ($i + 1) . 'e') . ' approbation';
    if (isset($valid[$i])) {
        $steps[] = ['label' => $label, 'state' => 'done', 'who' => (string) $valid[$i]['approver_name'], 'when' => date_fr($valid[$i]['decided_at'])];
        continue;
    }
    $step = ['label' => $label, 'state' => 'todo'];
    if ($open && in_array($st, ['soumis', 'approuve_1'], true)) {
        $step['state'] = 'current';
    } elseif ($open && $st === 'rejete') {
        $step = ['label' => $label . ' : rejetée', 'state' => 'rejected', 'who' => (string) $d['rejected_by_name']];
    } elseif ($open && $st === 'annule') {
        $step = ['label' => 'Annulée par l\'initiateur', 'state' => 'rejected', 'who' => (string) $d['initiator_name'], 'when' => date_fr($d['cancelled_at'])];
    }
    $open = false;
    $steps[] = $step;
}
$steps[] = $payment !== null ? ['label' => 'Payée', 'state' => 'done', 'who' => (string) $payment['payer_name'], 'when' => date('d/m/Y H:i', strtotime((string) $payment['paid_at']))]
    : ['label' => 'Paiement', 'state' => $st === 'approuve_2' ? 'current' : 'todo'];

// Actions autorisées par le service, dans l'ordre du circuit.
$buttons = '';
if ($allowed('submit')) {
    $buttons .= '<form method="post" action="' . e($base) . '/soumettre" data-action="submit"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . ui('button', ['label' => 'Soumettre aux approbateurs', 'type' => 'submit']) . '</form>';
}
if ($allowed('approve')) {
    $buttons .= '<form method="post" action="' . e($base) . '/approuver" data-action="approve"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
        . '<input type="hidden" name="seen_revision" value="' . $rev . '">'
        . '<button class="btn btn-primary" type="submit" data-confirm-open="confirm-approve"><span>Approuver</span></button></form>';
}
if ($allowed('pay')) {
    $buttons .= '<span data-action="pay">' . ui('button', ['label' => 'Payer', 'href' => $base . '/payer']) . '</span>';
}
if ($allowed('revise')) {
    $buttons .= '<span data-action="revise">' . ui('button', ['label' => 'Modifier', 'variant' => 'secondary', 'href' => $base . '/modifier']) . '</span>';
}
if ($allowed('reject')) {
    $buttons .= '<span data-action="reject">' . ui('button', ['label' => 'Rejeter', 'variant' => 'danger', 'href' => $base . '/rejeter']) . '</span>';
}
if ($allowed('cancel')) {
    $buttons .= '<span data-action="cancel">' . ui('button', ['label' => 'Annuler la demande', 'variant' => 'secondary', 'href' => $base . '/annuler']) . '</span>';
}
$amount = static fn (?string $v): string => $v === null ? '—' : Money::format(Money::fromDecimal($v));
$details = ['Montant' => $amount((string) $d['amount']), 'Bénéficiaire' => $d['beneficiary_name'], 'Motif' => $d['purpose'], 'Compte débité' => $d['account_name'],
    'Catégorie' => $d['category_name'], 'Révision' => 'n° ' . $rev, 'Initiée par' => (string) $d['initiator_name'], 'Créée le' => date_fr($d['created_at'])];
if ((string) $d['internal_note'] !== '') {
    $details['Note interne'] = $d['internal_note'];
}
if ($st === 'rejete') {
    $details['Motif du rejet'] = (string) $d['rejection_reason'];
}
$dl = '<dl class="detail-list">';
foreach ($details as $k => $v) {
    $dl .= '<dt>' . e($k) . '</dt><dd>' . e((string) $v) . '</dd>';
}
if ($d['social_reference'] !== null) { // référence seulement : ni nom, ni type d'aide ; lien vers la demande pour social.view
    $label = 'Aide sociale ' . $d['social_reference'];
    $dl .= '<dt>Origine</dt><dd>' . ($can('social.view') ? '<a class="cell-link" href="/admin/demandes-sociales/' . (int) $d['social_request_id'] . '">' . e($label) . '</a>' : e($label)) . '</dd>';
}
$dl .= '</dl>';
?>
<?= ui('page-header', ['title' => 'Demande ' . $d['reference'], 'subtitle' => $d['beneficiary_name'] . ' · ' . $amount((string) $d['amount']),
    'breadcrumbs' => [['Décaissements', '/admin/decaissements'], [$d['reference'], null]]],
    ui('badge', ['label' => DisbursementController::STATES[$st] ?? $st, 'kind' => $kinds[$st] ?? 'neutral'])) ?>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?php if (!$operational && !in_array($st, ['paye', 'rejete', 'annule'], true)): ?>
<?= ui('alert', ['kind' => 'warn', 'title' => 'Circuit inopérant', 'message' => 'Approbations et paiement bloqués : deux personnes distinctes doivent être autorisées à approuver.']) ?>
<p><a class="cell-link" href="/admin/decaissements/inoperant">Ce qu'il faut configurer</a></p>
<?php endif; ?>
<?php if ($buttons !== ''): ?><div class="form-actions"><?= $buttons ?></div><?php endif; ?>
<?= ui('panel', ['title' => 'Avancement'], ui('validation-steps', ['steps' => $steps])) ?>
<?php if (in_array($st, ['soumis', 'approuve_1'], true)): ?>
<?= ui('panel', ['title' => 'Approbations'], '<p>Approbations valides pour la révision n° ' . $rev . ' : <strong>' . $circuit['valid'] . ' sur ' . $circuit['required'] . ' requises</strong>. '
    . 'Approbateurs possibles restants : ' . $circuit['other_eligible_approvers'] . '.</p>'
    . ($circuit['stalled'] ? ui('alert', ['kind' => 'warn', 'title' => 'Il manque des approbateurs possibles : désignez-en un autre',
        'message' => 'Le super administrateur doit attribuer la permission « Approuver un décaissement » (Administration > Rôles) à une personne active, autre que l\'initiateur et que les approbateurs qui se sont déjà prononcés sur cette révision.']) : '')) ?>
<?php endif; ?>
<?= ui('panel', ['title' => 'Détail de la demande'], $dl) ?>
<h2 class="section-title">Justificatifs</h2>
<?= ui('table', ['caption' => 'Justificatifs', 'rows' => $attachments, 'columns' => [
    ['label', 'Justificatif', 'left', false, static fn (array $a): string => '<a class="cell-link" href="' . e($base . '/pieces/' . $a['file_id']) . '">' . e($a['label'] ?? $a['original_name']) . '</a>'],
    ['original_name', 'Fichier', 'left', false, static fn (array $a): string => e($a['original_name'])],
], 'empty' => ['title' => 'Aucun justificatif', 'message' => 'Au moins un justificatif est exigé pour soumettre la demande.']]) ?>
<?php if ($payment !== null): ?>
<?= ui('panel', ['title' => 'Paiement'], '<dl class="detail-list"><dt>Payé le</dt><dd>' . e(date('d/m/Y H:i', strtotime((string) $payment['paid_at']))) . '</dd>'
    . '<dt>Mode</dt><dd>' . e($methods[$payment['method']] ?? $payment['method']) . '</dd><dt>Référence externe</dt><dd>' . e((string) ($payment['external_ref'] ?? '—')) . '</dd>'
    . '<dt>Payé par</dt><dd>' . e((string) $payment['payer_name']) . '</dd>'
    . '<dt>Preuve</dt><dd><a class="cell-link" href="' . e($base . '/pieces/' . $payment['proof_file_id']) . '">' . e($payment['proof_name']) . '</a></dd></dl>') ?>
<?php endif; ?>
<h2 class="section-title">Décisions des approbateurs (toutes révisions)</h2>
<?= ui('table', ['caption' => 'Décisions des approbateurs (toutes révisions)', 'rows' => $approvals, 'columns' => [
    ['approver_name', 'Approbateur', 'left', false, static fn (array $a): string => '<strong>' . e((string) $a['approver_name']) . '</strong>'],
    ['decision', 'Décision', 'left', false, static fn (array $a): string => ui('badge', $a['invalidated_at'] !== null ? ['label' => 'Caduque (demande modifiée)', 'kind' => 'neutral']
        : ($a['decision'] === 'approve' ? ['label' => 'Approuvée', 'kind' => 'ok'] : ['label' => 'Rejetée', 'kind' => 'danger'])), 'status'],
    ['revision', 'Révision', 'left', false, static fn (array $a): string => 'n° ' . e((string) $a['revision'])],
    ['decided_at', 'Date', 'left', false, static fn (array $a): string => '<span class="nowrap">' . e(date_fr($a['decided_at'])) . '</span>'],
    ['comment', 'Commentaire', 'left', false, static fn (array $a): string => e((string) $a['comment'])],
], 'empty' => ['title' => 'Aucune décision pour le moment']]) ?>
<h2 class="section-title">Historique des révisions</h2>
<?= ui('table', ['caption' => 'Historique des révisions', 'rows' => $revisions, 'columns' => [
    ['revision', 'Révision', 'left', false, static fn (array $r): string => '<strong>n° ' . e((string) $r['revision']) . '</strong>'],
    ['created_at', 'Date', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['created_at'])) . '</span>'],
    ['changed_by_name', 'Par', 'left', false, static fn (array $r): string => e((string) $r['changed_by_name'])],
    ['reason', 'Motif', 'left', false, static fn (array $r): string => e((string) $r['reason'])],
    ['snapshot', 'Contenu', 'left', false, static function (array $r) use ($amount): string {
        $s = json_decode((string) $r['snapshot'], true) ?: [];
        return e($amount(isset($s['amount']) ? (string) $s['amount'] : null) . ' · ' . ($s['beneficiary_name'] ?? '') . ' · ' . ($s['purpose'] ?? '') . ' · '
            . count((array) ($s['attachments'] ?? [])) . ' justificatif(s)');
    }],
], 'empty' => ['title' => 'Aucune révision enregistrée', 'message' => 'L\'historique commence à la soumission.']]) ?>
<?php if ($allowed('approve')): ?>
<?= ui('confirm-dialog', ['id' => 'confirm-approve', 'title' => 'Approuver cette demande ?', 'message' => 'Vous approuvez la révision n° ' . $rev . ' : ' . $amount((string) $d['amount']) . ' à ' . $d['beneficiary_name'] . '. L\'opération est consignée au journal.',
    'confirm_label' => 'Approuver', 'form_action' => $base . '/approuver', 'csrf' => $csrfToken, 'fields' => ['seen_revision' => (string) $rev]]) ?>
<?php endif; ?>
