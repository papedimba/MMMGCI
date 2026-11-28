<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Ressource inconnue ou hors du périmètre de l'utilisateur : le contrôleur répond 404 dans les deux cas. */
final class NotFound extends RuntimeException
{
}
