<?php declare(strict_types=1);
/** Vérification des soldes et contrôles d'intégrité : simple lecture, rien n'est corrigé ni journalisé. @var array $balances @var array $anomalies */
use App\Core\Money;
$tab = 'soldes'; $heading = 'Soldes et intégrité'; $subtitle = 'Solde affiché comparé à un recalcul indépendant, puis contrôles de cohérence.'; $dataset = null; $query = [];
include __DIR__ . '/_head.php';
$ko = array_filter($balances, static fn (array $b): bool => !$b['ok']);
$links = ['payment' => null, 'disbursement' => '/admin/controle/decaissements/', 'transaction' => null, 'receipt' => null];
$bad = array_sum(array_column($anomalies, 'count'));
?>
<?php if ($ko !== []): ?><?= ui('alert', ['kind' => 'danger', 'title' => 'Écart de solde détecté', 'message' => count($ko) . ' compte(s) dont le solde affiché diffère du recalcul. Rien n\'est corrigé ici : signalez-le aux responsables.']) ?>
<?php else: ?><?= ui('alert', ['kind' => 'ok', 'message' => 'Aucun écart : tous les soldes affichés correspondent au recalcul.']) ?><?php endif; ?>
<?= ui('table', ['caption' => 'Soldes par compte', 'rows' => $balances, 'columns' => [
    ['name', 'Compte', 'left', false, static fn (array $b): string => '<strong>' . e($b['name']) . '</strong>'],
    ['ok', 'Résultat', 'left', false, static fn (array $b): string => ui('badge', $b['ok'] ? ['label' => 'OK', 'kind' => 'ok'] : ['label' => 'KO', 'kind' => 'danger']), 'status'],
    ['displayed', 'Solde affiché', 'right', true, static fn (array $b): string => '<span class="nowrap">Affiché : ' . e(Money::format($b['displayed'])) . '</span>'],
    ['recomputed', 'Solde recalculé', 'right', true, static fn (array $b): string => '<span class="nowrap">Recalculé : ' . e(Money::format($b['recomputed'])) . '</span>'],
    ['gap', 'Écart', 'right', true, static fn (array $b): string => '<span class="nowrap">Écart : ' . e(($b['gap'] < 0 ? '−' : '') . Money::format(abs($b['gap']))) . '</span>'],
    ['count', 'Écritures', 'right', true, static fn (array $b): string => 'Écritures : ' . e((string) $b['count'])],
], 'empty' => ['title' => 'Aucun compte']]) ?>
<h2 class="section-title">Contrôles d'intégrité</h2>
<?= $bad === 0 ? ui('alert', ['kind' => 'ok', 'message' => 'Aucune anomalie détectée.']) : ui('alert', ['kind' => 'danger', 'title' => 'Anomalies détectées', 'message' => $bad . ' élément(s) à examiner. Rien n\'est corrigé ici.']) ?>
<?= ui('table', ['caption' => 'Contrôles d\'intégrité', 'rows' => $anomalies, 'columns' => [
    ['label', 'Contrôle', 'left', false, static fn (array $a): string => '<strong>' . e($a['label']) . '</strong>'],
    ['count', 'Anomalies', 'left', false, static fn (array $a): string => ui('badge', $a['count'] === 0 ? ['label' => 'Aucune', 'kind' => 'ok'] : ['label' => (string) $a['count'], 'kind' => 'danger']), 'status'],
    ['ids', 'Identifiants', 'left', false, static function (array $a) use ($links): string {
        $base = $links[$a['table']] ?? null;
        $out = array_map(static fn (int $id): string => $base !== null ? '<a class="cell-link" href="' . e($base . $id) . '">n° ' . $id . '</a>' : 'n° ' . $id, $a['ids']);
        return $out === [] ? '—' : implode(', ', $out);
    }],
]]) ?>
