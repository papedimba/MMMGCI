<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Refus d'un fichier téléversé ; le message est destiné à l'utilisateur. */
final class UploadError extends RuntimeException
{
}
