<?php declare(strict_types=1);
/**
 * @var list<array<string,mixed>> $rows @var array{status:string,category:string,q:string} $filters @var int $page @var int $pages @var int $total
 * @var array<int,string> $categories @var callable $can
 */
$base = '/admin/site/articles';
$kinds = ['brouillon' => 'neutral', 'en_relecture' => 'warn', 'publie' => 'ok', 'programme' => 'info', 'archive' => 'neutral'];
$labels = \App\Modules\Cms\ArticleService::STATUS_LABELS;
$fmt = static fn (?string $d): string => $d === null ? '' : (new DateTimeImmutable($d))->format('d/m/Y H:i');
$actions = ui('button', ['label' => 'Nouvel article', 'href' => $base . '/nouveau', 'icon' => 'plus'])
    . ($can('cms.pages.manage') ? ui('button', ['label' => 'Catégories', 'href' => $base . '/categories', 'variant' => 'secondary']) . ui('button', ['label' => 'Étiquettes', 'href' => $base . '/etiquettes', 'variant' => 'secondary']) : '');
?>
<?= ui('page-header', ['title' => 'Articles', 'subtitle' => 'Actualités du site public. Un article n\'est visible du public qu\'une fois publié.'], $actions) ?>
<form method="get" action="<?= e($base) ?>" class="filter-form">
<?= ui('field', ['name' => 'q', 'label' => 'Recherche', 'type' => 'search', 'value' => $filters['q'], 'attrs' => ['maxlength' => 100]]) ?>
<?= ui('field', ['name' => 'status', 'label' => 'État', 'type' => 'select', 'value' => $filters['status'], 'options' => ['' => 'Tous'] + $labels]) ?>
<?= ui('field', ['name' => 'category', 'label' => 'Catégorie', 'type' => 'select', 'value' => $filters['category'], 'options' => ['' => 'Toutes'] + $categories]) ?>
<button class="btn btn-secondary" type="submit"><span>Filtrer</span></button>
</form>
<?= ui('table', [
    'caption' => 'Articles (' . $total . ')',
    'empty' => ['title' => 'Aucun article', 'message' => 'Aucun article ne correspond.'],
    'rows' => $rows,
    'columns' => [
        ['title', 'Titre', '', false, static fn (array $a): string => '<a class="cell-link" href="' . e($base . '/' . $a['id']) . '">' . e($a['title']) . '</a> <span class="page-slug">/' . e($a['slug']) . '</span>'],
        ['status', 'État', '', false, static fn (array $a): string => ui('badge', ['kind' => $kinds[$a['status']] ?? 'neutral', 'label' => $labels[$a['status']] ?? $a['status']])
            . ($a['has_copy'] ? ' ' . ui('badge', ['kind' => 'warn', 'label' => 'Modifications à publier']) : ''), 'status'],
        ['category', 'Catégorie'],
        ['author', 'Auteur'],
        ['published_at', 'Publication', '', false, static fn (array $a): string => e($fmt($a['published_at']))],
        ['updated_at', 'Mise à jour', '', false, static fn (array $a): string => e($fmt($a['updated_at']))],
    ],
]) ?>
<?= ui('pagination', ['page' => $page, 'pages' => $pages, 'base' => $base, 'query' => array_filter($filters, static fn (string $v): bool => $v !== '')]) ?>
