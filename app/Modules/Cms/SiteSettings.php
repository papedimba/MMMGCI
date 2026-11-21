<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Settings;
use DomainException;

/**
 * Réglages publics du site (groupe « site »). Règles :
 * - saisie par cms.settings.manage : la valeur est enregistrée mais reste « en attente » (non_configure) ;
 * - validation par settings.validate avec référence de décision (Settings::validate) ;
 * - MODIFIER une valeur déjà validée la remet en attente : la table n'a qu'une colonne de valeur, donc le public perd la valeur
 *   jusqu'à nouvelle validation (règle la plus sûre : jamais de valeur non validée en ligne). Enregistrer la même valeur ne change rien ;
 * - le public ne lit que publicValue(), qui renvoie null tant que le réglage n'est pas validé.
 */
final class SiteSettings
{
    private const GROUP = 'site';
    /** clé => [libellé, type, aide] */
    public const KEYS = [
        'contact_email' => ['Adresse e-mail de contact', 'email', 'Reçoit les notifications des formulaires. Vide : les gestionnaires des formulaires sont prévenus.'],
        'contact_phone' => ['Téléphone de contact', 'tel', 'Chiffres, espaces et « + », 20 caractères au maximum.'],
        'address' => ['Adresse postale', 'text', '300 caractères au maximum, en texte simple.'],
        'social_facebook' => ['Page Facebook', 'url', 'Adresse https://… uniquement.'],
        'social_whatsapp' => ['WhatsApp', 'text', 'Lien https://wa.me/… ou numéro avec indicatif (+225…).'],
        'consent_text' => ['Texte de consentement des formulaires', 'textarea', 'Texte fourni par la Mutuelle (4000 caractères au maximum) : il n\'est pas rédigé ici.'],
        'adhesion_open' => ['Adhésion en ligne ouverte', 'bool', 'Décision des instances : oui ou non.'],
        'testimonials_enabled' => ['Témoignages activés', 'bool', 'Décision des instances : oui ou non.'],
        'forms_retention_months' => ['Conservation des formulaires reçus (mois)', 'int', 'Entier de 1 à 120. Aucune purge automatique n\'est faite.'],
    ];

    private Settings $settings;

    public function __construct(private Database $db, private Audit $audit, private Gate $gate)
    {
        $this->settings = new Settings($db);
    }

