<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use DomainException;

/** Passage d'état non prévu par le circuit A5.3. Message en français, sûr à afficher. */
final class DisbursementTransitionInvalid extends DomainException
{
}
