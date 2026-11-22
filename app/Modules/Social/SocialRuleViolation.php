<?php
declare(strict_types=1);

namespace App\Modules\Social;

use DomainException;

/** Règle du circuit social non respectée (droit, saisie, pièce manquante, opération simultanée). Message en français, sûr à afficher. */
final class SocialRuleViolation extends DomainException
{
    public const BUSY = 'Opération simultanée en cours, réessayez dans un instant.';
}
