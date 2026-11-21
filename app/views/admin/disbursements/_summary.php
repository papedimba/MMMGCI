<?php declare(strict_types=1);
/** Résumé de ce que la personne approuve, rejette ou paie (montant, bénéficiaire, motif, compte, révision) : `$html = require` . @var array $d */
use App\Core\Money;
use App\Modules\Disbursements\DisbursementController;
$pairs = ['Référence' => $d['reference'], 'État' => DisbursementController::STATES[$d['status']] ?? $d['status'], 'Montant' => Money::format(Money::fromDecimal((string) $d['amount'])),
    'Bénéficiaire' => $d['beneficiary_name'], 'Motif' => $d['purpose'], 'Compte débité' => $d['account_name'], 'Catégorie' => $d['category_name'],
    'Révision' => 'n° ' . $d['revision'], 'Initiée par' => (string) $d['initiator_name']];
$out = '<dl class="detail-list">';
foreach ($pairs as $k => $v) {
    $out .= '<dt>' . e($k) . '</dt><dd>' . e((string) $v) . '</dd>';
}
return $out . '</dl>';
