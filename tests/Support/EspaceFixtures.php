<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Response;
use App\Modules\Auth\PasswordPolicy;
use App\Modules\Espace\MemberAccountService;

/** Membres et comptes adhérents de test, requêtes avec le jeton CSRF de l'Espace (pour une classe AdminTestCase). */
trait EspaceFixtures
{
    protected const MEMBER_PASSWORD = 'marin au long cours 2026';

    /** @param array<string,mixed> $member colonnes de members @return array{member: int, account: int} */
    protected function memberWithAccount(array $member = [], string $status = 'actif', ?string $password = self::MEMBER_PASSWORD): array
    {
        static $n = 0;
        $n++;
        $memberId = $this->db->insert('members', $member + ['member_number' => 'ESP-' . getmypid() . "-$n", 'last_name' => 'Marin', 'first_names' => 'Test']);
        $creator = (int) ($this->db->fetchValue('SELECT id FROM users ORDER BY id LIMIT 1') ?? $this->createUser("createur$n-" . getmypid() . '@x.test', []));
        $account = $this->db->insert('member_accounts', ['member_id' => $memberId, 'status' => $status, 'created_by' => $creator,
            'password' => $password === null ? null : PasswordPolicy::hash($password)]);
        return ['member' => $memberId, 'account' => $account];
    }

    /** @param array<string,mixed> $post */
    protected function espacePost(string $path, array $post = []): Response
    {
        $this->app();
        return $this->request('POST', $path, $post + ['_csrf' => $this->services['member_csrf']->token()]);
    }

    protected function espaceLogin(string $identifier, string $password = self::MEMBER_PASSWORD, string $retour = ''): Response
    {
        return $this->espacePost('/espace/connexion', ['identifiant' => $identifier, 'password' => $password, 'retour' => $retour]);
    }

    protected function loggedAccount(): mixed
    {
        return $this->services['member_session']->get('espace.account_id');
    }

    protected function memberNumber(int $memberId): string
    {
        return (string) $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$memberId]);
    }

    protected const BIRTH_DATE = '1980-05-17';
    protected const NEW_MEMBER_PASSWORD = 'voile hissée vers grand-bassam';

    /** Fiche sans compte, avec date de naissance (sauf surcharge). @param array<string,mixed> $member */
    protected function memberForAccess(array $member = []): int
    {
        static $n = 0;
        $n++;
        return $this->db->insert('members', $member + ['member_number' => 'ACC-' . getmypid() . "-$n", 'last_name' => 'Kouadio', 'first_names' => 'Aya',
            'birth_date' => self::BIRTH_DATE, 'status' => 'actif']);
    }

    protected function accounts(): MemberAccountService
    {
        $this->app();
        return $this->services['member_accounts']();
    }

    protected function activateVia(string $identifier, string $code, string $birthDate = self::BIRTH_DATE, string $password = self::NEW_MEMBER_PASSWORD): Response
    {
        return $this->espacePost('/espace/activer', ['identifiant' => $identifier, 'date_naissance' => $birthDate, 'code' => $code,
            'password' => $password, 'password_confirmation' => $password]);
    }

    /** @return list<array{to: string, subject: string, text: string}> */
    protected function mails(): array
    {
        $this->app();
        return array_map(static fn (array $m): array => ['to' => $m['to'], 'subject' => $m['subject'], 'text' => base64_decode(str_replace("\r\n", '', $m['body']))], $this->mail->messages);
    }
}
