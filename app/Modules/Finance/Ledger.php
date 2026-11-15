<?php
declare(strict_types=1);

namespace App\Modules\Finance;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Money;
use App\Core\Sequence;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PDOException;

/**
 * Grand livre immuable : on ajoute des écritures, on n'en modifie ni n'en supprime jamais (aucune méthode update/delete) ;
 * une erreur se corrige par contre-passation. Montants en centimes entiers (représentation de Money), jamais en flottant.
 */
final class Ledger
{
    public const DIRECTIONS = ['recette', 'depense'];
    private const MAX_CENTS = 999_999_999_999_999; // DECIMAL(15,2)
    /** Catégorie semée (art. 13) dont toute écriture exige une référence d'autorisation. */
    public const EXCEPTIONAL = 'Contributions exceptionnelles';
    private const REASON_MAX = 300;

    public function __construct(private Database $db, private Audit $audit, private Sequence $sequence)
    {
    }

    /**
     * Enregistre une écriture. Aucune route ne peut appeler ceci avec 'depense' : le seul appelant légitime d'une dépense
     * sera DisbursementService (Task 6), qui l'appelle dans sa propre transaction (verrous : demande, compte, séquences).
     *
     * @param array{source_type?: ?string, source_id?: ?int, description?: ?string, authorization_ref?: ?string, donor_partner_id?: ?int} $meta
     */
    public function record(int $accountId, int $categoryId, string $direction, int $amountCents, DateTimeImmutable $occurredAt, array $meta, int $actor): int
    {
        return $this->db->atomic(function () use ($accountId, $categoryId, $direction, $amountCents, $occurredAt, $meta, $actor): int {
            $account = $this->lockAccount($accountId);
            if ((int) $account['is_active'] !== 1) {
                throw new DomainException('Ce compte est désactivé : aucune écriture ne peut y être enregistrée.');
            }
            $category = $this->db->fetch('SELECT name, direction, is_active FROM financial_categories WHERE id = ?', [$categoryId])
                ?? throw new DomainException('Catégorie inconnue.');
            if ((int) $category['is_active'] !== 1) {
                throw new DomainException('Cette catégorie est désactivée.');
            }
            if ($category['name'] === self::EXCEPTIONAL && trim((string) ($meta['authorization_ref'] ?? '')) === '') {
                throw new DomainException('Une contribution exceptionnelle exige la référence de son autorisation.');
            }
            if ($category['direction'] !== $direction) {
                throw new DomainException('La catégorie choisie ne correspond pas au sens de l\'écriture.');
            }
            return $this->insert($accountId, $categoryId, $direction, $amountCents, $occurredAt, $meta, null, $actor);
        });
    }

