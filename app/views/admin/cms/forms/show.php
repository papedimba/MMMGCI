<?php declare(strict_types=1);
/** @var array<string,mixed> $row @var string $error @var array<int,string> $assignees @var array<string,array<int,mixed>> $fields @var string $csrfToken */
$base = '/admin/site/formulaires';
$labels = \App\Modules\Cms\FormInbox::STATUS_LABELS;
$next = \App\Modules\Cms\FormInbox::TRANSITIONS[$row['status']] ?? [];
$post = static fn (string $path, string $inner): string => '<form method="post" action="' . e($base . '/' . $row['id'] . $path) . '" class="page-form"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">' . $inner . '</form>';
$data = '<dl class="detail-list">';
foreach ($row['payload'] as $k => $v) {
    $data .= '<dt>' . e($fields[$k][0] ?? (string) $k) . '</dt><dd class="prewrap">' . e((string) $v) . '</dd>';
}
$data .= '</dl>';
$stateForm = '';
foreach ($next as $to) {
    $stateForm .= $post('/etat', '<input type="hidden" name="to" value="' . e($to) . '"><button class="btn btn-primary" type="submit"><span>Passer à « ' . e($labels[$to]) . ' »</span></button>');
}
$opts = ['' => 'Personne'] + $assignees;
$history = '<ul class="history-list">';
$actions = ['recu' => 'Reçu', 'note' => 'Note ajoutée', 'affectation' => 'Affectation modifiée'];
foreach ($row['history'] as $h) {
    $act = (string) ($h['action'] ?? '');
    $act = str_starts_with($act, 'etat:') ? 'Passé à « ' . ($labels[substr($act, 5)] ?? substr($act, 5)) . ' »' : ($actions[$act] ?? $act);
    $who = ($h['by'] ?? null) ? ' (' . ($row['history_users'][(int) $h['by']] ?? 'utilisateur supprimé') . ')' : '';
    $history .= '<li><strong>' . e((string) ($h['at'] ?? '')) . '</strong> — ' . e($act) . e($who) . (($h['note'] ?? '') !== '' ? '<br><span class="prewrap">' . e((string) $h['note']) . '</span>' : '') . '</li>';
}
$history .= '</ul>';
?>
<?= ui('page-header', ['title' => 'Envoi ' . $row['reference'], 'subtitle' => $row['form_name'] . ' — ' . ($labels[$row['status']] ?? $row['status']), 'breadcrumbs' => [['Formulaires reçus', $base], [$row['reference']]]]) ?>
<?= $error !== '' ? ui('alert', ['kind' => 'danger', 'message' => $error]) : '' ?>
<?= ui('panel', ['title' => 'Contenu de l\'envoi'], $data) ?>
<?= ui('panel', ['title' => 'Traitement'], '<p>Affecté à : <strong>' . e($row['assignee'] ?? 'personne') . '</strong></p>' . $stateForm
    . $post('/affectation', ui('field', ['name' => 'assigned_to', 'label' => 'Affecter à', 'type' => 'select', 'value' => (string) ($row['assigned_to'] ?? ''), 'options' => $opts])
        . '<div class="form-actions"><button class="btn btn-secondary" type="submit"><span>Affecter</span></button></div>')) ?>
<?= ui('panel', ['title' => 'Notes internes et historique'], $post('/notes', ui('field', ['name' => 'note', 'label' => 'Nouvelle note (interne)', 'type' => 'textarea', 'required' => true, 'attrs' => ['maxlength' => 1000]])
    . '<div class="form-actions"><button class="btn btn-secondary" type="submit"><span>Ajouter la note</span></button></div>') . $history) ?>
