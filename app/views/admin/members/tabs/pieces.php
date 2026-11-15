<?php declare(strict_types=1);
/** @var array $m @var array $rows @var array $docTypes @var string $csrfToken */
$base = '/admin/adherents/' . $m['id'] . '/pieces';
echo ui('table', ['caption' => 'Pièces déposées', 'rows' => $rows, 'columns' => [
    ['type_label', 'Type', 'left', false, static fn (array $r): string => '<strong>' . e($r['type_label']) . '</strong>'],
    ['original_name', 'Fichier', 'left', false, static fn (array $r): string => '<a href="' . e($base . '/' . $r['file_id']) . '">' . e($r['original_name']) . '</a>'],
    ['created_at', 'Déposée le', 'left', false, static fn (array $r): string => e(date_fr($r['created_at'], '—'))],
], 'empty' => ['title' => 'Aucune pièce enregistrée', 'message' => 'Les pièces déposées apparaîtront ici ; leur consultation est journalisée.']]);
echo ui('panel', ['title' => 'Déposer une pièce'], '<form method="post" enctype="multipart/form-data" action="' . e($base) . '"><input type="hidden" name="_csrf" value="' . e($csrfToken) . '">'
    . ui('field', ['name' => 'type', 'label' => 'Type de pièce', 'type' => 'select', 'options' => $docTypes, 'required' => true])
    . ui('file-input', ['name' => 'piece', 'label' => 'Fichier', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'PDF, JPG ou PNG.', 'max_mb' => 5])
    . '<div class="form-actions">' . ui('button', ['label' => 'Déposer', 'type' => 'submit']) . '</div></form>');
