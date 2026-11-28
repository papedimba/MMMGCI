<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use DomainException;

/** Règle du compte adhérent non respectée (droit, saisie, état du compte). Message en français, sûr à afficher. */
final class AccountRuleViolation extends DomainException
{
}
