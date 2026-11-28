<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\IpKey;
use App\Core\View;
use App\Modules\Auth\LoginThrottle;
use Closure;

/** Connexion, déconnexion et accueil provisoire de l'Espace adhérent. L'audit ne porte jamais l'identifiant saisi. */
final class LoginController
{
    public const FAILED = 'Identifiant ou mot de passe incorrect.';
    private const BLOCKED = 'Trop de tentatives : réessayez dans 15 minutes.';

    /** @param Closure(): int $clock */
    public function __construct(private View $view, private Csrf $csrf, private Database $db, private MemberAuth $auth,
        private Audit $audit, private LoginThrottle $throttle, private Closure $clock)
    {
    }

    public function show(Request $r): Response
    {
        $stale = $this->auth->hasSession();
        if ($this->auth->current() !== null) {
            return Response::redirect('/espace');
        }
        if ($stale) { // session close à l'instant (cookie effacé) : un jeton émis maintenant serait perdu, on repart d'une session neuve
            return Response::redirect('/espace/connexion');
        }
        return $this->form(self::safeReturn($r->input('retour')));
    }

    public function login(Request $r): Response
    {
        $identifier = $r->post('identifiant');
        $identifier = is_string($identifier) ? trim($identifier) : '';
        $password = $r->post('password');
        $password = is_string($password) ? $password : '';
        $retour = self::safeReturn($r->post('retour'));
        [$ip, $ua, $now] = [IpKey::of($r->ip()), $r->header('User-Agent'), ($this->clock)()];
        $key = MemberAuth::throttleKey('espace:', $identifier);

        // Sérialisé par IP : des tentatives simultanées ne lisent jamais le même compteur (verrou non obtenu = bloqué).
        [$blocked, $account] = $this->throttle->serialized($ip, function () use ($identifier, $password, $key, $ip, $now, $r, $ua): array {
            $limit = $this->throttle->blockedBy($key, $ip, $now);
            if ($limit !== null) {
                $this->db->transaction(function () use ($key, $ip, $now, $limit, $r, $ua): void {
                    if ($this->throttle->markBlocked($key, $ip, $now, $limit)) {
                        $this->audit->log(null, 'espace.login_blocked', 'member_account', null, null, ['scope' => $limit], null, $r->ip(), $ua);
                    }
                });
                return [true, null];
            }
            return [false, $this->db->transaction(function () use ($identifier, $password, $key, $ip, $now, $r, $ua): ?array {
                $account = $this->auth->attempt($identifier, $password, $now);
                $this->throttle->record($key, $ip, $account !== null, $now);
                if ($account !== null) {
                    $this->audit->log(null, 'espace.login', 'member_account', (int) $account['id'], null, null, null, $r->ip(), $ua);
                }
                return $account;
            })];
        }) ?? [true, null];
        if ($blocked) {
            return $this->form($retour, $identifier, self::BLOCKED);
        }
        if ($account === null) {
            return $this->form($retour, $identifier, self::FAILED);
        }
        $this->auth->login($account); // après le commit : jamais de session ouverte sans sa ligne d'audit
        return Response::redirect($retour);
    }

    public function logout(Request $r): Response
    {
        $id = (int) $r->attribute('member')['account_id'];
        $this->audit->log(null, 'espace.logout', 'member_account', $id, null, null, null, $r->ip(), $r->header('User-Agent'));
        $this->auth->logout();
        return Response::redirect('/espace/connexion');
    }

    /** Accueil provisoire (remplacé par la tâche 5). */
    public function home(Request $r): Response
    {
        return new Response($this->view->render('espace/home-placeholder', ['title' => 'Accueil', 'member' => $r->attribute('member'),
            'csrfToken' => $this->csrf->token()], 'layouts/espace'));
    }

    /** Chemin interne à /espace/ uniquement : ni hôte externe (//, \), ni segment .. (même encodé), ni caractère de contrôle. */
    public static function safeReturn(mixed $v): string
    {
        return is_string($v) && str_starts_with($v, '/espace/') && !str_contains($v, '//') && !str_contains($v, '\\')
            && !preg_match('/[\x00-\x1F\x7F]/', $v) && !preg_match('#(^|/)\.\.(/|$)#', rawurldecode($v)) ? $v : '/espace';
    }

    private function form(string $retour, string $identifier = '', ?string $error = null): Response
    {
        return new Response($this->view->render('espace/login', [
            'title' => 'Connexion — Espace adhérent MMMGCI',
            'csrfToken' => $this->csrf->token(),
            'retour' => $retour,
            'identifier' => $identifier,
            'error' => $error,
        ]));
    }
}
