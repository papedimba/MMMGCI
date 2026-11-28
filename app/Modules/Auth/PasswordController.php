<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use Closure;
use Throwable;

/** Mot de passe oublié (lien par e-mail), réinitialisation par jeton et changement du mot de passe de l'utilisateur connecté. */
final class PasswordController
{
    private const NEUTRAL = "Si un compte actif correspond à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé. Le lien est valable 1 heure.";
    private const INVALID = "Ce lien n'est plus valable : demandez un nouveau lien de réinitialisation.";
    private const MISMATCH = 'Les deux mots de passe saisis sont différents : saisissez le même nouveau mot de passe dans les deux champs.';
    private const WRONG_CURRENT = 'Mot de passe actuel incorrect : saisissez le mot de passe que vous utilisez pour vous connecter.';
    private const BLOCKED = 'Trop de tentatives : réessayez dans 15 minutes.';
    private const SUBJECT = 'Réinitialisation de votre mot de passe — MMMGCI';

    /**
     * @param Closure(callable): void $defer exécute un travail après l'envoi de la réponse
     * @param Closure(): int $clock
     */
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Auth $auth, private Gate $gate, private Audit $audit, private LoginThrottle $throttle, private PasswordReset $resets,
        private LinkMailer $links, private Closure $defer, private Closure $clock)
    {
    }

    public function forgotForm(Request $r): Response
    {
        return $this->guestPage('auth/forgot', 'Mot de passe oublié', ['sent' => false]);
    }

    /** Même page, quelle que soit l'adresse, et même quand la limite est atteinte (rien n'est alors envoyé). */
    public function forgot(Request $r): Response
    {
        $email = $r->post('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        [$ip, $now] = [$r->ip(), ($this->clock)()];
        // Compté comme un échec de connexion : partage les limites par compte (5) et par IP (20) sur 15 minutes.
        if (!$this->throttle->tooManyAttempts('reset:' . $email, $ip, $now)) {
            $this->throttle->record('reset:' . $email, $ip, false, $now);
            // Recherche du compte, jeton, audit et e-mail après la réponse : même travail visible pour une adresse connue ou non.
            $ua = $r->header('User-Agent');
            ($this->defer)(fn () => $this->sendLink($email, $ip, $ua, $now));
        }
        return $this->guestPage('auth/forgot', 'Mot de passe oublié', ['sent' => true, 'message' => self::NEUTRAL]);
    }

    public function resetForm(Request $r): Response
    {
        $token = (string) $r->attribute('token');
        return $this->resetPage($token, $this->resets->check($token, ($this->clock)()) === null ? self::INVALID : null);
    }

    public function reset(Request $r): Response
    {
        $token = (string) $r->attribute('token');
        $now = ($this->clock)();
        $userId = $this->resets->check($token, $now);
        if ($userId === null) {
            return $this->resetPage($token, self::INVALID);
        }
        $user = $this->db->fetch('SELECT id, name, email FROM users WHERE id = ?', [$userId]);
        $password = self::str($r->post('password'));
        $error = $this->newPasswordError($password, self::str($r->post('password_confirmation')), $user);
        if ($error !== null) {
            return $this->resetPage($token, null, $error);
        }
        $done = $this->db->transaction(function () use ($token, $now, $userId, $password, $r): bool {
            if ($this->resets->consume($token, $now) === null) { // utilisé ou désactivé entre-temps
                return false;
            }
            $this->changePassword($userId, $password, $now);
            $this->audit->log($userId, 'auth.password_reset', 'user', $userId, null, null, null, $r->ip(), $r->header('User-Agent'));
            return true;
        });
        if (!$done) {
            return $this->resetPage($token, self::INVALID);
        }
        $this->session->flash('status', 'Votre mot de passe a été réinitialisé : connectez-vous avec le nouveau mot de passe.');
        return Response::redirect('/admin/connexion')->withHeader('Referrer-Policy', 'no-referrer')->withHeader('Cache-Control', 'no-store');
    }

    public function changeForm(Request $r): Response
    {
        return $this->accountPage($r->attribute('user'));
    }

    public function change(Request $r): Response
    {
        $user = $r->attribute('user');
        $id = (int) $user['id'];
        [$ip, $ua, $now] = [$r->ip(), $r->header('User-Agent'), ($this->clock)()];
        // Partage la limite de 5 échecs / 15 min du couple (e-mail, IP) avec la connexion.
        // Sérialisé par IP (LoginThrottle::serialized) : des essais simultanés ne dépassent pas la limite.
        $error = $this->throttle->serialized($ip, function () use ($user, $id, $ip, $ua, $now, $r): string {
            if ($this->throttle->tooManyAttempts((string) $user['email'], $ip, $now)) {
                return self::BLOCKED;
            }
            if (!password_verify(self::str($r->post('current_password')), $user['password'])) {
                $this->db->transaction(function () use ($user, $id, $ip, $ua, $now): void {
                    $this->throttle->record((string) $user['email'], $ip, false, $now);
                    $this->audit->log($id, 'auth.password_change_failed', 'user', $id, null, null, null, $ip, $ua);
                });
                return self::WRONG_CURRENT;
            }
            return '';
        }) ?? self::BLOCKED; // verrou non obtenu : comme une limite atteinte
        if ($error !== '') {
            return $this->accountPage($user, $error);
        }
        $password = self::str($r->post('password'));
        $error = $this->newPasswordError($password, self::str($r->post('password_confirmation')), $user);
        if ($error !== null) {
            return $this->accountPage($user, $error);
        }
        $version = $this->db->transaction(function () use ($id, $password, $r, $now): int {
            $version = $this->changePassword($id, $password, $now);
            $this->audit->log($id, 'auth.password_changed', 'user', $id, null, null, null, $r->ip(), $r->header('User-Agent'));
            return $version;
        });
        $this->auth->login(['id' => $id, 'session_version' => $version]); // après le commit : seule la session courante reste valide
        $this->session->flash('status', 'Votre mot de passe a été modifié. Vos autres sessions ont été fermées.');
        return Response::redirect('/admin');
    }

    /**
     * Nouveau haché, session_version + 1 (ferme les autres sessions) et jetons de réinitialisation en attente annulés ;
     * retourne la nouvelle version. À appeler dans une transaction.
     */
    private function changePassword(int $userId, string $password, int $now): int
    {
        $this->db->query('UPDATE users SET password = ?, session_version = session_version + 1 WHERE id = ?', [PasswordPolicy::hash($password), $userId]);
        $this->db->query('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [gmdate('Y-m-d H:i:s', $now), $userId]);
        return (int) $this->db->fetchValue('SELECT session_version FROM users WHERE id = ?', [$userId]);
    }

    /** Exécuté après la réponse (voir forgot()). */
    private function sendLink(string $email, string $ip, ?string $ua, int $now): void
    {
        $user = $this->db->fetch('SELECT id, name, email FROM users WHERE email = ? AND is_active = 1', [$email]);
        if ($user === null) {
            return;
        }
        if (!$this->links->ready()) { // aucun jeton émis pour un lien qui ne partirait pas
            return;
        }
        $id = (int) $user['id'];
        try {
            $token = $this->db->transaction(function () use ($id, $now, $ip, $ua): ?string {
                $token = $this->resets->issue($id, $now);
                if ($token !== null) { // l'audit ne garde que l'utilisateur, jamais le jeton
                    $this->audit->log($id, 'auth.password_reset_requested', 'user', $id, null, null, null, $ip, $ua);
                }
                return $token;
            });
            if ($token === null) {
                return;
            }
            $this->links->send($user, $token, self::SUBJECT, static fn (string $link): string => "Bonjour {$user['name']},\n\n"
                . "Une réinitialisation du mot de passe de votre compte d'administration MMMGCI a été demandée. "
                . "Pour choisir un nouveau mot de passe, ouvrez ce lien :\n\n$link\n\n"
                . "Ce lien est valable 1 heure et ne peut servir qu'une fois.\n\n"
                . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message : votre mot de passe reste inchangé.\n");
        } catch (Throwable $e) {
            // Classe et utilisateur seulement : le message pourrait contenir un secret.
            error_log('Lien de réinitialisation non envoyé (utilisateur #' . $id . ') : ' . get_class($e));
        }
    }

    /** @param array<string,mixed> $user */
    private function newPasswordError(string $password, string $confirmation, array $user): ?string
    {
        return PasswordPolicy::check($password, (string) $user['email'], (string) $user['name'])
            ?? ($password !== $confirmation ? self::MISMATCH : null);
    }

    private function resetPage(string $token, ?string $invalid, ?string $error = null): Response
    {
        // no-referrer : le jeton présent dans l'URL ne doit pas fuiter vers un autre site par l'en-tête Referer.
        return $this->guestPage('auth/reset', 'Nouveau mot de passe', ['token' => $token, 'invalid' => $invalid, 'error' => $error])
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string,mixed> $data */
    private function guestPage(string $view, string $title, array $data): Response
    {
        return new Response($this->view->render($view, ['title' => "$title — Administration MMMGCI", 'csrfToken' => $this->csrf->token()] + $data));
    }

    /** @param array<string,mixed> $user */
    private function accountPage(array $user, ?string $error = null): Response
    {
        return new Response($this->view->render('admin/account/password', [
            'title' => 'Changer mon mot de passe',
            'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => null,
            'error' => $error,
        ], 'layouts/admin'));
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
