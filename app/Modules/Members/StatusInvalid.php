<?php
declare(strict_types=1);

namespace App\Modules\Members;

use DomainException;

/** Changement de statut refusé : erreurs par champ (to, reason, decision_ref). */
final class StatusInvalid extends DomainException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
