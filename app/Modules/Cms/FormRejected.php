<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use DomainException;

/** Envoi de formulaire public refusé sans détail (champ piège, trop d'envois, formulaire inconnu) : le message est affichable tel quel. */
final class FormRejected extends DomainException
{
}
