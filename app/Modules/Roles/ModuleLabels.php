<?php
declare(strict_types=1);

namespace App\Modules\Roles;

/** Noms français des modules de permissions (préfixe des permissions) ; un module inconnu garde son code. */
final class ModuleLabels
{
    private const LABELS = [
        'admin' => 'Administration', 'cms' => 'Site web', 'members' => 'Adhérents', 'contributions' => 'Cotisations',
        'finance' => 'Finances', 'solidarity' => 'Fonds de solidarité', 'social' => "Demandes d'assistance",
        'employment' => 'Emploi et formation', 'partners' => 'Partenariats', 'governance' => 'Gouvernance',
        'delegates' => 'Délégués et points focaux', 'discipline' => 'Discipline', 'reports' => 'Rapports',
    ];

    public static function label(string $module): string
    {
        return self::LABELS[$module] ?? $module;
    }
}
