<?php declare(strict_types=1);
/** Pièces (lues seulement sur cet onglet : SocialDocumentService::list journalise la lecture de libellés médicaux). @var list<array> $documents @var bool $canUpload @var string $base @var string $csrf */
use App\Modules\Social\SocialDocumentService;
?>
<h2 class="section-title">Pièces</h2>
<?= ui('table', ['caption' => 'Pièces de la demande', 'rows' => $documents, 'columns' => [
    ['label', 'Pièce', 'left', false, static fn (array $d): string => $d['downloadable']
        ? '<a class="cell-link" href="' . e($base . '/pieces/' . $d['id']) . '">' . e($d['label']) . '</a>' : e($d['label'])],
    ['kind', 'Nature', 'left', false, static fn (array $d): string => e(SocialDocumentService::KINDS[$d['kind']] ?? $d['kind'])],
    ['is_medical', 'Accès', 'left', false, static fn (array $d): string => $d['is_medical'] ? ui('badge', ['label' => 'Médicale', 'kind' => 'warn']) : '', 'status'],
    ['created_at', 'Déposée le', 'left', false, static fn (array $d): string => '<span class="nowrap">' . e(date_fr($d['created_at'])) . '</span>'],
], 'empty' => ['title' => 'Aucune pièce']]) ?>
<?php if ($canUpload): ?>
<form method="post" enctype="multipart/form-data" action="<?= e($base) ?>/pieces">
<?= $csrf ?>
<?= ui('panel', ['title' => 'Déposer une pièce'],
    ui('file-input', ['name' => 'piece', 'label' => 'Fichier', 'accept' => '.pdf,.jpg,.jpeg,.png', 'hint' => 'PDF, JPG ou PNG.', 'max_mb' => 5, 'required' => true])
    . ui('field', ['name' => 'kind', 'label' => 'Nature', 'type' => 'select', 'options' => SocialDocumentService::KINDS])
    . ui('field', ['name' => 'label', 'label' => 'Libellé (facultatif)', 'attrs' => ['maxlength' => 160], 'hint' => '160 caractères au maximum.'])
    . '<label class="check"><input type="checkbox" name="is_medical" value="1"><span>Pièce médicale (accès restreint)</span></label>'
    . '<div class="form-actions">' . ui('button', ['label' => 'Déposer', 'type' => 'submit', 'icon' => 'upload']) . '</div>') ?>
</form>
<?php endif; ?>
