<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Settings;
use DomainException;

/** Réglages généraux du module (groupe « social ») : aucune valeur par défaut inventée, validation par settings.validate avec référence de décision. */
final class SocialSettingsService
{
    private const GROUP = 'social';
    /** clé => [libellé, type, aide] */
    public const KEYS = [
        'reference_format' => ['Format de la référence des demandes', 'format', 'Format technique à valider ; doit contenir {NNNN} (numéro), éventuellement {YYYY} (année).'],
        'processing_deadline_days' => ['Délai de traitement (jours)', 'int', 'Entier strictement positif. Aucun délai n\'est appliqué tant qu\'il n\'est pas validé.'],
        'retention_months' => ['Durée de conservation (mois)', 'int', 'Entier strictement positif. Aucune purge n\'est faite tant qu\'elle n\'est pas validée.'],
        'eligibility_rule' => ['Règle d\'éligibilité', 'text', 'Texte adopté par les instances (2000 caractères au maximum). Aucun contrôle automatique n\'en est tiré.'],
    ];

    private Settings $settings;

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
        $this->settings = new Settings($db);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT `key`, `value`, status, decision_ref FROM settings WHERE `group` = ?', [self::GROUP]) as $r) {
            $rows[$r['key']] = $r;
        }
        $out = [];
        foreach (self::KEYS as $key => [$label, $type, $hint]) {
            $r = $rows[$key] ?? ['value' => null, 'status' => 'non_configure', 'decision_ref' => null];
            $valid = $r['status'] === 'valide' && $r['value'] !== null;
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'hint' => $hint, 'validated' => $valid,
                'value' => $valid ? json_decode((string) $r['value'], true) : null, 'decision_ref' => $valid ? $r['decision_ref'] : null];
        }
        return $out;
    }

    public function validate(string $key, string $raw, string $decisionRef, int $actor): void
    {
        $type = (self::KEYS[$key] ?? throw new NotFound('Réglage introuvable.'))[1];
        if (!$this->gate->allows($actor, 'settings.validate')) {
            throw new DomainException('Valider un réglage demande le droit « valider les paramètres ».');
        }
        $ref = trim($decisionRef);
        if ($ref === '' || mb_strlen($ref) > 100) {
            throw new DomainException('La référence de la décision est obligatoire (100 caractères au maximum).');
        }
        $raw = trim($raw);
        $value = match ($type) {
            'int' => ctype_digit($raw) && (int) $raw > 0 && strlen($raw) <= 5 ? (int) $raw : throw new DomainException('Saisissez un entier strictement positif (5 chiffres au maximum).'),
            'format' => preg_match('/^(?=.*\{NNNN\})[A-Za-z0-9\-_\/{}]{4,40}$/', $raw) === 1 && preg_replace('/\{(NNNN|YYYY)\}/', '', $raw) !== null && preg_match('/[{}]/', preg_replace('/\{(NNNN|YYYY)\}/', '', $raw)) !== 1
                ? $raw : throw new DomainException('Format invalide : lettres, chiffres, « - _ / » et les jetons {NNNN} (obligatoire) et {YYYY}, 40 caractères au maximum.'),
            default => $raw !== '' && mb_strlen($raw) <= 2000 ? $raw : throw new DomainException('Saisissez le texte adopté (2000 caractères au maximum).'),
        };
        $this->db->atomic(function () use ($key, $value, $ref, $actor): void {
            $this->db->fetchValue('SELECT 1 FROM settings WHERE `group` = ? AND `key` = ? FOR UPDATE', [self::GROUP, $key]) ?? throw new NotFound('Réglage introuvable.');
            $this->settings->validate(self::GROUP, $key, $value, $actor, $ref);
            $this->audit->log($actor, 'social.setting_validated', 'settings', null, null, ['key' => $key, 'decision_ref' => $ref]);
        });
    }
}
