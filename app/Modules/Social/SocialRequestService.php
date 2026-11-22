<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NotFound;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Contributions\ArrearsService;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDOException;

/**
 * Circuit des demandes d'assistance sociale (A5.2, CDC art. 9-10). Toutes les règles sont ici, jamais dans les vues ni les contrôleurs.
 *
 * Chaque action : run() -> Database::atomic (rejoint une transaction ouverte), verrou FOR UPDATE de la demande AVANT toute lecture de son
 * état (lock(), qui applique aussi le périmètre : hors zone = introuvable), contrôles (prédicats partagés avec allowedActions et
 * SocialWorkflow::TRANSITIONS), écritures, une ligne social_request_events, puis Audit::log EN DERNIER (identifiants, états, montants,
 * références : jamais de texte libre). Épinglé par SocialRequestServiceTest::test_audit_is_the_last_lock.
 *
 * Ordre des verrous : social_requests (FOR UPDATE) -> social_decisions -> sequences -> audit_chain. Les tests d'existence suivis d'un INSERT
 * (décision, avis) sont des lectures simples écrites seulement sous le verrou de la demande ; l'UNIQUE de social_decisions reste le filet (1062 traduit).
 *
 * Aucune règle non validée n'est appliquée : statut du membre, impayés et carence ne sont que des informations (context()) ; plafond,
 * sortes d'aide et organe compétent ne s'imposent que s'ils sont validés pour le type (SocialTypeService).
 */
final class SocialRequestService
{
    public const ACTIONS = ['assign', 'requestDocuments', 'resumeInstruction', 'addReview', 'submitForDecision', 'decide', 'close'];
    public const CHANNELS = ['guichet' => 'Guichet', 'delegue' => 'Délégué', 'telephone' => 'Téléphone', 'courrier' => 'Courrier', 'email' => 'E-mail'];
    public const PRIORITIES = ['normale' => 'Normale', 'urgente' => 'Urgente'];
    public const OPINIONS = ['favorable' => 'Favorable', 'defavorable' => 'Défavorable', 'reserve' => 'Réservé'];
    private const MAX_CENTS = 999_999_999_999_999; // DECIMAL(15,2)
    private const REGISTER_KEYS = ['member_id', 'beneficiary_id', 'type_id', 'received_at', 'channel', 'priority', 'description', 'contains_medical_data'];
    private const DECISION_KEYS = ['decision', 'aid_kind', 'amount', 'reason', 'member_message', 'decision_ref', 'deciding_body', 'decided_at'];
    private const VERBS = ['assign' => 'd\'affecter', 'requestDocuments' => 'de demander des pièces pour', 'resumeInstruction' => 'de reprendre l\'instruction de',
        'addReview' => 'd\'ajouter un avis à', 'submitForDecision' => 'de soumettre à décision', 'decide' => 'de décider', 'close' => 'de clôturer'];

    private MemberScope $scope;
    private SocialTypeService $types;
    private ArrearsService $arrears;
    private SocialAccess $access;

    public function __construct(private Database $db, private Audit $audit, private Sequence $sequence, private Gate $gate, private ?SocialNotifier $notifier = null)
    {
        $this->scope = new MemberScope($db, $gate);
        $this->access = new SocialAccess($db, $gate, $audit);
        $this->types = new SocialTypeService($db, $audit, $gate);
        $this->arrears = new ArrearsService($db, $this->scope, new Settings($db), $audit);
    }

