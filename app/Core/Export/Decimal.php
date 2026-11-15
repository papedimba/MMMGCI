<?php
declare(strict_types=1);

namespace App\Core\Export;

use InvalidArgumentException;

/** Nombre décimal exact (chaîne DECIMAL de la base) à écrire tel quel dans une cellule numérique XLSX, sans passer par un flottant. */
final class Decimal
{
    public function __construct(public readonly string $value)
    {
        if (!preg_match('/^-?\d{1,20}(?:\.\d{1,6})?$/D', $value)) {
            throw new InvalidArgumentException('Décimal invalide pour l\'export.');
        }
    }
}
