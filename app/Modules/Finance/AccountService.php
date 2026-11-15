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

    public function __construct(private Database $db, private Audit $audit)
    {
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
        return $this->db->atomic(function () use ($name, $type, $opening, $actor): int {
            try {
                $id = $this->db->insert('financial_accounts', ['name' => $name, 'type' => $type, 'opening_balance' => Money::toDecimal($opening)]);
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Un compte porte déjà ce nom.', 0, $e) : $e;
            }
            $this->audit->log($actor, 'finance.account_created', 'financial_account', $id, null, ['name' => $name, 'type' => $type, 'opening_balance' => Money::toDecimal($opening)]);
            return $id;
        });
    }

    public function setActive(int $id, bool $active, int $actor): void
    {
        $this->db->atomic(function () use ($id, $active, $actor): void {
            $row = $this->db->fetch('SELECT is_active FROM financial_accounts WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException('Compte inconnu.');
            if ((int) $row['is_active'] === (int) $active) {
                return;
            }
            $this->db->update('financial_accounts', ['is_active' => (int) $active], ['id' => $id]);
            $this->audit->log($actor, $active ? 'finance.account_activated' : 'finance.account_deactivated', 'financial_account', $id, ['is_active' => (int) !$active], ['is_active' => (int) $active]);
        });
    }
}
