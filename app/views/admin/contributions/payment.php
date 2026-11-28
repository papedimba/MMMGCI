<?php declare(strict_types=1);
/** @var array $p @var ?array $call @var ?array $tx @var ?int $balance @var array $methods @var Closure $can */
use App\Core\Money;
$cancelled = $p['cancelled_at'] !== null;
$actions = ui('button', ['label' => 'Voir le reçu', 'href' => '/admin/cotisations/paiements/' . $p['id'] . '/recu'])
    . (!$cancelled && $can('contributions.manage') ? ui('button', ['label' => 'Annuler ce paiement', 'variant' => 'danger', 'href' => '/admin/cotisations/paiements/' . $p['id'] . '/annuler']) : '');
?>
<?= ui('page-header', ['title' => 'Paiement ' . $p['reference'], 'breadcrumbs' => [['Cotisations', '/admin/cotisations/appels'], ['Paiement', null]]], $actions) ?>
<?php if ($cancelled): ?><?= ui('alert', ['kind' => 'warn', 'message' => 'Paiement annulé le ' . date_fr((string) $p['cancelled_at']) . ' — motif : ' . $p['cancel_reason']]) ?><?php endif; ?>
<?= ui('panel', ['title' => 'Détail'], '<dl class="detail-list">'
    . '<dt>Adhérent</dt><dd><a href="/admin/adherents/' . e((string) $p['member_id']) . '?onglet=cotisations">' . e($p['last_name'] . ' ' . $p['first_names']) . '</a> (' . e($p['member_number']) . ')</dd>'
    . '<dt>Appel</dt><dd>' . ($call === null ? '—' : e($call['type_name'] . ', ' . $call['period_label'])) . '</dd>'
    . '<dt>Montant</dt><dd>' . e(Money::format(Money::fromDecimal($p['amount']))) . '</dd>'
    . '<dt>Mode</dt><dd>' . e($methods[$p['method']] ?? $p['method']) . '</dd>'
    . '<dt>Date</dt><dd>' . e(date('d/m/Y H:i', strtotime((string) $p['paid_at']))) . '</dd>'
    . '<dt>Référence externe</dt><dd>' . e((string) ($p['external_ref'] ?? '—')) . '</dd>'
    . '<dt>Reçu</dt><dd>' . e((string) $p['receipt_number']) . '</dd>'
    . '<dt>Reçu par</dt><dd>' . e((string) ($p['received_by_name'] ?? '—')) . '</dd>'
    . '<dt>Écriture</dt><dd>' . e((string) ($tx['reference'] ?? '—')) . '</dd>'
    . ($cancelled && $balance !== null ? '<dt>Solde du compte après contre-passation</dt><dd>' . e(Money::format($balance)) . '</dd>' : '')
    . '</dl>') ?>
