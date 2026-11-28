<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\IpKey;
use App\Core\NotFound;
use App\Core\Settings;
use App\Modules\Auth\Auth;
use App\Modules\Auth\LinkMailer;
use App\Modules\Auth\LoginThrottle;
use App\Modules\Auth\PasswordPolicy;
use App\Modules\Members\MemberScope;
use Closure;
use Throwable;

/**
 * Cycle de vie du compte adhérent : ouverture, codes d'activation (HMAC seul en base, usage unique, expirants), activation,
 * mot de passe oublié, réinitialisation par un gestionnaire, désactivation et changement du mot de passe.
 * Verrous toujours dans l'ordre membre, compte, codes. L'audit ne porte ni code, ni jeton, ni identifiant saisi, ni donnée personnelle.
 */
final class MemberAccountService
{
    public const FAILED = 'Ces informations ne permettent pas d\'activer un compte : vérifiez-les ou demandez un nouveau code.';
    public const BLOCKED = 'Trop de tentatives : réessayez dans 15 minutes.';
    public const INVALID_LINK = "Ce lien n'est plus valable : demandez un nouveau lien de réinitialisation.";
    public const MISMATCH = 'Les deux mots de passe saisis sont différents : saisissez le même nouveau mot de passe dans les deux champs.';
    public const WRONG_CURRENT = 'Mot de passe actuel incorrect : saisissez le mot de passe que vous utilisez pour vous connecter.';
    public const NO_BIRTH_DATE = "Renseignez la date de naissance dans la fiche avant d'ouvrir l'accès.";
    private const DENIED = "Vous n'avez pas le droit d'effectuer cette opération sur les accès à l'Espace adhérent.";
    private const DEFAULT_DAYS = 14; // défaut technique (proposition A8), appliqué tant que le paramètre est illisible
    private const FORGOT_TTL = 3600;
    private const FORGOT_PER_HOUR = 3;

    private MemberAccess $access;

    /**
     * @param Closure(callable): void $defer travail exécuté après l'envoi de la réponse
     * @param Closure(): int $clock
     */
    public function __construct(private Database $db, private Audit $audit, private Gate $gate, private MemberScope $scope,
        private MemberAuth $auth, private Settings $settings, private LoginThrottle $throttle, private LinkMailer $links,
        private Closure $defer, private string $appKey, private Closure $clock)
    {
        $this->access = new MemberAccess($settings);
    }

    /** @return array{account_id: int, code?: string} code en clair (XXXX-XXXX-XXXX) seulement pour une lettre, à imprimer une fois */
    public function create(int $memberId, string $delivery, int $actor, ?string $ip): array
    {
        $now = ($this->clock)();
        [$id, $code, $email, $days] = $this->db->atomic(function () use ($memberId, $delivery, $actor, $ip, $now): array {
            $member = $this->managed($actor, 'espace.accounts.manage', $memberId);
            $this->assertIssuable($member, $delivery);
            if ($this->account($memberId) !== null) {
                throw new AccountRuleViolation("Cet adhérent a déjà un accès à l'Espace adhérent.");
            }
            $id = $this->db->insert('member_accounts', ['member_id' => $memberId, 'status' => 'en_attente', 'created_by' => $actor]);
            $this->audit->log($actor, 'espace.account_created', 'member_account', $id, null, ['status' => 'en_attente'], null, $ip);
            [$code, $issued, $days] = $this->issueCode($id, 'activation', $delivery, $actor, $now);
            $this->audit->log($actor, 'espace.code_issued', 'member_account', $id, null, $issued, null, $ip);
            return [$id, $code, (string) $member['email'], $days];
        });
        $letter = $this->deliver($id, $delivery, $email, $code, $days, 'activation');
        return ['account_id' => $id] + ($letter === null ? [] : ['code' => $letter]);
    }

