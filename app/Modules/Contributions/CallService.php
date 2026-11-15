<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Money;
use App\Core\Scheduler;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDOException;

/**
 * Appels de cotisation. Le montant est TOUJOURS celui du type (jamais saisi). Un appel ne se supprime pas : il s'annule avec un motif,
 * uniquement s'il n'a reçu aucun paiement non annulé. Population de l'appel groupé : adhérents « actif » non supprimés,
 * population concernée en attente de validation par les instances ; échéance = fin de période (défaut technique, à valider).
 */
final class CallService
{
    public const BATCH_BANNER = 'Population concernée en attente de validation par les instances : par défaut, les adhérents au statut « actif ». L\'échéance est la fin de la période (à valider).';

    public function __construct(private Database $db, private Audit $audit, private MemberScope $scope)
    {
    }

    /** Tâche planifiée : sans type actif (montant validé, périodicité définie), ne fait rien ; ne crée jamais pour une période non commencée. */
    public static function register(Scheduler $scheduler, Database $db, Audit $audit): void
    {
        $scheduler->register('contributions.generate_calls', 60, static function () use ($db, $audit): void {
            $svc = new self($db, $audit, new MemberScope($db, new \App\Core\Gate($db)));
            $types = $db->fetchAll("SELECT id FROM contribution_types WHERE status = 'actif' AND kind = 'periodique' AND amount IS NOT NULL AND periodicity IS NOT NULL");
            foreach ($types as $t) {
                $svc->generateBatch((int) $t['id'], Ledger::now(), null);
            }
        });
    }

    /** @param array{start?: string, due_date?: string} $period start = un jour de la période visée */
    public function createForMember(int $memberId, int $typeId, array $period, int $actor): int
    {
        $this->scope->assertMayAccess($actor, $memberId); // NotFound : adhérent inconnu, supprimé ou hors périmètre
        return $this->db->atomic(function () use ($memberId, $typeId, $period, $actor): int {
            $type = $this->callableType($typeId, true);
            $p = $this->period($type, $this->date($period['start'] ?? ''));
            $due = isset($period['due_date']) && $period['due_date'] !== '' ? $this->date($period['due_date'])->format('Y-m-d') : $p['end'];
            $row = ['member_id' => $memberId, 'contribution_type_id' => $typeId, 'period_label' => $p['label'], 'period_start' => $p['start'], 'period_end' => $p['end'],
                'amount_due' => $type['amount'], 'due_date' => $due, 'status' => 'appelee'];
            try {
                $id = $this->db->insert('contribution_calls', $row);
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Cet adhérent a déjà été appelé pour cette période.', 0, $e) : $e;
            }
            $this->audit->log($actor, 'contributions.call_created', 'contribution_call', $id, null, $row);
            return $id;
        });
    }

    /** @return array{eligible: int, already: int, to_create: int} sans rien écrire (page de confirmation) */
    public function previewBatch(int $typeId, DateTimeImmutable $periodStart, ?int $actor): array
    {
        $type = $this->callableType($typeId, false);
        $p = $this->period($type, $periodStart);
        [$where, $params] = $this->population($actor);
        $eligible = (int) $this->db->fetchValue("SELECT COUNT(*) FROM members m WHERE $where", $params);
        $already = (int) $this->db->fetchValue("SELECT COUNT(*) FROM members m WHERE $where AND EXISTS (SELECT 1 FROM contribution_calls c
            WHERE c.member_id = m.id AND c.contribution_type_id = ? AND c.period_start = ?)", [...$params, $typeId, $p['start']]);
        return ['eligible' => $eligible, 'already' => $already, 'to_create' => $eligible - $already];
    }

