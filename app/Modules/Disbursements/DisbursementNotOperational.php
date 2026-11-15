<?php
declare(strict_types=1);

namespace App\Modules\Disbursements;

use DomainException;

/** Circuit inopérant : moins de deux approbateurs actifs distincts. Message en français, sûr à afficher. */
final class DisbursementNotOperational extends DomainException
{
}