    /** @param array<string,mixed> $data member_id, type_id, received_at (AAAA-MM-JJ), channel, description, beneficiary_id?, priority?, contains_medical_data? */
    public function register(array $data, int $actor, ?string $ip = null): int
    {
        $row = $this->cleanRequest($data);
        return $this->run(function () use ($row, $actor, $ip): int {
            $this->deny($this->permission($actor, 'social.create'));
            $this->scope->assertMayAccess($actor, $row['member_id']);
            if ((int) $this->db->fetchValue('SELECT is_active FROM social_request_types WHERE id = ?', [$row['type_id']]) !== 1) {
                throw new SocialRuleViolation('Choisissez un type de demande actif.');
            }
            if ($row['beneficiary_id'] !== null && $this->db->fetchValue('SELECT 1 FROM member_beneficiaries WHERE id = ? AND member_id = ?', [$row['beneficiary_id'], $row['member_id']]) === null) {
                throw new SocialRuleViolation('Cet ayant droit n\'est pas rattaché à l\'adhérent.');
            }
            $reference = $this->sequence->next('social_request', 'AS', 4);
            $id = $this->db->insert('social_requests', $row + ['reference' => $reference, 'status' => 'recue', 'created_by' => $actor]);
            $this->event($id, null, 'recue', 'register', $actor, null);
            $this->auditTransition($actor, 'registered', ['id' => $id, 'reference' => $reference, 'status' => null], 'recue', $ip, ['type_id' => $row['type_id'],
                'priority' => $row['priority'], 'contains_medical_data' => $row['contains_medical_data'] === 1, 'beneficiary_id' => $row['beneficiary_id']]);
            return $id;
        });
    }

    /** Affectation (reçue -> en instruction) ou réaffectation sans changement d'état. L'affecté est prévenu après la validation (référence et lien seulement). */
    public function assign(int $id, int $assignee, int $actor, ?string $ip = null): void
    {
        $this->run(function () use ($id, $assignee, $actor, $ip): void {
            $r = $this->lock($id, $actor);
            $this->deny($this->canAssign($r, $actor));
            $this->gate->forget($assignee);
            if (!$this->gate->allows($assignee, 'social.manage') || !$this->visible($assignee, (int) $r['member_id'])) {
                throw new SocialRuleViolation('Cette personne ne peut pas recevoir l\'affectation : compte actif, droit de gérer les demandes sociales et accès à cet adhérent requis.');
            }
            if ((int) $r['assigned_to'] === $assignee) {
                throw new SocialRuleViolation('La demande est déjà affectée à cette personne.');
            }
            $to = SocialWorkflow::target($r['status'], 'assign') ?? $r['status'];
            $this->db->update('social_requests', ['assigned_to' => $assignee, 'status' => $to], ['id' => $id]);
            $this->event($id, $r['status'], $to, $to === $r['status'] ? 'reassign' : 'assign', $actor, null);
            $this->auditTransition($actor, $to === $r['status'] ? 'reassigned' : 'assigned', $r, $to, $ip, ['assigned_to' => $assignee,
                'previous_assignee' => $r['assigned_to'] === null ? null : (int) $r['assigned_to']]);
        });
        $this->notifier?->assigned($id, $assignee);
    }

    /** $memberText est destiné à l'adhérent (affiché dans son espace tant que les pièces sont attendues). */
    public function requestDocuments(int $id, string $memberText, int $actor, ?string $ip = null): void
    {
        $text = $this->text($memberText, 1000, 'Pièces demandées') ?? throw new SocialRuleViolation('Indiquez à l\'adhérent les pièces demandées (1 000 caractères au maximum).');
        $this->run(function () use ($id, $text, $actor, $ip): void {
            $r = $this->lock($id, $actor);
            $this->deny($this->canStep($r, $actor, 'requestDocuments'));
            $this->db->update('social_requests', ['status' => 'en_attente_pieces', 'documents_requested_text' => $text], ['id' => $id]);
            $this->event($id, $r['status'], 'en_attente_pieces', 'requestDocuments', $actor, null);
            $this->auditTransition($actor, 'documents_requested', $r, 'en_attente_pieces', $ip);
        });
    }

