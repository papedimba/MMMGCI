<?php declare(strict_types=1);
/** @var array $rows @var Closure $can */
use App\Core\Money;
use App\Modules\Social\SocialTypeService;
$validate = $can('settings.validate');
$pending = static fn (): string => ui('badge', ['label' => 'Non configuré', 'kind' => 'warn']);
$ok = static fn (array $r): bool => $r['params_decision_ref'] !== null;
$columns = [
    ['name', 'Type', 'left', false, static fn (array $r): string => '<strong>' . e($r['name']) . '</strong><br><span class="muted">' . e($r['action_text']) . '</span>'],
    ['criteria', 'Critères', 'left', false, static fn (array $r): string => $ok($r) && $r['criteria_text'] !== null ? e($r['criteria_text']) : $pending()],
    ['ceiling', 'Plafond', 'right', false, static fn (array $r): string => $ok($r) && $r['ceiling_amount'] !== null ? '<span class="nowrap">' . e(Money::format(Money::fromDecimal($r['ceiling_amount']))) . '</span>' : $pending()],
    ['waiting', 'Carence (jours)', 'right', false, static fn (array $r): string => $ok($r) && $r['waiting_period_days'] !== null ? e((string) $r['waiting_period_days']) : $pending()],
    ['body', 'Organe compétent', 'left', false, static fn (array $r): string => $ok($r) && $r['deciding_body'] !== null ? e($r['deciding_body']) : $pending()],
    ['kinds', 'Sortes d\'aide', 'left', false, static fn (array $r): string => $ok($r) && $r['allowed_aid_kinds'] !== null
        ? e(implode(', ', array_map(static fn (string $k): string => SocialTypeService::AID_KINDS[$k] ?? $k, json_decode($r['allowed_aid_kinds'], true)))) : $pending()],
    ['state', 'État', 'left', false, static fn (array $r): string => $r['is_active'] ? ui('badge', ['label' => 'Actif', 'kind' => 'ok']) : ui('badge', ['label' => 'Désactivé', 'kind' => 'neutral'])],
];
$rowActions = $validate ? static fn (array $r): string => ui('button', ['label' => 'Paramètres', 'variant' => 'secondary', 'size' => 'sm', 'href' => '/admin/demandes-sociales/types/' . $r['id'] . '/parametres']) : null;
?>
<?= ui('page-header', ['title' => 'Types de demande d\'assistance', 'subtitle' => 'Sous réserve des ressources disponibles, des textes applicables et des critères adoptés.'],
    ui('button', ['label' => 'Paramètres généraux', 'variant' => 'secondary', 'href' => '/admin/demandes-sociales/parametres'])
    . ($validate ? ui('button', ['label' => 'Nouveau type', 'icon' => 'plus', 'href' => '/admin/demandes-sociales/types/nouveau']) : '')) ?>
<?= ui('alert', ['kind' => 'info', 'message' => 'Paramètre en attente de validation par les instances : tant qu\'un paramètre est « non configuré », le logiciel n\'en tire aucune règle ; toute aide reste soumise à des critères écrits, à un examen et à la validation de l\'organe compétent.']) ?>
<?= ui('table', ['caption' => 'Types de demande', 'columns' => $columns, 'rows' => $rows, 'row_actions' => $rowActions,
    'empty' => ['title' => 'Aucun type', 'message' => 'Les types de l\'article 9 sont installés avec le module.']]) ?>
