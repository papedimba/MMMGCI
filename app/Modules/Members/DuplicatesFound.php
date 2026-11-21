<?php
declare(strict_types=1);

namespace App\Modules\Members;

use RuntimeException;

/** Lancée dans la transaction de création pour l'annuler quand des homonymes existent et que la création n'est pas confirmée. */
final class DuplicatesFound extends RuntimeException
{
    /** @param array{visible: list<array<string,mixed>>, hidden_count: int} $found */
    public function __construct(public readonly array $found)
    {
        parent::__construct('Homonymes détectés.');
    }
}