    /** Nouveau code pour un accès en attente (les codes ouverts sont fermés) ; code en clair pour une lettre, sinon null. */
    public function reissueActivation(int $memberId, string $delivery, int $actor, ?string $ip): ?string
    {
        $now = ($this->clock)();
        [$id, $code, $email, $days] = $this->db->atomic(function () use ($memberId, $delivery, $actor, $ip, $now): array {
            $member = $this->managed($actor, 'espace.accounts.manage', $memberId);
            $account = $this->account($memberId);
            if ($account === null || $account['status'] !== 'en_attente') {
                throw new AccountRuleViolation("Un nouveau code d'activation ne peut être délivré qu'à un accès en attente d'activation.");
            }
            $this->assertIssuable($member, $delivery);
            $id = (int) $account['id'];
            [$code, $issued, $days] = $this->issueCode($id, 'activation', $delivery, $actor, $now);
            $this->audit->log($actor, 'espace.code_issued', 'member_account', $id, null, $issued, null, $ip);
            return [$id, $code, (string) $member['email'], $days];
        });
        return $this->deliver($id, $delivery, $email, $code, $days, 'activation');
    }

    /**
     * Réinitialisation au guichet, identité vérifiée : l'ancien mot de passe et toutes les sessions tombent immédiatement,
     * un code « reinitialisation » est délivré (saisi sur /espace/activer). Code en clair pour une lettre, sinon null.
     */
    public function resetByManager(int $memberId, string $delivery, bool $identityChecked, int $actor, ?string $ip): ?string
    {
        $now = ($this->clock)();
        [$id, $code, $email, $days] = $this->db->atomic(function () use ($memberId, $delivery, $identityChecked, $actor, $ip, $now): array {
            $member = $this->managed($actor, 'espace.accounts.reset', $memberId);
            if (!$identityChecked) {
                throw new AccountRuleViolation("Confirmez avoir vérifié l'identité de l'adhérent (pièce d'identité présentée) avant de délivrer un code.");
            }
            $account = $this->account($memberId);
            if ($account === null || $account['status'] !== 'actif') {
                throw new AccountRuleViolation('Seul un accès actif peut être réinitialisé : pour un accès en attente, délivrez un nouveau code d\'activation.');
            }
            $this->assertIssuable($member, $delivery);
            $id = (int) $account['id'];
            $this->db->query('UPDATE member_accounts SET password = NULL, session_version = session_version + 1 WHERE id = ?', [$id]);
            [$code, $issued, $days] = $this->issueCode($id, 'reinitialisation', $delivery, $actor, $now);
            $this->audit->log($actor, 'espace.reset_issued', 'member_account', $id, null, ['identity_checked' => true] + $issued, null, $ip);
            return [$id, $code, (string) $member['email'], $days];
        });
        return $this->deliver($id, $delivery, $email, $code, $days, 'reinitialisation');
    }

    /** Désactivation : sessions et codes fermés. Réactivation : actif si un mot de passe existe, sinon en attente (nouveau code à délivrer). */
    public function setDisabled(int $memberId, bool $disabled, int $actor, ?string $ip): void
    {
        $now = gmdate('Y-m-d H:i:s', ($this->clock)());
        $this->db->atomic(function () use ($memberId, $disabled, $actor, $ip, $now): void {
            $this->managed($actor, 'espace.accounts.manage', $memberId);
            $account = $this->account($memberId) ?? throw new AccountRuleViolation("Cet adhérent n'a pas d'accès à l'Espace adhérent.");
            $id = (int) $account['id'];
            $status = $disabled ? 'desactive' : ($account['password'] !== null ? 'actif' : 'en_attente');
            $this->db->query('UPDATE member_accounts SET status = ?, session_version = session_version + ? WHERE id = ?', [$status, $disabled ? 1 : 0, $id]);
            if ($disabled) {
                $this->db->query('UPDATE member_activation_codes SET used_at = ? WHERE member_account_id = ? AND used_at IS NULL', [$now, $id]);
            }
            $this->audit->log($actor, $disabled ? 'espace.account_disabled' : 'espace.account_enabled', 'member_account', $id,
                ['status' => $account['status']], ['status' => $status], null, $ip);
        });
    }

