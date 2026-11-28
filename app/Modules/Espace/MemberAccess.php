<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Settings;

/**
 * Seule source de la règle « niveau d'accès à l'Espace selon le statut du membre » (paramètre espace.status_access).
 * Non validé par les instances : le défaut technique (proposition A8) s'applique quand même, avec un bandeau (pending()).
 */
final class MemberAccess
{
    public const LEVELS = ['complet', 'lecture', 'desactive'];

    public function __construct(private Settings $settings)
    {
    }

    /** @return 'complet'|'lecture'|'desactive' statut absent de la table : lecture ; table ou valeur illisible : desactive (le plus sûr) */
    public function level(string $memberStatus): string
    {
        try {
            $map = $this->settings->get('espace', 'status_access');
        } catch (\JsonException|\InvalidArgumentException) {
            return 'desactive';
        }
        if (!is_array($map) || array_is_list($map) && $map !== []) {
            return 'desactive';
        }
        if (!array_key_exists($memberStatus, $map)) {
            return 'lecture';
        }
        $level = $map[$memberStatus];
        return is_string($level) && in_array($level, self::LEVELS, true) ? $level : 'desactive';
    }

    /** Vrai tant que les instances n'ont pas validé la règle (bandeau « paramètre en attente de validation »). */
    public function pending(): bool
    {
        try {
            return !$this->settings->isValidated('espace', 'status_access');
        } catch (\InvalidArgumentException) {
            return true;
        }
    }
}
