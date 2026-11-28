<?php
declare(strict_types=1);

namespace App\Modules\Social;

use DomainException;

/** Lecture refusée (le contrôleur répond 403) ; le refus est déjà journalisé par SocialAccess::deny. Message sûr à afficher. */
final class SocialAccessDenied extends DomainException
{
}
