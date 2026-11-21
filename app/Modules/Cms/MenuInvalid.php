<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/** Menu refusé : $errors associe « items[i].champ » (ou « menu ») à son message. */
final class MenuInvalid extends DomainException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
