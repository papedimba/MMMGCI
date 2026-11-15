<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use DomainException;

/** Interblocage ou attente de verrou trop longue : rien n'est écrit, l'utilisateur réessaie. Message en français, sûr à afficher. */
final class DisbursementBusy extends DomainException
{
    public const MESSAGE = 'Opération simultanée en cours, réessayez dans un instant.';
}
