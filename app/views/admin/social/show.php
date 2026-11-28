<?php declare(strict_types=1);
/**
 * Page d'une demande sociale (personnel). Chaque bouton n'apparaît que si allowedActions l'autorise ; le service reste seul juge au POST.
 * Visibilité (SocialAccess) : social.view seul voit l'en-tête, l'avancement, la chronologie sans commentaires, la décision (sans motif ni message)
 * et le décaissement lié ; instruction (manage|decide) : description, examen, avis, notes, pièces ; santé : social.medical.view, sur clic.
 * @var array $req @var string $tab @var array $tabs @var ?string $error @var bool $instruction @var bool $medical @var list<string> $actions @var list<string> $linkActions
 * @var ?array $decision @var list<array> $events @var list<array> $disbursements @var bool $readsDisbursements @var array $assignees @var string $csrfToken @var Closure $can
 */
use App\Core\Money;
use App\Modules\Disbursements\DisbursementController;
use App\Modules\Social\SocialMemberView;
use App\Modules\Social\SocialRequestService;
use App\Modules\Social\SocialTypeService;
use App\Modules\Social\SocialWorkflow;

$id = (int) $req['id'];
$base = '/admin/demandes-sociales/' . $id;
$st = (string) $req['status'];
$label = static fn (string $s): string => mb_strtoupper(mb_substr(SocialWorkflow::LABELS[$s] ?? $s, 0, 1)) . mb_substr(SocialWorkflow::LABELS[$s] ?? $s, 1);
$kinds = ['recue' => 'info', 'en_instruction' => 'info', 'en_attente_pieces' => 'warn', 'soumise_decision' => 'warn', 'accordee' => 'ok', 'refusee' => 'danger', 'en_paiement' => 'info', 'cloturee' => 'neutral'];
$money = static fn (?string $v): string => $v === null ? '—' : Money::format(Money::fromDecimal($v));
$allowed = static fn (string $a): bool => in_array($a, $actions, true);
$csrf = '<input type="hidden" name="_csrf" value="' . e($csrfToken) . '">';

// Avancement : reçue → instruction → soumise à décision → décision → [paiement] → clôturée (état réel).
$financial = $decision !== null && $decision['decision'] === 'accordee' && $decision['aid_kind'] === 'financiere';
$when = static function (string $to) use ($events): array {
    foreach (array_reverse($events) as $ev) {
        if ($ev['to_status'] === $to && $ev['from_status'] !== $to) {
            return ['who' => (string) $ev['author'], 'when' => date_fr($ev['created_at'])];
        }
    }
    return [];
};
$order = ['recue' => 0, 'en_instruction' => 1, 'en_attente_pieces' => 1, 'soumise_decision' => 2, 'accordee' => 3, 'refusee' => 3, 'en_paiement' => 4, 'cloturee' => 6];
$pos = $order[$st] ?? 0;
if ($st === 'accordee') {
    $pos = $financial ? 4 : 5;
} elseif ($st === 'refusee') {
    $pos = 5;
}
$stepState = static fn (int $i): string => $i < $pos ? 'done' : ($i === $pos ? 'current' : 'todo');
$steps = [['label' => 'Reçue', 'state' => $stepState(0)] + $when('recue'),
    ['label' => $st === 'en_attente_pieces' ? 'Instruction : pièces demandées' : 'Instruction', 'state' => $stepState(1)] + $when('en_instruction'),
    ['label' => 'Soumise à décision', 'state' => $stepState(2)] + $when('soumise_decision'),
    $decision === null ? ['label' => 'Décision', 'state' => $stepState(3)]
        : ['label' => 'Décision : ' . ($decision['decision'] === 'accordee' ? 'accordée' : 'refusée'), 'state' => $decision['decision'] === 'accordee' ? 'done' : 'rejected', 'when' => date('d/m/Y', strtotime((string) $decision['decided_at']))]];
if ($financial || $st === 'en_paiement') {
    $steps[] = ['label' => 'Paiement (circuit des décaissements)', 'state' => $stepState(4)];
}
$steps[] = ['label' => 'Clôturée', 'state' => $st === 'cloturee' ? 'done' : $stepState(5)] + ($st === 'cloturee' ? $when('cloturee') : []);