    public function resumeInstruction(int $id, int $actor, ?string $comment, ?string $ip = null): void
    {
        $comment = $this->text($comment, 1000, 'Commentaire');
        $this->run(function () use ($id, $actor, $comment, $ip): void {
            $r = $this->lock($id, $actor);
            $this->deny($this->canStep($r, $actor, 'resumeInstruction'));
            $this->db->update('social_requests', ['status' => 'en_instruction'], ['id' => $id]);
            $this->event($id, $r['status'], 'en_instruction', 'resumeInstruction', $actor, $comment);
            $this->auditTransition($actor, 'instruction_resumed', $r, 'en_instruction', $ip);
        });
    }

    /** Avis d'instruction (plusieurs possibles), jamais montré à l'adhérent. $recommendedCents : montant proposé, facultatif. */
    public function addReview(int $id, string $opinion, ?int $recommendedCents, string $comment, int $actor, ?string $ip = null): int
    {
        if (!isset(self::OPINIONS[$opinion])) {
            throw new SocialRuleViolation('Choisissez un avis : favorable, défavorable ou réservé.');
        }
        if ($recommendedCents !== null && ($recommendedCents <= 0 || $recommendedCents > self::MAX_CENTS)) {
            throw new SocialRuleViolation('Montant proposé : laissez vide ou saisissez un montant strictement positif.');
        }
        $comment = $this->text($comment, 5000, 'Commentaire') ?? throw new SocialRuleViolation('Le commentaire de l\'avis est obligatoire (5 000 caractères au maximum).');
        return $this->run(function () use ($id, $opinion, $recommendedCents, $comment, $actor, $ip): int {
            $r = $this->lock($id, $actor);
            $this->deny($this->canStep($r, $actor, 'addReview'));
            $amount = $recommendedCents === null ? null : Money::toDecimal($recommendedCents);
            $reviewId = $this->db->insert('social_request_reviews', ['social_request_id' => $id, 'reviewer_id' => $actor, 'opinion' => $opinion,
                'recommended_amount' => $amount, 'comment' => $comment]);
            $this->event($id, $r['status'], $r['status'], 'addReview', $actor, null);
            $this->auditTransition($actor, 'review_added', $r, $r['status'], $ip, ['review_id' => $reviewId, 'opinion' => $opinion, 'recommended_amount' => $amount]);
            return $reviewId;
        });
    }

    public function submitForDecision(int $id, int $actor, ?string $ip = null): void
    {
        $this->run(function () use ($id, $actor, $ip): void {
            $r = $this->lock($id, $actor);
            $this->deny($this->canSubmit($r, $actor));
            $this->db->update('social_requests', ['status' => 'soumise_decision'], ['id' => $id]);
            $this->event($id, $r['status'], 'soumise_decision', 'submitForDecision', $actor, null);
            $this->auditTransition($actor, 'submitted', $r, 'soumise_decision', $ip);
        });
    }

    /**
     * Décision motivée et immuable (aucune modification ni suppression). Le montant est saisi par la décision, jamais pré-rempli.
     * @param array<string,mixed> $d decision (accordee|refusee), aid_kind, amount (centimes int), reason, member_message, decision_ref, deciding_body, decided_at? (AAAA-MM-JJ, défaut aujourd'hui)
     */
    public function decide(int $id, array $d, int $actor, ?string $ip = null): int
    {
        $in = $this->cleanDecision($d);
        return $this->run(function () use ($id, $in, $actor, $ip): int {
            $r = $this->lock($id, $actor);
            $this->deny($this->canDecide($r, $actor));
            $row = $this->decisionRow($r, $in) + ['social_request_id' => $id, 'recorded_by' => $actor];
            try {
                $decisionId = $this->db->insert('social_decisions', $row);
            } catch (PDOException $e) {
                throw $this->integrity($e, 'Une décision est déjà enregistrée.');
            }
            $this->db->update('social_requests', ['status' => $row['decision']], ['id' => $id]);
            $this->event($id, $r['status'], $row['decision'], 'decide', $actor, null);
            $this->auditTransition($actor, 'decided', $r, $row['decision'], $ip, ['decision_id' => $decisionId, 'decision' => $row['decision'], 'aid_kind' => $row['aid_kind'],
                'amount' => $row['amount'], 'decision_ref' => $row['decision_ref']]);
            return $decisionId;
        });
    }

