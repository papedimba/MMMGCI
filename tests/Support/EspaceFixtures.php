<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Response;
use App\Modules\Auth\PasswordPolicy;

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
}
