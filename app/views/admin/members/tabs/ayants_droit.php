<?php declare(strict_types=1);
/** @var array $rows @var callable $fmt */
echo ui('table', ['caption' => 'Ayants droit', 'rows' => $rows, 'columns' => [
    ['last_name', 'Nom', 'left', false, static fn (array $r): string => '<strong>' . e($r['last_name'] . ' ' . $r['first_names']) . '</strong>'],
    ['relationship', 'Lien'],
    ['birth_date', 'Naissance', 'left', false, static fn (array $r): string => e($fmt($r['birth_date']))],
    ['phone', 'Téléphone', 'left', false, static fn (array $r): string => e($r['phone'] ?? '—')],
], 'empty' => ['title' => 'Aucun ayant droit enregistré', 'message' => 'Les conjoints et enfants déclarés apparaîtront ici.']]);
