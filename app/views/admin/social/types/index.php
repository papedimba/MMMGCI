<?php declare(strict_types=1);
/** @var array $rows @var Closure $can */
use App\Core\Money;
use App\Modules\Social\SocialTypeService;
$validate = $can('settings.validate');
$pending = static fn (): string => ui('badge', ['label' => 'Non configuré', 'kind' => 'warn']);
$ok = static fn (array $r): bool => $r['params_decision_ref'] !== null;
$columns = [
    ['name', 'Type', 'left', false, static fn (array $r): string => '<strong>' . e($r['name']) . '</strong><br><span class="muted">' . e($r['action_text']) . '</span>'],
    // Une seule colonne pour les cinq paramètres : huit colonnes débordaient de l'écran à 768 px.
    ['params', 'Paramètres', 'left', false, static function (array $r) use ($ok, $pending): string {
        if (!$ok($r)) {
            return $pending();
        }
        $kinds = $r['allowed_aid_kinds'] === null ? null : implode(', ', array_map(static fn (string $k): string => SocialTypeService::AID_KINDS[$k] ?? $k, json_decode($r['allowed_aid_kinds'], true)));
        $lines = ['Critères' => $r['criteria_text'], 'Plafond' => $r['ceiling_amount'] === null ? null : Money::format(Money::fromDecimal($r['ceiling_amount'])),
            'Carence (jours)' => $r['waiting_period_days'] === null ? null : (string) $r['waiting_period_days'], 'Organe compétent' => $r['deciding_body'], 'Sortes d\'aide' => $kinds];
        $out = '';
        foreach ($lines as $label => $value) {
            $out .= '<span class="muted">' . e($label) . ' :</span> ' . ($value === null ? 'non configuré' : e($value)) . '<br>';
        }
        return $out . '<span class="muted">Décision ' . e($r['params_decision_ref']) . '</span>';
    }],
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