    /** Écriture de sens opposé, même montant, même compte et catégorie ; refusée si la cible est déjà contre-passée ou est elle-même une contre-passation. */
    public function reverse(int $transactionId, string $reason, int $actor): int
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Le motif de la contre-passation est obligatoire.');
        }
        if (mb_strlen($reason) > self::REASON_MAX) {
            throw new DomainException('Motif : ' . self::REASON_MAX . ' caractères au maximum.');
        }
        return $this->db->atomic(function () use ($transactionId, $reason, $actor): int {
            $probe = $this->db->fetch('SELECT account_id FROM financial_transactions WHERE id = ?', [$transactionId])
                ?? throw new DomainException('Écriture introuvable.');
            $this->lockAccount((int) $probe['account_id']); // compte avant séquences
            $t = $this->db->fetch('SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE', [$transactionId]);
            if ($t['reversal_of_id'] !== null) {
                throw new DomainException('Cette écriture est elle-même une contre-passation : elle ne peut pas être contre-passée.');
            }
            if ($this->db->fetchValue('SELECT 1 FROM financial_transactions WHERE reversal_of_id = ?', [$transactionId]) !== null) {
                throw new DomainException('Cette écriture a déjà été contre-passée.');
            }
            $meta = ['source_type' => $t['source_type'], 'source_id' => $t['source_id'], 'donor_partner_id' => $t['donor_partner_id'],
                'description' => mb_substr('Contre-passation de ' . $t['reference'] . ' : ' . $reason, 0, 500)];
            try {
                $id = $this->insert((int) $t['account_id'], (int) $t['category_id'], $t['direction'] === 'recette' ? 'depense' : 'recette',
                    Money::fromDecimal($t['amount']), self::now(), $meta, $transactionId, $actor);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) { // UNIQUE(reversal_of_id) : filet de la base
                    throw new DomainException('Cette écriture a déjà été contre-passée.', 0, $e);
                }
                throw $e;
            }
            $this->audit->log($actor, 'finance.transaction_reversed', 'financial_transaction', $transactionId, null, ['reversal_id' => $id, 'reason' => $reason]);
            return $id;
        });
    }

    /**
     * Solde = ouverture + Σ recettes − Σ dépenses, calculé en SQL (DECIMAL). $at est un jour : inclus jusqu'à 23:59:59 ;
     * Africa/Abidjan est à UTC+0 sans changement d'heure, donc les dates stockées (UTC) sont déjà celles d'Abidjan.
     */
    public function balance(int $accountId, ?DateTimeImmutable $at = null): int
    {
        $row = $this->db->fetch(
            "SELECT a.opening_balance + COALESCE(SUM(CASE t.direction WHEN 'recette' THEN t.amount ELSE -t.amount END), 0) AS balance
             FROM financial_accounts a
             LEFT JOIN financial_transactions t ON t.account_id = a.id" . ($at === null ? '' : ' AND t.occurred_at <= ?') . '
             WHERE a.id = ? GROUP BY a.id', $at === null ? [$accountId] : [$at->format('Y-m-d') . ' 23:59:59', $accountId]);
        if ($row === null) {
            throw new DomainException('Compte inconnu.');
        }
        $dec = (string) $row['balance'];
        return $dec[0] === '-' ? -Money::fromDecimal(substr($dec, 1)) : Money::fromDecimal($dec);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'));
    }

    /** @return array<string,mixed> */
    private function lockAccount(int $accountId): array
    {
        return $this->db->fetch('SELECT id, is_active FROM financial_accounts WHERE id = ? FOR UPDATE', [$accountId])
            ?? throw new DomainException('Compte inconnu.');
    }

    /** @param array<string,mixed> $meta */
    private function insert(int $accountId, int $categoryId, string $direction, int $cents, DateTimeImmutable $at, array $meta, ?int $reversalOf, int $actor): int
    {
        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new DomainException('Sens d\'écriture invalide.');
        }
        if ($cents <= 0 || $cents > self::MAX_CENTS) {
            throw new DomainException('Le montant doit être strictement positif.');
        }
        $description = isset($meta['description']) ? trim((string) $meta['description']) : null;
        $authRef = isset($meta['authorization_ref']) ? trim((string) $meta['authorization_ref']) : null;
        if (($description !== null && mb_strlen($description) > 500) || ($authRef !== null && mb_strlen($authRef) > 100)) {
            throw new DomainException('Description (500 caractères) ou référence d\'autorisation (100 caractères) trop longue.');
        }
        $reference = $this->sequence->next('financial_transaction', 'TX', 6); // format technique TX-AAAA-NNNNNN
        $id = $this->db->insert('financial_transactions', [
            'reference' => $reference, 'account_id' => $accountId, 'category_id' => $categoryId, 'direction' => $direction,
            'amount' => Money::toDecimal($cents), 'occurred_at' => $at->setTimezone(new DateTimeZone('Africa/Abidjan'))->format('Y-m-d H:i:s'),
            'source_type' => $meta['source_type'] ?? null, 'source_id' => $meta['source_id'] ?? null,
            'donor_partner_id' => $meta['donor_partner_id'] ?? null, 'description' => $description === '' ? null : $description,
            'authorization_ref' => $authRef === '' ? null : $authRef, 'reversal_of_id' => $reversalOf, 'recorded_by' => $actor,
        ]);
        $this->audit->log($actor, 'finance.transaction_recorded', 'financial_transaction', $id, null,
            ['reference' => $reference, 'account_id' => $accountId, 'direction' => $direction, 'amount' => Money::toDecimal($cents), 'reversal_of_id' => $reversalOf], $reference);
        return $id;
    }
}
