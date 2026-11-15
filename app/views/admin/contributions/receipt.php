<?php declare(strict_types=1);
/** @var array $rc @var array $methods */
use App\Core\Money;
$cancelled = $rc['cancelled_at'] !== null;
?>
<?php if ($cancelled): ?><h2>ANNULÉ</h2><p>Annulé le <?= e(date_fr((string) $rc['cancelled_at'])) ?> — motif : <?= e((string) $rc['cancel_reason']) ?></p><?php endif; ?>
<table>
<tbody>
<tr><th scope="row">Reçu n°</th><td><?= e($rc['receipt_number']) ?></td></tr>
<tr><th scope="row">Adhérent</th><td><?= e($rc['last_name'] . ' ' . $rc['first_names']) ?> — n° <?= e($rc['member_number']) ?></td></tr>
<tr><th scope="row">Cotisation</th><td><?= e((string) ($rc['type_name'] ?? '—')) ?></td></tr>
<tr><th scope="row">Période</th><td><?= e((string) ($rc['period_label'] ?? '—')) ?></td></tr>
<tr><th scope="row">Montant reçu</th><td><strong><?= e(Money::format(Money::fromDecimal($rc['amount']))) ?></strong></td></tr>
<tr><th scope="row">Mode de paiement</th><td><?= e($methods[$rc['method']] ?? $rc['method']) ?><?= $rc['external_ref'] !== null ? ' — réf. ' . e($rc['external_ref']) : '' ?></td></tr>
<tr><th scope="row">Date du paiement</th><td><?= e(date('d/m/Y H:i', strtotime((string) $rc['paid_at']))) ?></td></tr>
<tr><th scope="row">Reçu par</th><td><?= e((string) ($rc['received_by_name'] ?? '—')) ?></td></tr>
</tbody>
</table>
