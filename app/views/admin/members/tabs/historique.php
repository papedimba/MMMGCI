<?php declare(strict_types=1);
/** @var array $rows */
echo ui('table', ['caption' => 'Historique des statuts', 'rows' => $rows, 'columns' => [
    ['created_at', 'Date', 'left', false, static fn (array $r): string => '<span class="nowrap">' . e(date_fr($r['created_at'])) . '</span>'],
    ['to_label', 'Changement', 'left', false, static fn (array $r): string => e(($r['from_label'] ?? $r['from_status'] ?? 'Création') . ' → ' . ($r['to_label'] ?? $r['to_status']))],
    ['reason', 'Motif', 'left', false, static fn (array $r): string => e((string) ($r['reason'] ?? '') !== '' ? $r['reason'] : '—')],
    ['decision_ref', 'Référence', 'left', false, static fn (array $r): string => e((string) ($r['decision_ref'] ?? '') !== '' ? $r['decision_ref'] : '—')],
    ['author', 'Par', 'left', false, static fn (array $r): string => e($r['author'] ?? '—')],
], 'empty' => ['title' => 'Aucun changement de statut', 'message' => 'Le statut n\'a pas été modifié depuis la création de la fiche.']]);
