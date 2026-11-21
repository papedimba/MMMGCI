<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/** Envoi de formulaire public refusé pour des champs invalides : $errors associe chaque champ à son message. */
final class FormInvalid extends DomainException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
