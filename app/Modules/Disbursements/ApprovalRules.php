<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use App\Core\Database;
use App\Core\Money;

/**
 * Nombre d'approbations requis par tranche de montant (approval_rules, VAL V14). Bornes incluses (min <= montant <= max, max NULL = sans plafond).
 * Tranches qui se chevauchent : on retient la PLUS EXIGEANTE (MAX), indépendamment de l'ordre des lignes ; aucune tranche : 2.
 * Jamais moins de 2, même si une ligne le dit (le CHECK de la base l'interdit déjà : double filet).
 */
final class ApprovalRules
{
    public const MINIMUM = 2;

    public function __construct(private Database $db)
    {
    }

    public function requiredFor(int $amountCents): int
    {
        $amount = Money::toDecimal($amountCents);
        $max = $this->db->fetchValue('SELECT MAX(approvals_required) FROM approval_rules WHERE min_amount <= ? AND (max_amount IS NULL OR max_amount >= ?)', [$amount, $amount]);
        return max(self::MINIMUM, (int) $max);
    }
}
