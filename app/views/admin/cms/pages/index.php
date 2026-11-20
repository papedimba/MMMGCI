<?php declare(strict_types=1);
/** @var list<array<string,mixed>> $rows @var string $csrfToken */
$base = '/admin/site/pages';
$kinds = ['brouillon' => 'neutral', 'en_relecture' => 'warn', 'publie' => 'ok', 'programme' => 'info', 'archive' => 'neutral'];
$labels = \App\Modules\Cms\PageService::STATUS_LABELS;
$fmt = static fn (?string $d): string => $d === null ? '' : (new DateTimeImmutable($d))->format('d/m/Y H:i');
$order = static fn (array $row, string $dir, string $label): string => '<form method="post" action="' . e($base . '/' . $row['id'] . '/ordre') . '" class="inline-form">'
    . '<input type="hidden" name="_csrf" value="' . e($csrfToken) . '"><input type="hidden" name="dir" value="' . $dir . '">'
    . '<button class="btn btn-ghost btn-sm" type="submit"><span>' . $label . '<span class="visually-hidden"> : ' . e($row['title']) . '</span></span></button></form>';
?>
<?= ui('page-header', ['title' => 'Pages', 'subtitle' => 'Pages du site public, rangées par rubrique. Une page n\'est visible du public qu\'une fois publiée.'],
    ui('button', ['label' => 'Nouvelle page', 'href' => $base . '/nouvelle', 'icon' => 'plus'])) ?>
<?= ui('table', [
    'caption' => 'Pages du site',
    'empty' => ['title' => 'Aucune page', 'message' => 'Créez la première page du site.'],
    'rows' => $rows,
    'columns' => [
        ['title', 'Titre', '', false, static fn (array $p): string => '<span class="page-depth page-depth-' . min(4, (int) $p['depth']) . '">'
            . ((int) $p['depth'] > 0 ? '<span class="visually-hidden">Sous-page de niveau ' . ((int) $p['depth'] + 1) . ' : </span>' : '')
            . '<a href="' . e($base . '/' . $p['id']) . '">' . e($p['title']) . '</a> <span class="page-slug">/' . e($p['slug']) . '</span></span>'],
        ['status', 'État', '', false, static fn (array $p): string => ui('badge', ['kind' => $kinds[$p['status']] ?? 'neutral', 'label' => $labels[$p['status']] ?? $p['status']])
            . ($p['live'] && $p['status'] !== 'publie' ? ' ' . ui('badge', ['kind' => 'ok', 'label' => 'Version en ligne']) : '')
            . ($p['has_copy'] ? ' ' . ui('badge', ['kind' => 'warn', 'label' => 'Modifications à publier']) : ''), 'status'],
        ['author', 'Auteur'],
        ['updated_at', 'Mise à jour', '', false, static fn (array $p): string => e($fmt($p['updated_at']))],
    ],
    'row_actions' => static fn (array $p): string => $order($p, 'up', 'Monter') . $order($p, 'down', 'Descendre'),
]) ?>