    /** @return list<array{key:string,label:string,type:string,hint:string,value:mixed,status:string,decision_ref:?string,validated_at:?string,seen:string}> */
    public function all(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT `key`, `value`, status, decision_ref, validated_at FROM settings WHERE `group` = ?', [self::GROUP]) as $r) {
            $rows[$r['key']] = $r;
        }
        $out = [];
        foreach (self::KEYS as $key => [$label, $type, $hint]) {
            $r = $rows[$key] ?? ['value' => null, 'status' => 'non_configure', 'decision_ref' => null, 'validated_at' => null];
            $out[] = ['key' => $key, 'label' => $label, 'type' => $type, 'hint' => $hint,
                'value' => $r['value'] === null ? null : json_decode((string) $r['value'], true), 'status' => $r['status'],
                'decision_ref' => $r['decision_ref'], 'validated_at' => $r['validated_at'], 'seen' => md5((string) $r['value'])];
        }
        return $out;
    }

    /** Valeur à utiliser publiquement : null tant que le réglage n'est pas validé (ou vide). */
    public function publicValue(string $key): mixed
    {
        if (!isset(self::KEYS[$key]) || !$this->settings->isValidated(self::GROUP, $key)) {
            return null;
        }
        return $this->settings->get(self::GROUP, $key);
    }

    public function save(string $key, string $raw, int $actor): void
    {
        $this->assertKnown($key);
        if (!$this->gate->allows($actor, 'cms.settings.manage')) {
            throw new DomainException('Modifier les réglages du site demande le droit « réglages du site ».');
        }
        $value = $this->normalize($key, $raw);
        $json = $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->db->atomic(function () use ($key, $json, $actor): void {
            $old = $this->db->fetch('SELECT `value`, status FROM settings WHERE `group` = ? AND `key` = ? FOR UPDATE', [self::GROUP, $key]) ?? throw new NotFound('Réglage introuvable.');
            if ($old['value'] === $json && ($json !== null || $old['status'] === 'non_configure')) {
                return;
            }
            $this->db->query('UPDATE settings SET `value` = ?, status = ?, validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = ? AND `key` = ?',
                [$json, 'non_configure', self::GROUP, $key]);
            $this->audit->log($actor, 'cms.setting_saved', 'settings', null, ['key' => $key, 'status' => $old['status']], ['key' => $key, 'status' => 'non_configure']);
        });
    }

    /** @param string $seen empreinte de la valeur affichée : refus si elle a changé depuis */
    public function validate(string $key, int $actor, string $decisionRef, string $seen): void
    {
        $this->assertKnown($key);
        if (!$this->gate->allows($actor, 'settings.validate')) {
            throw new DomainException('Valider un réglage demande le droit « valider les paramètres ».');
        }
        $this->db->atomic(function () use ($key, $actor, $decisionRef, $seen): void {
            $row = $this->db->fetch('SELECT `value` FROM settings WHERE `group` = ? AND `key` = ? FOR UPDATE', [self::GROUP, $key]) ?? throw new NotFound('Réglage introuvable.');
            if ($row['value'] === null) {
                throw new DomainException('Ce réglage est vide : saisissez d\'abord une valeur.');
            }
            if (!hash_equals(md5((string) $row['value']), $seen)) {
                throw new DomainException('La valeur a changé depuis l\'affichage : relisez-la avant de la valider.');
            }
            $ref = trim($decisionRef);
            if ($ref === '' || mb_strlen($ref) > 100) {
                throw new DomainException('La référence de la décision est obligatoire (100 caractères au maximum).');
            }
            $this->settings->validate(self::GROUP, $key, json_decode((string) $row['value'], true), $actor, $ref);
            $this->audit->log($actor, 'cms.setting_validated', 'settings', null, null, ['key' => $key, 'status' => 'valide', 'decision_ref' => $ref]);
        });
    }

    private function assertKnown(string $key): void
    {
        if (!isset(self::KEYS[$key])) {
            throw new NotFound('Réglage introuvable.');
        }
    }

    /** @return string|int|bool|null null = valeur vidée */
    private function normalize(string $key, string $raw): string|int|bool|null
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        $fail = static fn (string $m): never => throw new PageInvalid([$key => $m]);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) === 1 || ($key !== 'consent_text' && preg_match('/[\r\n]/', $v) === 1)) {
            $fail('Caractères non autorisés (retours à la ligne ou caractères de contrôle).');
        }
        switch ($key) {
            case 'contact_email':
                return mb_strlen($v) <= 150 && filter_var($v, FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower($v) : $fail('Adresse e-mail invalide (150 caractères au maximum).');
            case 'contact_phone':
                return preg_match('/^\+?[0-9 ]{6,19}$/', $v) === 1 && strlen(preg_replace('/\D/', '', $v)) >= 6 ? $v : $fail('Téléphone invalide : chiffres, espaces et « + », 20 caractères au maximum.');
            case 'address':
                return mb_strlen($v) <= 300 && !preg_match('/[<>]/', $v) ? $v : $fail('Adresse invalide : 300 caractères au maximum, sans < ni >.');
            case 'social_facebook':
                $p = parse_url($v);
                return mb_strlen($v) <= 255 && filter_var($v, FILTER_VALIDATE_URL) !== false && ($p['scheme'] ?? '') === 'https' && ($p['host'] ?? '') !== '' && !preg_match('/[<>"\'\s]/', $v)
                    ? $v : $fail('Adresse invalide : https://… uniquement.');
            case 'social_whatsapp':
                return preg_match('#^https://wa\.me/\d{6,15}(\?[^\s<>"\']{0,100})?$#', $v) === 1 || preg_match('/^\+?\d{6,15}$/', $v) === 1
                    ? $v : $fail('WhatsApp : lien https://wa.me/… ou numéro avec indicatif (chiffres et « + »).');
            case 'consent_text':
                return mb_strlen($v) <= 4000 && !preg_match('/[<>]/', $v) ? $v : $fail('Texte invalide : 4000 caractères au maximum, sans < ni >.');
            case 'adhesion_open':
            case 'testimonials_enabled':
                return $v === 'true' ? true : ($v === 'false' ? false : $fail('Choisissez oui ou non.'));
            default: // forms_retention_months
                return ctype_digit($v) && (int) $v >= 1 && (int) $v <= 120 ? (int) $v : $fail('Entier de 1 à 120.');
        }
    }
}
