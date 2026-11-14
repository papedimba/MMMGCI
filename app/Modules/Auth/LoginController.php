<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View;
use Closure;

final class LoginController
{
    private const FAILED = 'Identifiants incorrects. Vérifiez votre adresse e-mail et votre mot de passe.';
    private const BLOCKED = 'Trop de tentatives : réessayez dans 15 minutes.';

    /** @param Closure(): int $clock */
    public function __construct(private View $view, private Csrf $csrf, private Database $db, private Auth $auth,
        private Audit $audit, private LoginThrottle $throttle, private Closure $clock)
    {
    }

    public function show(Request $r): Response
    {
        return $this->form(self::safeReturn($r->input('retour')));
    }

    public function login(Request $r): Response
    {
        $email = $r->post('email');
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        $password = $r->post('password');
        $password = is_string($password) ? $password : '';
        $retour = self::safeReturn($r->post('retour'));
        [$ip, $ua, $now] = [$r->ip(), $r->header('User-Agent'), ($this->clock)()];
        $userId = $this->db->fetchValue('SELECT id FROM users WHERE email = ?', [$email]);
        $userId = $userId === null ? null : (int) $userId;
        $log = fn (string $action) => $this->audit->log($userId, $action, 'user', $userId, null, ['email' => $email], null, $ip, $ua);

        // Blocage non enregistré dans login_attempts (il gonflerait la table), mais journalisé.
        if ($this->throttle->tooManyAttempts($email, $ip, $now)) {
            $log('auth.login_blocked');
            return $this->form($retour, $email, self::BLOCKED);
        }
        $ok = $this->db->transaction(function () use ($email, $password, $ip, $now, $log): bool {
            $ok = $this->auth->attempt($email, $password, $ip, $now);
            $this->throttle->record($email, $ip, $ok, $now);
            $log($ok ? 'auth.login_succeeded' : 'auth.login_failed');
            return $ok;
        });
        return $ok ? Response::redirect($retour) : $this->form($retour, $email, self::FAILED);
    }

    public function logout(Request $r): Response
    {
        $id = (int) $r->attribute('user')['id'];
        $this->audit->log($id, 'auth.logout', 'user', $id, null, null, null, $r->ip(), $r->header('User-Agent'));
        $this->auth->logout();
        return Response::redirect('/admin/connexion');
    }

    /** Chemin interne à /admin uniquement : ni hôte externe (//, \), ni caractère de contrôle (en-tête Location). */
    private static function safeReturn(mixed $v): string
    {
        return is_string($v) && str_starts_with($v, '/admin') && !str_contains($v, '//') && !str_contains($v, '\\')
            && !preg_match('/[\x00-\x1F\x7F]/', $v) ? $v : '/admin';
    }

    private function form(string $retour, string $email = '', ?string $error = null): Response
    {
        return new Response($this->view->render('auth/login', [
            'title' => 'Connexion — Administration MMMGCI',
            'csrfToken' => $this->csrf->token(),
            'retour' => $retour,
            'email' => $email,
            'error' => $error,
        ]));
    }
}