    /** Depuis « refusée », « accordée » (aide non financière) ou « en paiement » (décaissement lié payé). Ensuite : plus aucune écriture. */
    public function close(int $id, ?string $note, int $actor, ?string $ip = null): void
    {
        $note = $this->text($note, 2000, 'Note de clôture');
        $this->run(function () use ($id, $note, $actor, $ip): void {
            $r = $this->lock($id, $actor);
            $this->deny($this->canClose($r, $actor));
            $this->db->query('UPDATE social_requests SET status = \'cloturee\', closed_at = UTC_TIMESTAMP(), closure_note = ? WHERE id = ?', [$note, $id]);
            $this->event($id, $r['status'], 'cloturee', 'close', $actor, null);
            $this->auditTransition($actor, 'closed', $r, 'cloturee', $ip, ['has_note' => $note !== null]);
        });
    }

    /** Actions que $userId peut faire maintenant (lecture seule, mêmes prédicats que les actions, hors contrôle de la saisie). @return list<string> */
    public function allowedActions(int $id, int $userId): array
    {
        $r = $this->db->fetch('SELECT * FROM social_requests WHERE id = ?', [$id]);
        if ($r === null || !$this->visible($userId, (int) $r['member_id'])) {
            return [];
        }
        $checks = ['assign' => fn () => $this->canAssign($r, $userId), 'requestDocuments' => fn () => $this->canStep($r, $userId, 'requestDocuments'),
            'resumeInstruction' => fn () => $this->canStep($r, $userId, 'resumeInstruction'), 'addReview' => fn () => $this->canStep($r, $userId, 'addReview'),
            'submitForDecision' => fn () => $this->canSubmit($r, $userId), 'decide' => fn () => $this->canDecide($r, $userId), 'close' => fn () => $this->canClose($r, $userId)];
        return array_keys(array_filter($checks, fn (callable $c): bool => $c() === null));
    }

    /**
     * Informations d'aide à l'examen, jamais bloquantes et sans notion « à jour » (règle non définie par le cahier des charges).
     * @return array{member_status: string, unpaid_calls: int, joined_at: ?string, waiting_period_days: ?int, ceiling: ?int}
     */
    public function context(int $id): array
    {
        $r = $this->db->fetch('SELECT r.member_id, r.type_id, m.status, m.joined_at FROM social_requests r JOIN members m ON m.id = r.member_id WHERE r.id = ?', [$id])
            ?? throw new NotFound('Demande sociale introuvable.');
        return ['member_status' => (string) $r['status'], 'unpaid_calls' => $this->arrears->memberSituation((int) $r['member_id'])['unpaid_calls'],
            'joined_at' => $r['joined_at'], 'waiting_period_days' => $this->types->waitingPeriodFor((int) $r['type_id']), 'ceiling' => $this->types->ceilingFor((int) $r['type_id'])];
    }

    // --- prédicats : null = autorisé, sinon l'exception à lever (source unique pour les actions et allowedActions) ---

    /** @param array<string,mixed> $r */
    private function canStep(array $r, int $actor, string $action): ?DomainException
    {
        return $this->permission($actor, 'social.manage') ?? $this->step($r, $action);
    }

    /** @param array<string,mixed> $r */
    private function canAssign(array $r, int $actor): ?DomainException
    {
        return $this->canStep($r, $actor, 'assign');
    }

    /** @param array<string,mixed> $r */
    private function canSubmit(array $r, int $actor): ?DomainException
    {
        return $this->canStep($r, $actor, 'submitForDecision')
            ?? ($this->db->fetchValue('SELECT 1 FROM social_request_reviews WHERE social_request_id = ? LIMIT 1', [(int) $r['id']]) === null
                ? new SocialRuleViolation('Ajoutez au moins un avis d\'instruction avant de soumettre la demande à décision.') : null);
    }