// Détail : social.view voit ces champs ; la description relève de l'instruction.
$details = ['Adhérent' => $req['last_name'] . ' ' . $req['first_names'] . ' (' . $req['member_number'] . ')', 'Ayant droit' => $req['beneficiary_name'] ?: '—',
    'Type' => $req['type_name'], 'Priorité' => SocialRequestService::PRIORITIES[$req['priority']] ?? $req['priority'], 'Reçue le' => date('d/m/Y', strtotime((string) $req['received_at'])),
    'Canal' => SocialRequestService::CHANNELS[$req['channel']] ?? $req['channel'], 'Responsable' => $req['assignee_name'] ?? 'Non affectée',
    'Statut interne' => $label($st), 'Ce que voit l\'adhérent' => SocialMemberView::statusOf($st)];
if ($instruction) {
    $details['Description'] = $req['description'];
    if ($st === 'en_attente_pieces') {
        $details['Pièces demandées'] = (string) $req['documents_requested_text'];
    }
}
$dl = '<dl class="detail-list">';
foreach ($details as $k => $v) {
    $dl .= '<dt>' . e($k) . '</dt><dd>' . e((string) $v) . '</dd>';
}
$dl .= '</dl>';

// Actions autorisées (service), dans l'ordre du circuit.
$forms = '';
if ($allowed('assign')) {
    $forms .= '<form method="post" action="' . e($base) . '/affecter" data-action="assign">' . $csrf
        . ui('field', ['name' => 'assignee', 'label' => $req['assigned_to'] === null ? 'Affecter à' : 'Réaffecter à', 'type' => 'select', 'options' => $assignees, 'required' => true,
            'hint' => 'Personne autorisée à gérer les demandes sociales et ayant accès à cet adhérent. Elle est prévenue par e-mail (référence seulement).'])
        . ui('button', ['label' => $req['assigned_to'] === null ? 'Affecter' : 'Réaffecter', 'type' => 'submit']) . '</form>';
}
if ($allowed('requestDocuments')) {
    $forms .= '<form method="post" action="' . e($base) . '/pieces-demandees" data-action="requestDocuments">' . $csrf
        . ui('field', ['name' => 'documents', 'label' => 'Pièces à demander à l\'adhérent', 'type' => 'textarea', 'required' => true, 'attrs' => ['maxlength' => 1000],
            'hint' => 'Texte montré à l\'adhérent dans son espace. N\'y mettez aucune donnée de santé. 1 000 caractères au maximum.'])
        . ui('button', ['label' => 'Demander des pièces', 'variant' => 'secondary', 'type' => 'submit']) . '</form>';
}
if ($allowed('resumeInstruction')) {
    $forms .= '<form method="post" action="' . e($base) . '/reprendre" data-action="resumeInstruction">' . $csrf
        . ui('field', ['name' => 'comment', 'label' => 'Commentaire interne (facultatif)', 'type' => 'textarea', 'attrs' => ['maxlength' => 1000],
            'hint' => 'Jamais montré à l\'adhérent ; pas de donnée de santé. 1 000 caractères au maximum.'])
        . ui('button', ['label' => 'Reprendre l\'instruction', 'type' => 'submit']) . '</form>';
}
$buttons = '';
if ($allowed('addReview')) {
    $buttons .= '<span data-action="addReview">' . ui('button', ['label' => 'Ajouter un avis', 'variant' => 'secondary', 'href' => $base . '?onglet=examen#avis']) . '</span>';
}
if ($allowed('submitForDecision')) {
    $buttons .= '<form method="post" action="' . e($base) . '/soumettre" data-action="submitForDecision">' . $csrf . ui('button', ['label' => 'Soumettre à décision', 'type' => 'submit']) . '</form>';
}
if ($allowed('decide')) {
    $buttons .= '<span data-action="decide">' . ui('button', ['label' => 'Enregistrer la décision', 'href' => $base . '/decision']) . '</span>';
}
if (in_array('prepare', $linkActions, true)) {
    $buttons .= '<span data-action="prepare">' . ui('button', ['label' => 'Préparer le décaissement', 'href' => $base . '/decaissement']) . '</span>';
}
if (in_array('paymentFailed', $linkActions, true)) {
    $buttons .= '<form method="post" action="' . e($base) . '/decaissement/echec" data-action="paymentFailed">' . $csrf
        . ui('button', ['label' => 'Constater l\'échec du décaissement', 'variant' => 'secondary', 'type' => 'submit']) . '</form>';
}
if ($allowed('close')) {
    $forms .= '<form method="post" action="' . e($base) . '/cloturer" id="close-form" data-action="close">' . $csrf
        . ui('field', ['name' => 'closure_note', 'label' => 'Note de clôture (facultatif)', 'type' => 'textarea', 'attrs' => ['maxlength' => 2000],
            'hint' => 'Interne ; pas de donnée de santé. 2 000 caractères au maximum.'])
        . '<button class="btn btn-danger" type="submit" data-confirm-open="confirm-close"><span>Clôturer la demande</span></button></form>';
}
?>
<?= ui('page-header', ['title' => 'Demande ' . $req['reference'], 'subtitle' => $req['type_name'] . ' · ' . $req['last_name'] . ' ' . $req['first_names'],
    'breadcrumbs' => [['Assistance sociale', null], [$req['reference'], null]]],
    ui('badge', ['label' => $label($st), 'kind' => $kinds[$st] ?? 'neutral'])) ?>