    /** @return array{created: int, skipped: int} $actor null = tâche planifiée (périmètre complet) */
    public function generateBatch(int $typeId, DateTimeImmutable $periodStart, ?int $actor): array
    {
        return $this->db->atomic(function () use ($typeId, $periodStart, $actor): array {
            $type = $this->callableType($typeId, true);
            $p = $this->period($type, $periodStart);
            [$where, $params] = $this->population($actor);
            $eligible = (int) $this->db->fetchValue("SELECT COUNT(*) FROM members m WHERE $where", $params);
            // IGNORE + UNIQUE(member, type, period_start) : idempotent même si deux exécutions se croisent.
            $created = $this->db->query("INSERT IGNORE INTO contribution_calls (member_id, contribution_type_id, period_label, period_start, period_end, amount_due, due_date, status)
                SELECT m.id, ?, ?, ?, ?, ?, ?, 'appelee' FROM members m WHERE $where",
                [$typeId, $p['label'], $p['start'], $p['end'], $type['amount'], $p['end'], ...$params])->rowCount();
            if ($created > 0) {
                $this->audit->log($actor, 'contributions.batch_generated', 'contribution_type', $typeId, null, ['period' => $p['label'], 'created' => $created, 'skipped' => $eligible - $created]);
            }
            return ['created' => $created, 'skipped' => $eligible - $created];
        });
    }

    /**
     * Annulation : motif obligatoire ; refusée si un paiement non annulé existe (il faut d'abord l'annuler) ou si l'appel est déjà soldé/annulé.
     * L'appel reste dans l'historique et occupe toujours sa période (UNIQUE).
     */
    public function cancel(int $callId, string $reason, int $actor): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new DomainException('Le motif de l\'annulation est obligatoire (1 000 caractères au maximum).');
        }
        $this->db->atomic(function () use ($callId, $reason, $actor): void {
            $c = $this->db->fetch('SELECT id, member_id, status FROM contribution_calls WHERE id = ? FOR UPDATE', [$callId]) ?? throw new DomainException('Appel inconnu.');
            $this->scope->assertMayAccess($actor, (int) $c['member_id']);
            if ($c['status'] === 'annulee') {
                throw new DomainException('Cet appel est déjà annulé.');
            }
            if ($this->db->fetchValue('SELECT 1 FROM payments WHERE contribution_call_id = ? AND cancelled_at IS NULL LIMIT 1', [$callId]) !== null) {
                throw new DomainException('Cet appel a reçu un paiement : il ne peut pas être annulé.');
            }
            $this->db->update('contribution_calls', ['status' => 'annulee', 'cancelled_at' => gmdate('Y-m-d H:i:s'), 'cancel_reason' => $reason], ['id' => $callId]);
            $this->audit->log($actor, 'contributions.call_cancelled', 'contribution_call', $callId, ['status' => $c['status']], ['status' => 'annulee', 'reason' => $reason]);
        });
    }

    /** @return array<string,mixed> type actif, montant validé ; périodicité définie pour une cotisation périodique */
    private function callableType(int $typeId, bool $lock): array
    {
        $t = $this->db->fetch('SELECT id, name, kind, amount, periodicity, status FROM contribution_types WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$typeId])
            ?? throw new DomainException('Type de cotisation inconnu.');
        if ($t['status'] !== 'actif' || $t['amount'] === null) {
            throw new DomainException('Ce type n\'est pas actif avec un montant validé : aucun appel possible.');
        }
        if ($t['kind'] === 'periodique' && $t['periodicity'] === null) {
            throw new DomainException('La périodicité de ce type n\'est pas définie : aucun appel possible.');
        }
        return $t;
    }

    /** @return array{label: string, start: string, end: string} */
    private function period(array $type, DateTimeImmutable $date): array
    {
        $p = $type['kind'] === 'periodique' ? Periods::containing((string) $type['periodicity'], $date) : Periods::single($date);
        if ($p['start'] > Ledger::now()->format('Y-m-d')) {
            throw new DomainException('Cette période n\'a pas commencé : aucun appel ne peut être créé pour le futur.');
        }
        return $p;
    }

    private function date(string $v): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone('Africa/Abidjan'));
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new DomainException('Date invalide : choisissez une date.');
        }
        return $d;
    }

    /** @return array{string, list<int>} */
    private function population(?int $actor): array
    {
        [$sql, $params] = $actor === null ? ['m.deleted_at IS NULL', []] : $this->scope->where($actor);
        return ["$sql AND m.status = 'actif'", $params];
    }
}
