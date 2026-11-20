<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/** Bloc refusé ; $path situe l'erreur (ex. « blocs[2].items[1].label »). */
final class BlockInvalid extends DomainException
{
    public function __construct(public readonly string $path, string $message)
    {
        parent::__construct($message);
    }
}