    /**
     * Activation (ou fin de réinitialisation par un gestionnaire) : identifiant, date de naissance et code. Tout échec donne
     * FAILED, quel que soit le facteur faux ou l'état du compte ; limité sur « espace-activation: » + identifiant et par IP.
     * Retourne l'id du compte ; l'appelant ouvre ensuite la session (MemberAuth::login).
     */
    public function activate(string $identifier, string $birthDate, string $code, string $password, string $confirm, string $ip, int $now): int
    {
        $this->assertNewPassword($password, $confirm); // règles sans identité d'abord : aucun indice sur le compte
        $key = MemberAuth::throttleKey('espace-activation:', $identifier);
        $ipKey = IpKey::of($ip);
        $out = $this->throttle->serialized($ipKey, function () use ($identifier, $birthDate, $code, $password, $ip, $now, $key, $ipKey): int|string {
            $limit = $this->throttle->blockedBy($key, $ipKey, $now);
            if ($limit !== null) {
                $this->db->atomic(function () use ($key, $ipKey, $now, $limit, $ip): void {
                    if ($this->throttle->markBlocked($key, $ipKey, $now, $limit)) {
                        $this->audit->log(null, 'espace.activation_blocked', 'member_account', null, null, ['scope' => $limit], null, $ip);
                    }
                });
                return self::BLOCKED;
            }
            return $this->db->atomic(function () use ($identifier, $birthDate, $code, $password, $ip, $now, $key, $ipKey): int|string {
                // Mêmes requêtes que l'identifiant soit connu ou non (id 0) : pas d'écart de temps exploitable.
                $found = $this->auth->resolve($identifier);
                $account = $this->db->fetch('SELECT id, member_id, status FROM member_accounts WHERE id = ? FOR UPDATE', [(int) ($found['id'] ?? 0)]);
                $member = $this->db->fetch('SELECT birth_date, email, last_name, first_names, status, deleted_at, anonymized_at FROM members WHERE id = ?',
                    [(int) ($account['member_id'] ?? 0)]);
                $codes = $this->db->fetchAll("SELECT id, code_hash, purpose FROM member_activation_codes WHERE member_account_id = ?
                    AND purpose IN ('activation', 'reinitialisation') AND used_at IS NULL AND expires_at > ? FOR UPDATE",
                    [(int) ($account['id'] ?? 0), gmdate('Y-m-d H:i:s', $now)]);
                $hash = ActivationCode::hash($code, $this->appKey);
                $match = null;
                foreach ($codes ?: [['id' => null, 'code_hash' => str_repeat('0', 64), 'purpose' => '']] as $c) {
                    if (hash_equals((string) $c['code_hash'], $hash) && $c['id'] !== null) {
                        $match = $c;
                    }
                }
                $dateOk = hash_equals((string) ($member['birth_date'] ?? ''), self::date($birthDate)) && ($member['birth_date'] ?? null) !== null;
                if ($match === null || !$dateOk || $account['status'] === 'desactive' || !$this->open($member)) {
                    $this->throttle->record($key, $ipKey, false, $now);
                    return self::FAILED;
                }
                $id = (int) $account['id'];
                $this->assertIdentityPassword($password, $member); // facteurs déjà prouvés : ce refus n'apprend rien à un tiers
                $this->setPassword($id, $password, $now);
                $this->throttle->record($key, $ipKey, true, $now);
                $this->audit->log(null, $match['purpose'] === 'activation' ? 'espace.activated' : 'espace.password_reset', 'member_account', $id,
                    null, ['purpose' => $match['purpose']], null, $ip);
                return $id;
            });
        }) ?? self::BLOCKED;
        if (is_string($out)) {
            throw new AccountRuleViolation($out);
        }
        return $out;
    }

    /**
     * Mot de passe oublié : même réponse et même travail visible dans tous les cas (recherche, jeton et e-mail après la réponse).
     * L'ancien mot de passe reste valable tant que le lien n'est pas utilisé : sinon n'importe qui pourrait bloquer un adhérent.
     */
    public function requestReset(string $identifier, string $ip, string $ua, int $now): void
    {
        $key = MemberAuth::throttleKey('espace-oubli:', $identifier);
        $ipKey = IpKey::of($ip);
        if (!$this->throttle->tooManyAttempts($key, $ipKey, $now)) {
            $this->throttle->record($key, $ipKey, false, $now); // comme L1 : partage les limites (5 par clé, 20 par IP, 15 min)
            ($this->defer)(fn () => $this->sendResetLink($identifier, $ip, $ua, $now));
        }
    }

    public function checkResetToken(string $token, int $now): bool
    {
        return $this->tokenOwner($token, $now) !== null;
    }

