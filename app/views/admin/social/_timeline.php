<?php declare(strict_types=1);
/** Chronologie (événements du circuit). Commentaires internes : instruction seulement. @var list<array> $events @var bool $instruction */
use App\Modules\Social\SocialWorkflow;

$actionLabels = ['register' => 'Enregistrement', 'assign' => 'Affectation', 'reassign' => 'Réaffectation', 'requestDocuments' => 'Pièces demandées',
    'resumeInstruction' => 'Reprise de l\'instruction', 'addReview' => 'Avis d\'instruction', 'submitForDecision' => 'Soumission à décision', 'decide' => 'Décision',
    'prepareDisbursement' => 'Décaissement préparé', 'paymentFailed' => 'Échec du décaissement', 'close' => 'Clôture'];
$columns = [
    ['action', 'Étape', 'left', false, static fn (array $ev): string => '<strong>' . e($actionLabels[$ev['action']] ?? $ev['action']) . '</strong>'],
    ['to_status', 'État', 'left', false, static fn (array $ev): string => e(($ev['from_status'] !== null && $ev['from_status'] !== $ev['to_status'] ? (SocialWorkflow::LABELS[$ev['from_status']] ?? '') . ' → ' : '')
        . (SocialWorkflow::LABELS[$ev['to_status']] ?? $ev['to_status']))],
    ['author', 'Par', 'left', false, static fn (array $ev): string => e((string) $ev['author'])],
    ['created_at', 'Date', 'left', false, static fn (array $ev): string => '<span class="nowrap">' . e(date_fr($ev['created_at'])) . '</span>'],
];
if ($instruction) {
    $columns[] = ['comment', 'Commentaire interne', 'left', false, static fn (array $ev): string => e((string) $ev['comment'])];
}
?>
<h2 class="section-title">Chronologie</h2>
<?= ui('table', ['caption' => 'Chronologie', 'rows' => array_reverse($events), 'columns' => $columns, 'empty' => ['title' => 'Aucun événement']]) ?>
