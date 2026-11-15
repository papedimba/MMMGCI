<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Database;
use App\Core\NotFound;
use App\Core\Sequence;
use DomainException;

/**
 * Demandes d'adhésion (diagramme A5.1). Le schéma n'a pas de table de transitions : chaque transition est consignée au journal d'audit
 * (le motif de rejet l'est aussi dans rejection_reason). La note de compléments n'existe que dans l'audit. Aucune donnée d'identité
 * (pièce, livret) n'est copiée dans l'audit : seul le payload de la demande les contient.
 */
final class ApplicationService
{
    public const STATES = ['recue' => 'Reçue', 'en_verification' => 'En vérification', 'complements_demandes' => 'Compléments demandés', 'validee' => 'Validée', 'rejetee' => 'Rejetée'];
    public const FIELDS = ['last_name', 'first_names', 'sex', 'birth_date', 'birth_place', 'nationality', 'phone', 'phone_alt', 'email', 'address',
        'zone_id', 'category_id', 'profession_id', 'specialty_id', 'employment_status', 'employer', 'joined_at',
        'id_document_type', 'id_document_number', 'seaman_book_number', 'seaman_book_expiry'];
    private const SOURCES = ['saisie', 'delegue', 'en_ligne'];

    public function __construct(private Database $db, private Audit $audit, private Sequence $sequence, private MemberService $members)
    {
    }

    /** Validée avec les règles de MemberService : valider() ne peut plus échouer pour une raison évitable. @param array<string,mixed> $payload */
    public function submit(array $payload, string $source, ?int $actor, ?string $ip): int
    {
        if (!in_array($source, self::SOURCES, true)) {
            throw new DomainException('Source de demande inconnue.');
        }
        $raw = [];
        foreach (self::FIELDS as $k) {
            $v = $payload[$k] ?? null;
            $raw[$k] = is_string($v) || is_int($v) ? trim((string) $v) : '';
        }
        $this->members->validate($raw, true);
        return $this->db->atomic(function () use ($raw, $source, $actor, $ip): int {
            $ref = $this->sequence->next('application', 'AD', 4);
            $id = $this->db->insert('membership_applications', ['reference' => $ref, 'payload' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'recue', 'source' => $source, 'ip' => $ip]);
            $this->audit->log($actor, 'application.submitted', 'membership_application', $id, null, ['reference' => $ref, 'source' => $source, 'zone_id' => $raw['zone_id']]);
            return $id;
        });
    }

    public function markVerifying(int $id, int $actor): void
    {
        $this->transition($id, ['recue', 'complements_demandes'], 'en_verification', 'application.verifying', $actor, ['reviewer_id' => $actor]);
    }

    public function requestComplements(int $id, string $note, int $actor): void
    {
        $note = trim($note);
        if ($note === '') {
            throw new DomainException('Indiquez les compléments demandés.');
        }
        $this->transition($id, ['recue', 'en_verification'], 'complements_demandes', 'application.complements_requested', $actor, ['reviewer_id' => $actor], ['note' => $note]);
    }

    public function reject(int $id, string $reason, int $actor): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Le motif du rejet est obligatoire.');
        }
        $this->transition($id, ['recue', 'en_verification', 'complements_demandes'], 'rejetee', 'application.rejected', $actor,
            ['reviewer_id' => $actor, 'rejection_reason' => $reason], ['reason' => $reason]);
    }

    /**
     * Crée le membre (n° attribué, historique initial) et lie la demande, le tout dans une transaction. La ligne est verrouillée (FOR UPDATE) :
     * un second appel concurrent attend, relit l'état « validée » et est refusé ; il n'y a donc qu'un membre par demande.
     * Homonymes : DuplicatesFound (transaction annulée) tant que $confirmDuplicate est faux.
     */
    public function validate(int $id, int $actor, bool $confirmDuplicate = false): int
    {
        return $this->db->atomic(function () use ($id, $actor, $confirmDuplicate): int {
            $app = $this->lock($id);
            if (!in_array($app['status'], ['recue', 'en_verification'], true)) {
                throw new DomainException('Cette demande ne peut plus être validée (état : ' . (self::STATES[$app['status']] ?? $app['status']) . ').');
            }
            $payload = json_decode((string) $app['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!$confirmDuplicate) {
                $found = $this->members->findDuplicates($payload, null, $actor);
                if ($found['visible'] !== [] || $found['hidden_count'] > 0) {
                    throw new DuplicatesFound($found);
                }
            }
            $memberId = $this->members->create($payload, $actor);
            $status = (string) $this->db->fetchValue('SELECT status FROM members WHERE id = ?', [$memberId]);
            $this->db->insert('member_status_history', ['member_id' => $memberId, 'from_status' => null, 'to_status' => $status,
                'reason' => 'Adhésion validée', 'decision_ref' => $app['reference'], 'changed_by' => $actor]);
            // Les pièces de la demande rejoignent la fiche (mêmes fichiers, pas de copie).
            $this->db->query('INSERT INTO member_documents (member_id, type, file_id) SELECT ?, type, file_id FROM membership_application_files WHERE application_id = ?', [$memberId, $id]);
            $this->db->update('membership_applications', ['status' => 'validee', 'member_id' => $memberId, 'reviewer_id' => $actor], ['id' => $id]);
            $this->audit->log($actor, 'application.validated', 'membership_application', $id, ['status' => $app['status']], ['status' => 'validee', 'member_id' => $memberId]);
            return $memberId;
        });
    }

    /** @return array<string,mixed> */
    private function lock(int $id): array
    {
        return $this->db->fetch('SELECT * FROM membership_applications WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound("Demande $id introuvable.");
    }

    /** @param list<string> $from @param array<string,mixed> $set @param array<string,mixed> $extra */
    private function transition(int $id, array $from, string $to, string $action, int $actor, array $set = [], array $extra = []): void
    {
        $this->db->atomic(function () use ($id, $from, $to, $action, $actor, $set, $extra): void {
            $app = $this->lock($id);
            if (!in_array($app['status'], $from, true)) {
                throw new DomainException('Cette action n\'est pas possible dans l\'état « ' . (self::STATES[$app['status']] ?? $app['status']) . ' ».');
            }
            $this->db->update('membership_applications', ['status' => $to] + $set, ['id' => $id]);
            $this->audit->log($actor, $action, 'membership_application', $id, ['status' => $app['status']], ['status' => $to] + $extra);
        });
    }
}
