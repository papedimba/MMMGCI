<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Settings;
use DomainException;

/**
 * Paramètres de l'Espace adhérent (groupe « espace ») : validés par settings.validate avec la référence de la décision.
 * Tant qu'un paramètre n'est pas validé, son défaut technique (0029) s'applique et l'écran le dit (« non configuré »).
 */
final class EspaceSettingsService
{
    private const GROUP = 'espace';
    /** Statuts qu'un niveau doit toujours couvrir : sinon radié et décédé retomberaient en consultation (MemberAccess). */
    public const REQUIRED_STATUSES = ['actif', 'suspendu', 'radie', 'decede'];
    public const LEVEL_LABELS = ['complet' => 'Accès complet', 'lecture' => 'Consultation seule', 'desactive' => 'Aucun accès'];
    /** clé => [libellé, type, aide] */
    public const KEYS = [
        'status_access' => ["Niveau d'accès selon le statut du membre", 'map', 'Un niveau pour chaque statut : accès complet, consultation seule ou aucun accès.'],
        'activation_code_days' => ["Validité du code d'activation (jours)", 'int', 'Entier de 1 à 90 (borne technique).'],
        'depot_demande_en_ligne' => ["Dépôt de demandes d'assistance en ligne", 'bool', 'Oui ou non. Tant que ce paramètre n\'est pas validé à « oui », le dépôt en ligne reste fermé.'],
    ];

    private Settings $settings;

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
        $this->settings = new Settings($db);
    }

    /** @return array<string,string> code => libellé des statuts à couvrir (statuts actifs et les quatre obligatoires) */
    public function statuses(): array
    {
        $rows = array_column($this->db->fetchAll('SELECT code, label, is_active FROM member_statuses ORDER BY label'), null, 'code');
        $out = [];
        foreach ($rows as $code => $r) {
            if ((int) $r['is_active'] === 1 || in_array($code, self::REQUIRED_STATUSES, true)) {
                $out[$code] = (string) $r['label'];
            }
        }
        foreach (self::REQUIRED_STATUSES as $code) {
            $out[$code] ??= $code;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> value : valeur appliquée (validée, sinon défaut technique stocké, null si aucune) */
    public function all(): array
    {
        $rows = array_column($this->db->fetchAll('SELECT `key`, `value`, status, decision_ref FROM settings WHERE `group` = ?', [self::GROUP]), null, 'key');
        $out = [];
        foreach (self::KEYS as $key => [$label, $type, $hint]) {
            $r = $rows[$key] ?? ['value' => null, 'status' => 'non_configure', 'decision_ref' => null];
            try {
                $value = $r['value'] === null ? null : json_decode((string) $r['value'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $value = null;
            }
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'hint' => $hint, 'validated' => $r['status'] === 'valide' && $r['value'] !== null,
                'value' => $value, 'decision_ref' => $r['status'] === 'valide' ? $r['decision_ref'] : null];
        }
        return $out;
    }

    public function validate(string $key, string $raw, string $decisionRef, int $actor): void
    {
        $type = (self::KEYS[$key] ?? throw new NotFound('Paramètre introuvable.'))[1];
        if (!$this->gate->allows($actor, 'settings.validate')) {
            throw new DomainException('Valider un paramètre demande le droit « valider les paramètres ».');
        }
        $ref = trim($decisionRef);
        if ($ref === '' || mb_strlen($ref) > 100) {
            throw new DomainException('La référence de la décision est obligatoire (100 caractères au maximum).');
        }
        $raw = trim($raw);
        $value = match ($type) {
            'int' => preg_match('/^\d{1,2}$/D', $raw) === 1 && (int) $raw >= 1 && (int) $raw <= 90 ? (int) $raw : throw new DomainException('Saisissez un nombre entier de jours, de 1 à 90.'),
            'bool' => match ($raw) { 'oui' => true, 'non' => false, default => throw new DomainException('Choisissez « oui » ou « non ».') },
            default => $this->statusMap($raw),
        };
        $this->db->atomic(function () use ($key, $value, $ref, $actor): void {
            $this->db->fetchValue('SELECT 1 FROM settings WHERE `group` = ? AND `key` = ? FOR UPDATE', [self::GROUP, $key]) ?? throw new NotFound('Paramètre introuvable.');
            $this->settings->validate(self::GROUP, $key, $value, $actor, $ref);
            $this->audit->log($actor, 'espace.setting_validated', 'settings', null, null, ['key' => $key, 'decision_ref' => $ref]);
        });
    }

    /** Objet JSON statut => niveau couvrant exactement les statuts à couvrir (aucun manquant, aucun inconnu). @return array<string,string> */
    private function statusMap(string $raw): array
    {
        try {
            $map = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $map = null;
        }
        $statuses = $this->statuses();
        if (!is_array($map) || array_is_list($map)) {
            throw new DomainException('Choisissez un niveau d\'accès pour chaque statut.');
        }
        $missing = array_diff_key($statuses, $map);
        if ($missing !== []) {
            throw new DomainException('Choisissez un niveau d\'accès pour chaque statut (manquant : ' . implode(', ', $missing) . ').');
        }
        if (array_diff_key($map, $statuses) !== []) {
            throw new DomainException('Statut inconnu : rechargez la page et choisissez un niveau pour chaque statut affiché.');
        }
        $out = [];
        foreach ($statuses as $code => $label) {
            if (!is_string($map[$code]) || !isset(self::LEVEL_LABELS[$map[$code]])) {
                throw new DomainException("Niveau inconnu pour le statut « $label » : choisissez accès complet, consultation seule ou aucun accès.");
            }
            $out[$code] = $map[$code];
        }
        return $out;
    }
}
