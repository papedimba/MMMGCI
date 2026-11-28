<?php declare(strict_types=1);
/**
 * Examen (instruction seulement) : contexte non bloquant, avis, notes internes. Rien de cela n'est montré à l'adhérent.
 * @var array $req @var string $base @var string $csrf @var Closure $allowed @var Closure $money @var array $context @var ?array $fund @var list<array> $reviews @var list<array> $notes
 */
use App\Core\Money;
use App\Modules\Social\SocialNoteService;
use App\Modules\Social\SocialRequestService;

$pending = 'non configuré (paramètre en attente de validation par les instances)';
$ctx = '<dl class="detail-list"><dt>Statut de l\'adhérent</dt><dd>' . e($context['member_status']) . '</dd>'
    . '<dt>Appels de cotisation impayés</dt><dd>' . (int) $context['unpaid_calls'] . '</dd>'
    . '<dt>Adhésion</dt><dd>' . e($context['joined_at'] === null ? '—' : date('d/m/Y', strtotime((string) $context['joined_at']))) . '</dd>'
    . '<dt>Délai de carence</dt><dd>' . e($context['waiting_period_days'] === null ? $pending : $context['waiting_period_days'] . ' jours (validé)') . '</dd>'
    . '<dt>Plafond du type</dt><dd>' . e($context['ceiling'] === null ? $pending : Money::format($context['ceiling']) . ' (validé)') . '</dd>'
    . '<dt>Fonds de solidarité</dt><dd>' . e($fund === null ? 'aucun compte de fonds actif' : 'solde ' . Money::format($fund['balance'])) . '</dd></dl>'
    . '<p class="field-hint">Informations d\'aide à l\'examen, jamais bloquantes ; toute aide est accordée sous réserve des ressources disponibles.</p>';
?>
<?= ui('panel', ['title' => 'Contexte d\'examen'], $ctx) ?>
<h2 class="section-title" id="avis">Avis d'instruction</h2>
<?= ui('table', ['caption' => 'Avis d\'instruction', 'rows' => $reviews, 'columns' => [
    ['reviewer', 'Instructeur', 'left', false, static fn (array $v): string => '<strong>' . e((string) $v['reviewer']) . '</strong>'],
    ['opinion', 'Avis', 'left', false, static fn (array $v): string => ui('badge', ['label' => SocialRequestService::OPINIONS[$v['opinion']] ?? $v['opinion'],
        'kind' => ['favorable' => 'ok', 'defavorable' => 'danger'][$v['opinion']] ?? 'warn']), 'status'],
    ['recommended_amount', 'Montant proposé', 'right', true, static fn (array $v): string => e($money($v['recommended_amount']))],
    ['created_at', 'Date', 'left', false, static fn (array $v): string => '<span class="nowrap">' . e(date_fr($v['created_at'])) . '</span>'],
    ['comment', 'Commentaire', 'left', false, static fn (array $v): string => e((string) $v['comment'])],
], 'empty' => ['title' => 'Aucun avis', 'message' => 'Au moins un avis est exigé avant la soumission à décision.']]) ?>
<?php if ($allowed('addReview')): ?>
<form method="post" action="<?= e($base) ?>/avis">
<?= $csrf ?>
<?= ui('panel', ['title' => 'Ajouter un avis'],
    ui('field', ['name' => 'opinion', 'label' => 'Avis', 'type' => 'select', 'options' => ['' => 'Choisir'] + SocialRequestService::OPINIONS, 'required' => true])
    . ui('field', ['name' => 'recommended_amount', 'label' => 'Montant proposé en XOF (facultatif)', 'inputmode' => 'decimal', 'hint' => 'Laissez vide si aucun montant n\'est proposé. Par exemple 150 000.'])
    . ui('field', ['name' => 'comment', 'label' => 'Commentaire', 'type' => 'textarea', 'required' => true, 'attrs' => ['maxlength' => 5000],
        'hint' => 'Jamais montré à l\'adhérent ; pas de donnée de santé. 5 000 caractères au maximum.'])
    . '<div class="form-actions">' . ui('button', ['label' => 'Enregistrer l\'avis', 'type' => 'submit']) . '</div>') ?>
</form>
<?php endif; ?>
<h2 class="section-title">Notes internes</h2>
<?= ui('table', ['caption' => 'Notes internes', 'rows' => $notes, 'columns' => [
    ['author', 'Auteur', 'left', false, static fn (array $n): string => '<strong>' . e((string) $n['author']) . '</strong>'],
    ['created_at', 'Date', 'left', false, static fn (array $n): string => '<span class="nowrap">' . e(date_fr($n['created_at'])) . '</span>'],
    ['body', 'Note', 'left', false, static fn (array $n): string => e((string) $n['body'])],
], 'empty' => ['title' => 'Aucune note']]) ?>
<?php if ($req['status'] !== 'cloturee'): ?>
<form method="post" action="<?= e($base) ?>/notes">
<?= $csrf ?>
<?= ui('panel', ['title' => 'Ajouter une note'], ui('field', ['name' => 'body', 'label' => 'Note', 'type' => 'textarea', 'required' => true, 'attrs' => ['maxlength' => 5000],
    'hint' => SocialNoteService::WARNING . ' 5 000 caractères au maximum.'])
    . '<div class="form-actions">' . ui('button', ['label' => 'Ajouter la note', 'type' => 'submit', 'variant' => 'secondary']) . '</div>') ?>
</form>
<?php endif; ?>
