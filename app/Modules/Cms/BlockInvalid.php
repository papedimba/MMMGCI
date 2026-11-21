<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/** Bloc refusé ; $path situe l'erreur (ex. « blocs[2].items[1].label »). */
final class BlockInvalid extends DomainException
{
    /** @param array<string,string> $errors toutes les erreurs relevées (chemin => message) ; à défaut, la seule $path */
    public readonly array $errors;

    public function __construct(public readonly string $path, string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors !== [] ? $errors : [$path => $message];
    }
}
