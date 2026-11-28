<?php
declare(strict_types=1);

namespace App\Modules\Espace;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use Closure;

/** Activation par code, mot de passe oublié, réinitialisation par lien et page « Mon compte » de l'Espace adhérent. */
final class AccountController
{
    private const FORGOT_SENT = "Si un compte actif correspond à cet identifiant et qu'une adresse e-mail y est enregistrée, un e-mail contenant un lien "
        . "de réinitialisation vient d'être envoyé. Le lien est valable 1 heure. Sans adresse e-mail, adressez-vous à la Mutuelle pour recevoir un nouveau code.";

    /** @param Closure(): int $clock */
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private MemberAuth $auth, private MemberAccountService $accounts, private Closure $clock)
    {
    }

    public function activateForm(Request $r): Response
    {
        return $this->guest('espace/activate', "Activer mon accès", ['identifier' => '', 'birthDate' => '', 'error' => null]);
    }

    public function activate(Request $r): Response
    {
        $identifier = trim(self::str($r->post('identifiant')));
        $birthDate = self::str($r->post('date_naissance'));
        try {
            $id = $this->accounts->activate($identifier, $birthDate, self::str($r->post('code')), self::str($r->post('password')),
                self::str($r->post('password_confirmation')), $r->ip(), ($this->clock)());
        } catch (AccountRuleViolation $e) {
            return $this->guest('espace/activate', "Activer mon accès", ['identifier' => $identifier, 'birthDate' => $birthDate, 'error' => $e->getMessage()]);
        }
        $this->login($id);
        return Response::redirect('/espace');
    }

    public function forgotForm(Request $r): Response
    {
        return $this->guest('espace/forgot', 'Mot de passe oublié', ['message' => null]);
    }

    /** Même page dans tous les cas (compte connu ou non, limite atteinte ou non). */
    public function forgot(Request $r): Response
    {
        $this->accounts->requestReset(trim(self::str($r->post('identifiant'))), $r->ip(), (string) $r->header('User-Agent'), ($this->clock)());
        return $this->guest('espace/forgot', 'Mot de passe oublié', ['message' => self::FORGOT_SENT]);
    }

    public function resetForm(Request $r): Response
    {
        $token = (string) $r->attribute('token');
        return $this->resetPage($token, $this->accounts->checkResetToken($token, ($this->clock)()) ? null : MemberAccountService::INVALID_LINK);
    }

    public function reset(Request $r): Response
    {
        $token = (string) $r->attribute('token');
        if (!$this->accounts->checkResetToken($token, ($this->clock)())) {
            return $this->resetPage($token, MemberAccountService::INVALID_LINK);
        }
        try {
            $id = $this->accounts->resetWithToken($token, self::str($r->post('password')), self::str($r->post('password_confirmation')), $r->ip(), ($this->clock)());
        } catch (AccountRuleViolation $e) {
            return $e->getMessage() === MemberAccountService::INVALID_LINK ? $this->resetPage($token, $e->getMessage()) : $this->resetPage($token, null, $e->getMessage());
        }
        $this->login($id);
        return Response::redirect('/espace')->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function account(Request $r): Response
    {
        return $this->accountPage($r->attribute('member'), null, $this->session->pullFlash('status'));
    }

    public function changePassword(Request $r): Response
    {
        $member = $r->attribute('member');
        try {
            $this->accounts->changePassword((int) $member['account_id'], self::str($r->post('current_password')), self::str($r->post('password')),
                self::str($r->post('password_confirmation')), $r->ip(), ($this->clock)());
        } catch (AccountRuleViolation $e) {
            return $this->accountPage($member, $e->getMessage());
        }
        $this->login((int) $member['account_id']); // nouvelle version dans cette session : les autres sessions tombent
        $this->session->flash('status', 'Votre mot de passe a été modifié. Vos autres sessions ont été fermées.');
        return Response::redirect('/espace/compte');
    }

    private function login(int $accountId): void
    {
        $this->auth->login($this->db->fetch('SELECT id, session_version FROM member_accounts WHERE id = ?', [$accountId]));
    }

    /** @param array{account_id: int, member_id: int} $member */
    private function accountPage(array $member, ?string $error = null, ?string $flash = null): Response
    {
        $row = $this->db->fetch('SELECT member_number, phone, email FROM members WHERE id = ?', [$member['member_id']]);
        // Téléphone et e-mail affichés seulement s'ils ouvrent bien ce compte (une seule fiche) : même règle que la connexion.
        $identifiers = array_values(array_filter([$row['member_number'], $row['phone'], $row['email']],
            fn (?string $v): bool => $v !== null && $v !== '' && (int) ($this->auth->resolve($v)['id'] ?? 0) === $member['account_id']));
        return new Response($this->view->render('espace/account', ['title' => 'Mon compte', 'member' => $member, 'csrfToken' => $this->csrf->token(),
            'identifiers' => $identifiers, 'error' => $error, 'flash' => $flash], 'layouts/espace'));
    }

    private function resetPage(string $token, ?string $invalid, ?string $error = null): Response
    {
        // no-referrer : le jeton présent dans l'URL ne doit pas fuiter vers un autre site par l'en-tête Referer.
        return $this->guest('espace/reset', 'Nouveau mot de passe', ['token' => $token, 'invalid' => $invalid, 'error' => $error])
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** @param array<string,mixed> $data */
    private function guest(string $view, string $title, array $data): Response
    {
        return new Response($this->view->render($view, ['title' => "$title — Espace adhérent MMMGCI", 'csrfToken' => $this->csrf->token()] + $data));
    }

    private static function str(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}
