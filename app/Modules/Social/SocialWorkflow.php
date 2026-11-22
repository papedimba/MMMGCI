<?php
declare(strict_types=1);

namespace App\Modules\Social;

/** Circuit d'une demande sociale (A5.2) : source unique des passages d'état autorisés, chacun porté par une seule action. */
final class SocialWorkflow
{
    public const STATUSES = ['recue', 'en_instruction', 'en_attente_pieces', 'soumise_decision', 'accordee', 'refusee', 'en_paiement', 'cloturee'];

    /** de => [vers => action] ; toute autre paire est refusée ; cloturee est terminal. */
    public const TRANSITIONS = [
        'recue' => ['en_instruction' => 'assign'],
        'en_instruction' => ['en_attente_pieces' => 'requestDocuments', 'soumise_decision' => 'submitForDecision'],
        'en_attente_pieces' => ['en_instruction' => 'resumeInstruction'],
        'soumise_decision' => ['accordee' => 'decide', 'refusee' => 'decide'],
        'accordee' => ['en_paiement' => 'prepareDisbursement', 'cloturee' => 'close'],
        'refusee' => ['cloturee' => 'close'],
        'en_paiement' => ['accordee' => 'paymentFailed', 'cloturee' => 'close'],
        'cloturee' => [],
    ];

    /** Actions sans changement d'état (événement écrit, statut inchangé) : action => états où elle est permise. */
    public const IN_PLACE = [
        'assign' => ['en_instruction', 'en_attente_pieces'], // réaffectation
        'addReview' => ['en_instruction'],
    ];

    public const LABELS = ['recue' => 'reçue', 'en_instruction' => 'en instruction', 'en_attente_pieces' => 'en attente de pièces',
        'soumise_decision' => 'soumise à décision', 'accordee' => 'accordée', 'refusee' => 'refusée', 'en_paiement' => 'en paiement', 'cloturee' => 'clôturée'];

    /** @throws SocialTransitionInvalid si la paire n'est pas dans TRANSITIONS, ou (si $action est donnée) n'est pas portée par cette action */
    public static function assert(string $from, string $to, ?string $action = null): void
    {
        $a = self::TRANSITIONS[$from][$to] ?? null;
        if ($a === null || ($action !== null && $a !== $action)) {
            throw new SocialTransitionInvalid('Passage impossible : la demande est ' . (self::LABELS[$from] ?? 'dans un état inconnu') . ', elle ne peut pas devenir '
                . (self::LABELS[$to] ?? 'cet état') . ' par cette action.');
        }
    }

    /** État d'arrivée de $action depuis $from (null si l'action ne change pas l'état ou n'y est pas permise ; plusieurs arrivées : la décision choisit). */
    public static function target(string $from, string $action): ?string
    {
        $to = array_keys(self::TRANSITIONS[$from] ?? [], $action, true);
        return count($to) === 1 ? $to[0] : null;
    }

    public static function permits(string $from, string $action): bool
    {
        return in_array($action, self::TRANSITIONS[$from] ?? [], true) || in_array($from, self::IN_PLACE[$action] ?? [], true);
    }
}