    /** @param array<string,mixed> $r */
    private function canDecide(array $r, int $actor): ?DomainException
    {
        return $this->permission($actor, 'social.decide') ?? $this->step($r, 'decide');
    }

    /** @param array<string,mixed> $r */
    private function canClose(array $r, int $actor): ?DomainException
    {
        $kind = fn (): ?string => $this->db->fetchValue('SELECT aid_kind FROM social_decisions WHERE social_request_id = ?', [(int) $r['id']]);
        return $this->canStep($r, $actor, 'close')
            ?? ($r['status'] === 'accordee' && $kind() === 'financiere'
                ? new SocialRuleViolation('Une aide financière accordée ne se clôture qu\'après le paiement de son décaissement.') : null)
            ?? ($r['status'] === 'en_paiement' && $this->db->fetchValue('SELECT 1 FROM disbursement_requests dr JOIN social_decisions d ON d.id = dr.social_decision_id
                    WHERE d.social_request_id = ? AND dr.status = \'paye\' LIMIT 1', [(int) $r['id']]) === null
                ? new SocialRuleViolation('Le décaissement lié n\'est pas encore payé : la demande ne peut pas être clôturée.') : null);
    }

    /** @param array<string,mixed> $r */
    private function step(array $r, string $action): ?SocialTransitionInvalid
    {
        return SocialWorkflow::permits($r['status'], $action) ? null
            : new SocialTransitionInvalid('Impossible ' . self::VERBS[$action] . ' cette demande : elle est ' . (SocialWorkflow::LABELS[$r['status']] ?? 'dans un état inconnu') . '.');
    }

    private function permission(int $actor, string $permission): ?DomainException
    {
        $this->gate->forget($actor); // jamais une permission mise en cache avant un retrait de rôle ou une désactivation
        return $this->gate->allows($actor, $permission) ? null
            : new SocialRuleViolation('Action refusée : votre compte doit être actif et autorisé pour cette étape de la demande sociale.');
    }

    private function deny(?DomainException $e): void
    {
        if ($e !== null) {
            throw $e;
        }
    }

    // --- lectures verrouillantes, écritures annexes ---

    /** Verrou de la demande avant toute lecture de son état ; hors périmètre de l'acteur : introuvable (on ne révèle pas son existence). @return array<string,mixed> */
    private function lock(int $id, int $actor): array
    {
        $r = $this->db->fetch('SELECT * FROM social_requests WHERE id = ? FOR UPDATE', [$id]);
        if ($r === null || !$this->visible($actor, (int) $r['member_id'])) {
            throw new NotFound('Demande sociale introuvable.');
        }
        return $r;
    }

    private function visible(int $userId, int $memberId): bool
    {
        return $this->access->visible($userId, $memberId);
    }

    private function event(int $id, ?string $from, string $to, string $action, int $actor, ?string $comment): void
    {
        $this->db->insert('social_request_events', ['social_request_id' => $id, 'from_status' => $from, 'to_status' => $to, 'action' => $action, 'user_id' => $actor, 'comment' => $comment]);
    }

    /** @param array<string,mixed> $r @param array<string,mixed> $extra */
    private function auditTransition(int $actor, string $action, array $r, string $to, ?string $ip, array $extra = []): void
    {
        $this->audit->log($actor, 'social.' . $action, 'social_request', (int) $r['id'], $r['status'] === null ? null : ['status' => $r['status']], ['status' => $to] + $extra, $r['reference'], $ip);
    }