    /** Consomme le jeton (compte puis jeton sous verrou) ; mêmes effets que activate(). Retourne l'id du compte. */
    public function resetWithToken(string $token, string $password, string $confirm, string $ip, int $now): int
    {
        $this->assertNewPassword($password, $confirm);
        $out = $this->throttle->serialized(IpKey::of($ip), fn (): int => $this->db->atomic(function () use ($token, $password, $ip, $now): int {
            $owner = $this->db->fetchValue("SELECT member_account_id FROM member_activation_codes WHERE code_hash = ? AND purpose = 'oubli'",
                [ActivationCode::hash($token, $this->appKey)]);
            $this->db->fetchValue('SELECT id FROM member_accounts WHERE id = ? FOR UPDATE', [(int) $owner]);
            $member = $this->tokenOwner($token, $now, ' FOR UPDATE'); // deux consommations simultanées : la seconde ne trouve plus rien
            if ($member === null) {
                return 0;
            }
            $id = (int) $member['account_id'];
            $this->assertIdentityPassword($password, $member);
            $this->setPassword($id, $password, $now);
            $this->audit->log(null, 'espace.password_reset', 'member_account', $id, null, ['purpose' => 'oubli'], null, $ip);
            return $id;
        }));
        if ($out === null) {
            throw new AccountRuleViolation(self::BLOCKED);
        }
        return $out ?: throw new AccountRuleViolation(self::INVALID_LINK);
    }

