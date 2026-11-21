<?php declare(strict_types=1);
/** @var list<array<string,mixed>> $rows @var array{status:string,form:string} $filters @var int $page @var int $pages @var int $total */
$base = '/admin/site/formulaires';
$kinds = ['nouveau' => 'warn', 'en_cours' => 'info', 'traite' => 'ok', 'archive' => 'neutral'];
$labels = \App\Modules\Cms\FormInbox::STATUS_LABELS;
$forms = array_map(static fn (array $f): string => $f[0], \App\Modules\Cms\FormInbox::FORMS);
$query = array_filter($filters, static fn (string $v): bool => $v !== '');
?>
<?= ui('page-header', ['title' => 'Formulaires reçus', 'subtitle' => 'Messages envoyés par les visiteurs du site. Les coordonnées des visiteurs ne figurent jamais dans le journal d\'audit.'],
    ui('button', ['label' => 'Exporter en CSV', 'href' => $base . '/export.csv' . ($query === [] ? '' : '?' . http_build_query($query)), 'variant' => 'secondary'])) ?>
<form method="get" action="<?= e($base) ?>" class="filter-form">
<?= ui('field', ['name' => 'status', 'label' => 'État', 'type' => 'select', 'value' => $filters['status'], 'options' => ['' => 'Tous'] + $labels]) ?>
<?= ui('field', ['name' => 'form', 'label' => 'Formulaire', 'type' => 'select', 'value' => $filters['form'], 'options' => ['' => 'Tous'] + $forms]) ?>
<button class="btn btn-secondary" type="submit"><span>Filtrer</span></button>
</form>
<?= ui('table', [
    'caption' => 'Envois reçus (' . $total . ')',
    'empty' => ['title' => 'Aucun envoi', 'message' => 'Aucun envoi ne correspond.'],
    'rows' => $rows,
    'columns' => [
        ['reference', 'Référence', '', false, static fn (array $a): string => '<a class="cell-link" href="' . e($base . '/' . $a['id']) . '">' . e($a['reference']) . '</a>'],
        ['status', 'État', '', false, static fn (array $a): string => ui('badge', ['kind' => $kinds[$a['status']] ?? 'neutral', 'label' => $labels[$a['status']] ?? $a['status']]), 'status'],
        ['form_name', 'Formulaire'],
        ['assignee', 'Affecté à'],
        ['created_at', 'Reçu le', '', false, static fn (array $a): string => e((new DateTimeImmutable($a['created_at']))->format('d/m/Y H:i'))],
    ],
]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => $base, 'query' => $query]) ?>
