<?php declare(strict_types=1);
/** @var array $m @var array $requests @var Closure $can */
use App\Modules\Social\SocialWorkflow;

$cap = static fn (string $s): string => mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
$columns = [
    ['reference', 'Référence', 'left', false, static fn (array $r): string => '<a class="cell-link" href="/admin/demandes-sociales/' . e((string) $r['id']) . '"><strong>' . e($r['reference']) . '</strong></a>'],
    ['type_name', 'Type', 'left', false, static fn (array $r): string => e($r['type_name'])],
    ['received_at', 'Reçue le', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date('d/m/Y', strtotime((string) $r['received_at']))) . '</span>'],
    ['status', 'Statut', 'left', false, static fn (array $r): string => e($cap(SocialWorkflow::LABELS[$r['status']] ?? $r['status']))],
];
$new = $can('social.create') ? ['label' => 'Nouvelle demande', 'icon' => 'plus', 'href' => '/admin/demandes-sociales/nouvelle?membre=' . $m['id']] : null;
?>
<?php if ($new): ?><p><?= ui('button', $new) ?></p><?php endif; ?>
<?= ui('table', ['caption' => 'Demandes d\'assistance de cet adhérent', 'columns' => $columns, 'rows' => $requests,
    'empty' => ['title' => 'Aucune demande d\'assistance', 'message' => 'Cet adhérent n\'a pas de demande enregistrée.'] + ($new ? ['action' => $new] : [])]) ?>
