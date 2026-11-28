<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/** Formulaire de page refusé : $errors associe chaque champ (ou chemin de bloc « blocs[2].items[1].label ») à son message. */
final class PageInvalid extends DomainException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