<p class="page-subtitle member-facing">Ce que voit l'adhérent : <strong><?= e(SocialMemberView::statusOf($st)) ?></strong></p>
<?php if ($error !== null): ?><?= ui('alert', ['kind' => 'danger', 'message' => $error]) ?><?php endif; ?>
<?php if ($buttons !== ''): ?><div class="form-actions"><?= $buttons ?></div><?php endif; ?>
<?php if ($forms !== ''): ?><?= ui('panel', ['title' => 'Actions'], '<div class="stack-forms">' . $forms . '</div>') ?><?php endif; ?>
<?= ui('panel', ['title' => 'Avancement'], ui('validation-steps', ['steps' => $steps])) ?>
<?= ui('tabs', ['label' => 'Sections de la demande', 'items' => array_map(static fn (string $k, string $l): array => [$l, $base . '?onglet=' . $k, $k === $tab], array_keys($tabs), $tabs)]) ?>
<?php if ($tab === 'suivi'): ?>
<?= ui('panel', ['title' => 'Détail de la demande'], $dl) ?>
<?php if ($decision !== null):
    $dd = ['Décision' => $decision['decision'] === 'accordee' ? 'Accordée' : 'Refusée', 'Sorte d\'aide' => SocialTypeService::AID_KINDS[$decision['aid_kind']] ?? '—',
        'Montant' => $money($decision['amount']), 'Organe' => $decision['deciding_body'], 'Référence de la décision' => $decision['decision_ref'],
        'Date' => date('d/m/Y', strtotime((string) $decision['decided_at']))];
    if ($instruction) {
        $dd += ['Motif' => $decision['reason'], 'Message à l\'adhérent' => $decision['member_message'], 'Saisie par' => (string) $decision['recorded_by_name']];
    }
    $html = '<dl class="detail-list">';
    foreach ($dd as $k => $v) {
        $html .= '<dt>' . e($k) . '</dt><dd>' . e((string) $v) . '</dd>';
    }
    echo ui('panel', ['title' => 'Décision'], $html . '</dl>');
endif; ?>
<?php if ($disbursements !== []): ?>
<?= ui('table', ['caption' => 'Décaissement lié', 'rows' => $disbursements, 'columns' => [
    ['reference', 'Décaissement', 'left', false, static fn (array $d): string => $readsDisbursements
        ? '<a class="cell-link" href="/admin/decaissements/' . (int) $d['id'] . '">' . e($d['reference']) . '</a>' : e($d['reference'])],
    ['status', 'État', 'left', false, static fn (array $d): string => ui('badge', ['label' => DisbursementController::STATES[$d['status']] ?? $d['status'], 'kind' => $d['status'] === 'paye' ? 'ok' : 'neutral']), 'status'],
    ['amount', 'Montant', 'right', true, static fn (array $d): string => e($money((string) $d['amount']))],
]]) ?>
<?php endif; ?>
<?php if ($st === 'cloturee' && $instruction && (string) $req['closure_note'] !== ''): ?>
<?= ui('panel', ['title' => 'Note de clôture'], '<p>' . e((string) $req['closure_note']) . '</p>') ?>
<?php endif; ?>
<?php require __DIR__ . '/_timeline.php'; ?>
<?php elseif ($tab === 'examen'): ?>
<?php require __DIR__ . '/_notes.php'; ?>
<?php elseif ($tab === 'pieces'): ?>
<?php require __DIR__ . '/_documents.php'; ?>
<?php elseif ($tab === 'sante'): ?>
<?php require __DIR__ . '/_medical.php'; ?>
<?php endif; ?>
<?php if ($allowed('close')): ?>
<?= ui('confirm-dialog', ['id' => 'confirm-close', 'form' => 'close-form', 'title' => 'Clôturer cette demande ?',
    'message' => 'La clôture est définitive : plus aucune action, pièce ni note ne pourra être ajoutée. L\'opération est consignée au journal.', 'confirm_label' => 'Clôturer']) ?>
<?php endif; ?>