    /** Transaction de l'action ; interblocage (1213) : un rejeu si la transaction est la nôtre ; sinon (ou attente trop longue, 1205) message « réessayez » si elle est la nôtre, SQL brut dans celle d'un appelant. */
    private function run(callable $fn): mixed
    {
        $owned = !$this->db->pdo()->inTransaction();
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->atomic($fn);
            } catch (PDOException $e) {
                $errno = $e->errorInfo[1] ?? 0;
                if ($errno === 1213 && $owned && $attempt === 1) {
                    continue;
                }
                throw $owned && in_array($errno, [1213, 1205], true) ? new SocialRuleViolation(SocialRuleViolation::BUSY, 0, $e) : $e;
            }
        }
    }

    /** Contraintes de la base (UNIQUE 1062, CHECK 3819/4025) : filet de sécurité traduit en erreur métier. */
    private function integrity(PDOException $e, string $message): DomainException|PDOException
    {
        return in_array($e->errorInfo[1] ?? 0, [1062, 3819, 4025], true) ? new SocialRuleViolation($message, 0, $e) : $e;
    }

    // --- validation de la saisie ---

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function cleanRequest(array $data): array
    {
        if (array_diff(array_keys($data), self::REGISTER_KEYS) !== []) {
            throw new SocialRuleViolation('Champ inconnu dans la demande.');
        }
        foreach (['member_id' => 'L\'adhérent', 'type_id' => 'Le type de demande'] as $k => $label) {
            if (!is_int($data[$k] ?? null)) {
                throw new SocialRuleViolation("$label est obligatoire.");
            }
        }
        $beneficiary = $data['beneficiary_id'] ?? null;
        if ($beneficiary !== null && !is_int($beneficiary)) {
            throw new SocialRuleViolation('Cet ayant droit n\'est pas rattaché à l\'adhérent.');
        }
        $channel = $data['channel'] ?? null;
        if (!is_string($channel) || !isset(self::CHANNELS[$channel])) {
            throw new SocialRuleViolation('Choisissez le canal de réception.');
        }
        $priority = $data['priority'] ?? 'normale';
        if (!is_string($priority) || !isset(self::PRIORITIES[$priority])) {
            throw new SocialRuleViolation('Priorité : normale ou urgente.');
        }
        $medical = $data['contains_medical_data'] ?? false;
        if (!is_bool($medical)) {
            throw new SocialRuleViolation('Indicateur « données de santé » invalide.');
        }
        return ['member_id' => $data['member_id'], 'beneficiary_id' => $beneficiary, 'type_id' => $data['type_id'],
            'received_at' => $this->date($data['received_at'] ?? null, 'La date de réception'), 'channel' => $channel, 'priority' => $priority,
            'description' => $this->text($data['description'] ?? null, 5000, 'Description') ?? throw new SocialRuleViolation('La description de la demande est obligatoire (5 000 caractères au maximum).'),
            'contains_medical_data' => $medical ? 1 : 0];
    }

    /** Contrôles sans état (forme de la saisie). @param array<string,mixed> $d @return array<string,mixed> */
    private function cleanDecision(array $d): array
    {
        if (array_diff(array_keys($d), self::DECISION_KEYS) !== []) {
            throw new SocialRuleViolation('Champ inconnu dans la décision.');
        }
        $decision = $d['decision'] ?? null;
        if (!in_array($decision, ['accordee', 'refusee'], true)) {
            throw new SocialRuleViolation('Choisissez la décision : accordée ou refusée.');
        }
        $kind = $d['aid_kind'] ?? null;
        $amount = $d['amount'] ?? null;
        if ($decision === 'refusee' && ($kind !== null || $amount !== null)) {
            throw new SocialRuleViolation('Un refus ne porte ni sorte d\'aide ni montant.');
        }
        if ($decision === 'accordee') {
            if (!is_string($kind) || !isset(SocialTypeService::AID_KINDS[$kind])) {
                throw new SocialRuleViolation('Choisissez la sorte d\'aide accordée.');
            }
            if ($kind === 'financiere' && (!is_int($amount) || $amount <= 0 || $amount > self::MAX_CENTS)) {
                throw new SocialRuleViolation('Le montant de l\'aide financière est obligatoire et doit être strictement positif.');
            }
            if ($kind !== 'financiere' && $amount !== null) {
                throw new SocialRuleViolation('Une aide non financière n\'a pas de montant : laissez le champ vide.');
            }
        }
        return ['decision' => $decision, 'aid_kind' => $kind, 'amount' => $amount,
            'reason' => $this->text($d['reason'] ?? null, 5000, 'Motif') ?? throw new SocialRuleViolation('Le motif de la décision est obligatoire.'),
            'member_message' => $this->text($d['member_message'] ?? null, 2000, 'Message à l\'adhérent') ?? throw new SocialRuleViolation('Le message destiné à l\'adhérent est obligatoire.'),
            'decision_ref' => $this->text($d['decision_ref'] ?? null, 60, 'Référence de la décision') ?? throw new SocialRuleViolation('La référence de la décision (procès-verbal, délibération) est obligatoire.'),
            'deciding_body' => $this->text($d['deciding_body'] ?? null, 160, 'Organe compétent'),
            'decided_at' => ($d['decided_at'] ?? null) === null ? Ledger::now()->format('Y-m-d') : $this->date($d['decided_at'], 'La date de la décision')];
    }

    /** Contrôles liés au type (paramètres validés seulement) et à la demande verrouillée. @param array<string,mixed> $r @param array<string,mixed> $in @return array<string,mixed> */
    private function decisionRow(array $r, array $in): array
    {
        $type = (int) $r['type_id'];
        if ($in['decided_at'] < $r['received_at']) {
            throw new SocialRuleViolation('La date de la décision ne peut pas précéder la réception de la demande.');
        }
        $body = $this->types->decidingBodyFor($type) ?? $in['deciding_body'] ?? throw new SocialRuleViolation('Indiquez l\'organe qui a pris la décision (aucun organe validé pour ce type).');
        $kinds = $this->types->allowedAidKinds($type);
        if ($in['decision'] === 'accordee' && $kinds !== null && !in_array($in['aid_kind'], $kinds, true)) {
            throw new SocialRuleViolation('Cette sorte d\'aide n\'est pas prévue pour ce type de demande (paramètres validés par les instances).');
        }
        $ceiling = $this->types->ceilingFor($type);
        if ($in['amount'] !== null && $ceiling !== null && $in['amount'] > $ceiling) {
            throw new SocialRuleViolation('Le montant dépasse le plafond validé pour ce type (' . Money::format($ceiling) . ').');
        }
        return ['decision' => $in['decision'], 'aid_kind' => $in['aid_kind'], 'amount' => $in['amount'] === null ? null : Money::toDecimal($in['amount']),
            'deciding_body' => $body, 'decision_ref' => $in['decision_ref'], 'reason' => $in['reason'], 'member_message' => $in['member_message'], 'decided_at' => $in['decided_at']];
    }

    /** Texte libre nettoyé (null si vide), borné à $max caractères ; partagé avec les pièces, notes et données de santé. */
    public static function text(mixed $v, int $max, string $label): ?string
    {
        if ($v !== null && !is_string($v)) {
            throw new SocialRuleViolation("$label : texte invalide.");
        }
        $t = trim((string) $v);
        if (mb_strlen($t) > $max) {
            throw new SocialRuleViolation("$label : $max caractères au maximum.");
        }
        return $t === '' ? null : $t;
    }

    /** Date AAAA-MM-JJ réelle, au plus aujourd'hui (Africa/Abidjan). */
    private function date(mixed $v, string $label): string
    {
        $d = is_string($v) ? DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('Africa/Abidjan')) : false;
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new SocialRuleViolation("$label est obligatoire (date valide).");
        }
        if ($v > Ledger::now()->format('Y-m-d')) {
            throw new SocialRuleViolation("$label ne peut pas être dans le futur.");
        }
        return $v;
    }
}