    /** Mot de passe actuel exigé (limité sur « espace: » + numéro) ; session_version + 1 : l'appelant remet la nouvelle version dans sa session. */
    public function changePassword(int $accountId, string $current, string $new, string $confirm, string $ip, int $now): void
    {
        $row = $this->db->fetch('SELECT a.id, a.password, m.member_number, m.email, m.last_name, m.first_names
            FROM member_accounts a JOIN members m ON m.id = a.member_id WHERE a.id = ?', [$accountId]) ?? throw new NotFound("Compte $accountId introuvable.");
        $key = MemberAuth::throttleKey('espace:', (string) $row['member_number']);
        $ipKey = IpKey::of($ip);
        $error = $this->throttle->serialized($ipKey, function () use ($row, $current, $key, $ipKey, $ip, $now, $accountId): string {
            if ($this->throttle->tooManyAttempts($key, $ipKey, $now)) {
                return self::BLOCKED;
            }
            if (!password_verify($current, (string) ($row['password'] ?? Auth::dummyHash()))) {
                $this->db->atomic(function () use ($key, $ipKey, $ip, $now, $accountId): void {
                    $this->throttle->record($key, $ipKey, false, $now);
                    $this->audit->log(null, 'espace.password_change_failed', 'member_account', $accountId, null, null, null, $ip);
                });
                return self::WRONG_CURRENT;
            }
            return '';
        }) ?? self::BLOCKED;
        if ($error !== '') {
            throw new AccountRuleViolation($error);
        }
        $this->assertNewPassword($new, $confirm);
        $this->assertIdentityPassword($new, $row);
        $this->db->atomic(function () use ($accountId, $new, $ip, $now): void {
            $this->db->fetchValue('SELECT id FROM member_accounts WHERE id = ? FOR UPDATE', [$accountId]);
            $this->setPassword($accountId, $new, $now); // ferme aussi les jetons « oubli » ouverts
            $this->audit->log(null, 'espace.password_changed', 'member_account', $accountId, null, null, null, $ip);
        });
    }

    /** Exécuté après la réponse (voir requestReset()). */
    private function sendResetLink(string $identifier, string $ip, string $ua, int $now): void
    {
        $found = $this->auth->resolve($identifier);
        if ($found === null || $found['status'] !== 'actif' || $found['password'] === null) {
            return;
        }
        $id = (int) $found['id'];
        $member = $this->db->fetch('SELECT email, status, deleted_at, anonymized_at FROM members WHERE id = ?', [(int) $found['member_id']]);
        if (!$this->open($member) || (string) $member['email'] === '' || !$this->links->ready()) { // aucun jeton pour un lien qui ne partirait pas
            return;
        }
        try {
            $token = $this->db->atomic(function () use ($id, $now, $ip, $ua): ?string {
                $account = $this->db->fetch('SELECT status, password FROM member_accounts WHERE id = ? FOR UPDATE', [$id]);
                $recent = (int) $this->db->fetchValue("SELECT COUNT(*) FROM member_activation_codes WHERE member_account_id = ? AND purpose = 'oubli' AND created_at >= ?",
                    [$id, gmdate('Y-m-d H:i:s', $now - 3600)]);
                if ($account['status'] !== 'actif' || $account['password'] === null || $recent >= self::FORGOT_PER_HOUR) {
                    return null;
                }
                [$token] = $this->issueCode($id, 'oubli', 'email', null, $now);
                $this->audit->log(null, 'espace.reset_requested', 'member_account', $id, null, null, null, $ip, $ua);
                return $token;
            });
            if ($token === null) {
                return;
            }
            $this->links->send(['id' => "esp-$id", 'email' => (string) $member['email']], $token, 'Réinitialisation de votre mot de passe — Espace adhérent MMMGCI',
                static fn (string $link): string => "Bonjour,\n\n"
                . "Une réinitialisation du mot de passe de votre Espace adhérent MMMGCI a été demandée. "
                . "Pour choisir un nouveau mot de passe, ouvrez ce lien :\n\n$link\n\n"
                . "Ce lien est valable 1 heure et ne peut servir qu'une fois.\n\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.\n",
                '/espace/reinitialiser/');
        } catch (Throwable $e) {
            error_log('Lien de réinitialisation non envoyé (compte adhérent #' . $id . ') : ' . get_class($e)); // classe seule : le message pourrait contenir un secret
        }
    }

    /**
     * Ferme les codes ouverts du compte puis en émet un nouveau (code de 12 caractères, ou jeton de 64 hex pour « oubli »).
     *
     * @return array{0: string, 1: array{purpose: string, delivery: string, expires_at: string}, 2: int} code en clair, données d'audit, durée en jours
     */
    private function issueCode(int $accountId, string $purpose, string $delivery, ?int $actor, int $now): array
    {
        $days = $this->activationDays();
        $at = gmdate('Y-m-d H:i:s', $now);
        $this->db->query('UPDATE member_activation_codes SET used_at = ? WHERE member_account_id = ? AND used_at IS NULL', [$at, $accountId]);
        $code = $purpose === 'oubli' ? bin2hex(random_bytes(32)) : ActivationCode::generate();
        $expires = gmdate('Y-m-d H:i:s', $now + ($purpose === 'oubli' ? self::FORGOT_TTL : $days * 86400));
        $this->db->insert('member_activation_codes', ['member_account_id' => $accountId, 'code_hash' => ActivationCode::hash($code, $this->appKey),
            'purpose' => $purpose, 'delivery' => $delivery, 'issued_by' => $actor, 'expires_at' => $expires, 'created_at' => $at]);
        return [$code, ['purpose' => $purpose, 'delivery' => $delivery, 'expires_at' => $expires], $days];
    }

    /** Lettre : code en clair rendu à l'appelant (imprimé une fois). E-mail : envoi différé du code et du lien, sans nom ni numéro. */
    private function deliver(int $accountId, string $delivery, string $email, string $code, int $days, string $purpose): ?string
    {
        if ($delivery === 'lettre') {
            return ActivationCode::format($code);
        }
        $formatted = ActivationCode::format($code);
        $what = $purpose === 'activation' ? "Un accès à l'Espace adhérent MMMGCI a été ouvert pour vous." : 'Le mot de passe de votre Espace adhérent MMMGCI a été réinitialisé à votre demande.';
        ($this->defer)(fn () => $this->links->send(['id' => "esp-$accountId", 'email' => $email], '', "Votre code d'accès — Espace adhérent MMMGCI",
            static fn (string $link): string => "Bonjour,\n\n$what Pour choisir votre mot de passe, ouvrez ce lien :\n\n$link\n\n"
            . "Saisissez votre adresse e-mail (ou votre numéro de membre), votre date de naissance et ce code :\n\n$formatted\n\n"
            . "Ce code est valable $days jours et ne peut servir qu'une fois.\n\n"
            . "Si vous n'attendiez pas ce message, ignorez-le et contactez la Mutuelle.\n", '/espace/activer'));
        return null;
    }

    /**
     * Droit, périmètre (404 hors périmètre ou supprimé), puis fiche verrouillée (ordre : membre, compte, codes).
     *
     * @return array<string,mixed>
     */
    private function managed(int $actor, string $permission, int $memberId): array
    {
        if (!$this->gate->allows($actor, $permission)) {
            throw new AccountRuleViolation(self::DENIED);
        }
        $this->scope->assertMayAccess($actor, $memberId);
        return $this->db->fetch('SELECT id, birth_date, email, status, deleted_at, anonymized_at FROM members WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$memberId])
            ?? throw new NotFound("Adhérent $memberId introuvable.");
    }

    /** @param array<string,mixed> $member */
    private function assertIssuable(array $member, string $delivery): void
    {
        if ($member['anonymized_at'] !== null || $this->access->level((string) $member['status']) === 'desactive') {
            throw new AccountRuleViolation("Le statut de cet adhérent ne permet pas l'accès à l'Espace adhérent.");
        }
        if ($member['birth_date'] === null) {
            throw new AccountRuleViolation(self::NO_BIRTH_DATE);
        }
        if (!in_array($delivery, ['email', 'lettre'], true)) {
            throw new AccountRuleViolation('Choisissez la remise du code : par e-mail ou par lettre imprimée.');
        }
        if ($delivery === 'email' && ((string) $member['email'] === '' || !$this->links->ready())) {
            throw new AccountRuleViolation("Envoi par e-mail impossible (aucune adresse dans la fiche, ou envoi d'e-mails non configuré) : choisissez la lettre imprimée.");
        }
    }

    /** @return array<string,mixed>|null */
    private function account(int $memberId): ?array
    {
        return $this->db->fetch('SELECT id, status, password FROM member_accounts WHERE member_id = ? FOR UPDATE', [$memberId]);
    }

    /**
     * Compte et fiche du jeton « oubli » s'il est utilisable (ouvert, non expiré, compte actif avec mot de passe, membre ouvert).
     *
     * @return array<string,mixed>|null
     */
    private function tokenOwner(string $token, int $now, string $lock = ''): ?array
    {
        $codeId = $this->db->fetchValue("SELECT id FROM member_activation_codes WHERE code_hash = ? AND purpose = 'oubli' AND used_at IS NULL AND expires_at > ?$lock",
            [ActivationCode::hash($token, $this->appKey), gmdate('Y-m-d H:i:s', $now)]);
        $row = $codeId === null ? null : $this->db->fetch("SELECT a.id AS account_id, m.email, m.last_name, m.first_names, m.status, m.deleted_at, m.anonymized_at
            FROM member_activation_codes c JOIN member_accounts a ON a.id = c.member_account_id JOIN members m ON m.id = a.member_id
            WHERE c.id = ? AND a.status = 'actif' AND a.password IS NOT NULL", [$codeId]);
        return $this->open($row) ? $row : null;
    }

    /** Membre présent, ni anonymisé, et dont le statut ouvre l'Espace. @param array<string,mixed>|null $member */
    private function open(?array $member): bool
    {
        return $member !== null && $member['deleted_at'] === null && $member['anonymized_at'] === null
            && $this->access->level((string) $member['status']) !== 'desactive';
    }

    /** Nouveau haché (politique L1), compte actif, première activation datée, sessions fermées, tous les codes ouverts fermés. */
    private function setPassword(int $accountId, string $password, int $now): void
    {
        $at = gmdate('Y-m-d H:i:s', $now);
        $this->db->query("UPDATE member_accounts SET password = ?, status = 'actif', activated_at = COALESCE(activated_at, ?), session_version = session_version + 1 WHERE id = ?",
            [PasswordPolicy::hash($password), $at, $accountId]);
        $this->db->query('UPDATE member_activation_codes SET used_at = ? WHERE member_account_id = ? AND used_at IS NULL', [$at, $accountId]);
    }

    private function assertNewPassword(string $password, string $confirm): void
    {
        $error = PasswordPolicy::check($password) ?? ($password !== $confirm ? self::MISMATCH : null);
        if ($error !== null) {
            throw new AccountRuleViolation($error);
        }
    }

    /** @param array<string,mixed> $member e-mail, nom et prénoms de la fiche */
    private function assertIdentityPassword(string $password, array $member): void
    {
        $error = PasswordPolicy::check($password, (string) $member['email'], trim($member['first_names'] . ' ' . $member['last_name']));
        if ($error !== null) {
            throw new AccountRuleViolation($error);
        }
    }

    private function activationDays(): int
    {
        try {
            $days = $this->settings->get('espace', 'activation_code_days');
        } catch (\JsonException|\InvalidArgumentException) {
            return self::DEFAULT_DAYS;
        }
        return is_int($days) && $days >= 1 && $days <= 365 ? $days : self::DEFAULT_DAYS;
    }

    /** Date saisie (AAAA-MM-JJ) validée, sinon une valeur qui ne correspond à aucune fiche. */
    private static function date(string $v): string
    {
        $v = trim($v);
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : '-';
    }
}
