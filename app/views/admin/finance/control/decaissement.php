<?php declare(strict_types=1);
/** Historique complet d'une demande, sans aucune action. Pièces : route de téléchargement existante (mêmes contrôles, consultation journalisée). @var array $d */
use App\Core\Money;
use App\Modules\Disbursements\DisbursementController;
$tab = 'decaissements'; $heading = 'Demande ' . $d['reference']; $subtitle = $d['beneficiary_name']; $dataset = null; $query = [];
include __DIR__ . '/_head.php';
$amount = static fn (?string $v): string => $v === null ? '—' : Money::format(Money::fromDecimal($v));
$file = static fn (int $fid, string $name): string => '<a class="cell-link" href="/admin/decaissements/' . e((string) $d['id']) . '/pieces/' . e((string) $fid) . '">' . e($name) . '</a>';
$details = ['État' => DisbursementController::STATES[$d['status']] ?? $d['status'], 'Montant' => $amount((string) $d['amount']), 'Bénéficiaire' => $d['beneficiary_name'], 'Motif' => $d['purpose'],
    'Compte débité' => $d['account_name'], 'Catégorie' => $d['category_name'], 'Révision courante' => 'n° ' . $d['revision'], 'Initiée par' => (string) $d['initiator_name'], 'Créée le' => date_fr($d['created_at'])];
if ($d['status'] === 'rejete') {
    $details['Rejetée par'] = (string) $d['rejected_by_name'];
    $details['Motif du rejet'] = (string) $d['rejection_reason'];
}
if ($d['cancelled_at'] !== null) {
    $details['Annulée le'] = date_fr($d['cancelled_at']);
}
$dl = '<dl class="detail-list">';
foreach ($details as $k => $v) {
    $dl .= '<dt>' . e($k) . '</dt><dd>' . e((string) $v) . '</dd>';
}
$dl .= '</dl>';
$p = $d['payment'];
?>
<?= ui('panel', ['title' => 'Détail de la demande'], $dl) ?>
<?php if ($p !== null): ?>
<?= ui('panel', ['title' => 'Paiement'], '<dl class="detail-list"><dt>Payé le</dt><dd>' . e(date('d/m/Y H:i', strtotime((string) $p['paid_at']))) . '</dd><dt>Payé par</dt><dd>' . e((string) $p['payer_name']) . '</dd>'
    . '<dt>Mode</dt><dd>' . e($p['method']) . '</dd><dt>Référence externe</dt><dd>' . e((string) ($p['external_ref'] ?? '—')) . '</dd>'
    . '<dt>Écriture</dt><dd>' . e((string) ($p['transaction_reference'] ?? 'aucune')) . '</dd>'
    . '<dt>Preuve</dt><dd>' . $file((int) $p['proof_file_id'], (string) $p['proof_name']) . '</dd></dl>'
    . ($d['reversal'] !== null ? ui('badge', ['label' => 'Contre-passation ' . $d['reversal']['reference'] . ' du ' . date('d/m/Y', strtotime((string) $d['reversal']['occurred_at'])), 'kind' => 'warn']) : '')) ?>
<?php endif; ?>
<h2 class="section-title">Justificatifs</h2>
<?= ui('table', ['caption' => 'Justificatifs', 'rows' => $d['attachments'], 'columns' => [
    ['label', 'Justificatif', 'left', false, static fn (array $a): string => $file((int) $a['file_id'], (string) ($a['label'] ?? $a['original_name']))],
    ['original_name', 'Fichier', 'left', false, static fn (array $a): string => e($a['original_name'])],
], 'empty' => ['title' => 'Aucun justificatif']]) ?>
<h2 class="section-title">Décisions des approbateurs (toutes révisions, caduques comprises)</h2>
<?= ui('table', ['caption' => 'Décisions des approbateurs', 'rows' => $d['approvals'], 'columns' => [
    ['approver_name', 'Approbateur', 'left', false, static fn (array $a): string => '<strong>' . e((string) $a['approver_name']) . '</strong>'],
    ['decision', 'Décision', 'left', false, static fn (array $a): string => ui('badge', $a['decision'] === 'approve' ? ['label' => 'Approbation', 'kind' => 'ok'] : ['label' => 'Rejet', 'kind' => 'danger']), 'status'],
    ['invalidated_at', 'Validité', 'left', false, static fn (array $a): string => $a['invalidated_at'] !== null ? ui('badge', ['label' => 'Caduque depuis le ' . date_fr($a['invalidated_at']), 'kind' => 'warn']) : 'Valable'],
    ['revision', 'Révision', 'left', false, static fn (array $a): string => 'n° ' . e((string) $a['revision'])],
    ['decided_at', 'Date', 'left', false, static fn (array $a): string => '<span class="nowrap">' . e(date_fr($a['decided_at'])) . '</span>'],
    ['comment', 'Commentaire', 'left', false, static fn (array $a): string => e((string) $a['comment'])],
], 'empty' => ['title' => 'Aucune décision enregistrée']]) ?>
<h2 class="section-title">Historique des révisions</h2>
<?= ui('table', ['caption' => 'Historique des révisions', 'rows' => $d['revisions'], 'columns' => [
    ['revision', 'Révision', 'left', false, static fn (array $r): string => '<strong>n° ' . e((string) $r['revision']) . '</strong>'],
    ['created_at', 'Date', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['created_at'])) . '</span>'],
    ['changed_by_name', 'Par', 'left', false, static fn (array $r): string => e((string) $r['changed_by_name'])],
    ['reason', 'Motif', 'left', false, static fn (array $r): string => e((string) $r['reason'])],
    ['snapshot', 'Contenu', 'left', false, static function (array $r) use ($amount): string {
        $s = json_decode((string) $r['snapshot'], true) ?: [];
        return e($amount(isset($s['amount']) ? (string) $s['amount'] : null) . ' · ' . ($s['beneficiary_name'] ?? '') . ' · ' . ($s['purpose'] ?? '') . ' · ' . count((array) ($s['attachments'] ?? [])) . ' justificatif(s)');
    }],
], 'empty' => ['title' => 'Aucune révision enregistrée']]) ?>
<p><a class="cell-link" href="/admin/controle/decaissements">Retour à la liste</a></p>
