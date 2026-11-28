<?php declare(strict_types=1);
/** Liste d'un type de contenu. @var string $subtitle @var string $base @var string $newLabel @var list<array<string,mixed>> $rows @var list<array<int,mixed>> $columns @var string $notice (HTML de confiance) */
?>
<?= ui('page-header', ['title' => $title, 'subtitle' => $subtitle], ui('button', ['label' => $newLabel, 'href' => $base . '/nouveau', 'icon' => 'plus'])) ?>
<?= $notice ?>
<?= ui('table', ['caption' => $title . ' (' . count($rows) . ')', 'empty' => ['title' => 'Aucun élément', 'message' => 'Rien n\'a encore été créé ici.'], 'rows' => $rows, 'columns' => $columns]) ?>
