<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use DomainException;

/** Règle du circuit de décaissement non respectée. Message en français, sûr à afficher. */
final class DisbursementRuleViolation extends DomainException
{
}
