<?php
declare(strict_types=1);

namespace App\Modules\Finance;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Money;
use DomainException;
use PDOException;

/**
 * Comptes financiers. Le solde d'ouverture est fixé à la création et jamais modifié ensuite (ce n'est pas une écriture) :
 * toute correction passe par le grand livre.
 */
final class AccountService
{
    public const TYPES = ['general' => 'Compte général', 'solidarity_fund' => 'Fonds de solidarité', 'bank' => 'Compte bancaire', 'cash' => 'Caisse', 'mobile_money' => 'Mobile money'];

    public function __construct(private Database $db, private Audit $audit, private int $lockWaitSeconds = 5)
    {
    }

    /**
     * Un seul fonds de solidarité actif. Verrou nommé (GET_LOCK, comme FirstAdmin) pris AVANT la transaction : le contrôle et l'écriture
     * sont ainsi sérialisés entre connexions, et, quand l'appelant n'a pas de transaction ouverte, l'instantané est postérieur au dernier commit d'un concurrent. Filet en base : index UNIQUE (migration 0015).
     */
    private function withFundLock(callable $fn): mixed
    {
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(?, ?)', [$this->lockName(), $this->lockWaitSeconds]) !== 1) {
            throw new DomainException('Le fonds de solidarité est en cours de modification par un autre utilisateur : réessayez dans un instant.');
        }
        try {
            return $fn();
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [$this->lockName()]);
        }
    }

    /** Les verrous nommés sont propres au serveur : préfixe = base courante (la base de test ne partage pas celui de la base de dev). */
    public function lockName(): string
    {
        return 'mmmgci.solidarity-fund.' . $this->db->fetchValue('SELECT DATABASE()');
    }

    private function assertNoActiveFund(): void
    {
        if ($this->db->fetchValue("SELECT 1 FROM financial_accounts WHERE type = 'solidarity_fund' AND is_active = 1 LIMIT 1") !== null) {
            throw new DomainException('Un compte de fonds de solidarité actif existe déjà : il ne peut y en avoir qu\'un seul.');
        }
    }

    public function create(string $name, string $type, string $openingInput, int $actor): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Nom du compte : obligatoire, 120 caractères au maximum.');
        }
        if (!isset(self::TYPES[$type])) {
            throw new DomainException('Type de compte invalide.');
        }
        try {
            $opening = trim($openingInput) === '' ? 0 : Money::parse($openingInput);
        } catch (\InvalidArgumentException $e) {
            throw new DomainException('Solde d\'ouverture : ' . $e->getMessage());
        }
        $run = fn (): int => $this->db->atomic(function () use ($name, $type, $opening, $actor): int {
            if ($type === 'solidarity_fund') {
                $this->assertNoActiveFund();
            }
            try {
                $id = $this->db->insert('financial_accounts', ['name' => $name, 'type' => $type, 'opening_balance' => Money::toDecimal($opening)]);
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Un compte porte déjà ce nom.', 0, $e) : $e;
            }
            $this->audit->log($actor, 'finance.account_created', 'financial_account', $id, null, ['name' => $name, 'type' => $type, 'opening_balance' => Money::toDecimal($opening)]);
            return $id;
        });
        return $type === 'solidarity_fund' ? $this->withFundLock($run) : $run();
    }

    public function setActive(int $id, bool $active, int $actor): void
    {
        $run = fn () => $this->db->atomic(function () use ($id, $active, $actor): void {
            $row = $this->db->fetch('SELECT type, is_active FROM financial_accounts WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException('Compte inconnu.');
            if ((int) $row['is_active'] === (int) $active) {
                return;
            }
            if ($active && $row['type'] === 'solidarity_fund') {
                $this->assertNoActiveFund();
            }
            $this->db->update('financial_accounts', ['is_active' => (int) $active], ['id' => $id]);
            $this->audit->log($actor, $active ? 'finance.account_activated' : 'finance.account_deactivated', 'financial_account', $id, ['is_active' => (int) !$active], ['is_active' => (int) $active]);
        });
        $type = $this->db->fetchValue('SELECT type FROM financial_accounts WHERE id = ?', [$id]);
        $type === 'solidarity_fund' && $active ? $this->withFundLock($run) : $run();
    }
}
