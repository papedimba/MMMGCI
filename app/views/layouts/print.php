<?php declare(strict_types=1);
/** Gabarit d'impression A4 (reçus, PV, rapports). Variables : $title, $reference?, $generatedAt? (défaut : maintenant, Africa/Abidjan), $content. */
$generated = $generatedAt ?? date_fr(gmdate('Y-m-d H:i:s'));
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Document') ?> — MMMGCI</title>
<link rel="stylesheet" href="/assets/css/tokens.css">
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/print.css">
<script src="/assets/js/js-flag.js"></script>
<script src="/assets/js/app.js" defer></script>
</head>
<body class="print">
<p class="print-bar"><button type="button" class="print-btn" data-print><?= ui_icon('print') ?>Imprimer / Enregistrer en PDF</button></p>
<div class="sheet">
<?= ui('document-header', ['title' => $title ?? '', 'reference' => $reference ?? '', 'date' => $generated]) ?>
<table class="doc-frame" role="presentation">
<tbody><tr><td><main class="doc-body">
<?= $content ?>
</main></td></tr></tbody>
<tfoot><tr><td><p class="doc-foot">Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire — document généré le <?= e($generated) ?></p></td></tr></tfoot>
</table>
</div>
</body>
</html>
