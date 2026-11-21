<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class LoginTest extends AdminTestCase
{
    private const NEUTRAL = 'Identifiants incorrects. Vérifiez votre adresse e-mail et votre mot de passe.';
    private const PASSWORD = 'phrase secrète longue 2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
    }

    private function login(string $email, string $password = self::PASSWORD, string $retour = ''): \App\Core\Http\Response
    {
        return $this->request('POST', '/admin/connexion', ['email' => $email, 'password' => $password, 'retour' => $retour]);
    }

    private function loggedInId(): mixed
    {
        return $this->services['session']->get('auth.user_id');
    }

    public function test_login_page_renders_with_csrf_field(): void
    {
        $res = $this->request('GET', '/admin/connexion');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('name="_csrf" value="' . $this->services['csrf']->token() . '"', $res->body());
        $this->assertStringContainsString('<form method="post" action="/admin/connexion"', $res->body());
    }

    public function test_valid_credentials_log_in_and_regenerate_session(): void
    {
        $id = $this->createUser('valide@x.test', ['admin_general']);
        $this->app();
        $before = $this->services['csrf']->token();

        $res = $this->login('valide@x.test');

        $this->assertSame(302, $res->status());
        $this->assertSame('/admin', $res->headers()['Location']);
        $this->assertSame($id, $this->loggedInId());
        $this->assertSame(1, $this->services['session']->get('auth.session_version'));
        $this->assertNotSame($before, $this->services['csrf']->token(), 'le jeton CSRF d\'avant connexion ne doit plus servir');
        $this->assertNotNull($this->db->fetchValue('SELECT last_login_at FROM users WHERE id = ?', [$id]));
        $dash = $this->request('GET', '/admin');
        $this->assertSame(200, $dash->status());
        $this->assertStringContainsString('Bonjour Utilisateur<', $dash->body());
        $this->assertStringContainsString('href="/admin/utilisateurs"', $dash->body());
    }

    public function test_wrong_password_shows_neutral_message(): void
    {
        $this->createUser('faux@x.test', []);
        $res = $this->login('faux@x.test', 'mauvais mot de passe long');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString(self::NEUTRAL, $res->body());
        $this->assertNull($this->loggedInId());
    }

    public function test_unknown_email_shows_the_same_neutral_message(): void
    {
        $res = $this->login('inconnu@x.test');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString(self::NEUTRAL, $res->body());
        $this->assertNull($this->loggedInId());
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $this->createUser('inactif@x.test', ['admin_general'], active: false);
        $res = $this->login('inactif@x.test');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString(self::NEUTRAL, $res->body());
        $this->assertNull($this->loggedInId());
    }

    public function test_email_with_case_and_spaces_logs_in(): void
    {
        $id = $this->createUser('casse@x.test', []);
        $res = $this->login('  CaSSe@X.test ');
        $this->assertSame(302, $res->status());
        $this->assertSame($id, $this->loggedInId());
    }

    public function test_sixth_attempt_is_blocked_even_with_right_password(): void
    {
        $this->createUser('bloque@x.test', []);
        for ($i = 0; $i < 5; $i++) {
            $this->login('bloque@x.test', 'mauvais mot de passe long');
        }
        $res = $this->login('bloque@x.test');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Trop de tentatives : réessayez dans 15 minutes.', $res->body());
        $this->assertNull($this->loggedInId());
        $this->assertSame(5, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0'), 'une tentative bloquée ne compte pas comme un échec');
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_blocked' AND new_values LIKE '%bloque@x.test%'"));
    }

    public function test_admin_redirects_guest_to_login_with_return_path(): void
    {
        $res = $this->request('GET', '/admin');
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/connexion?retour=%2Fadmin', $res->headers()['Location']);

        $form = $this->request('GET', '/admin/connexion?retour=' . rawurlencode('/admin/roles'));
        $this->assertStringContainsString('name="retour" value="/admin/roles"', $form->body());

        $this->createUser('retour@x.test', []);
        $this->assertSame('/admin/roles', $this->login('retour@x.test', retour: '/admin/roles')->headers()['Location']);
    }

    public function test_external_or_protocol_relative_return_is_ignored(): void
    {
        $this->createUser('evil@x.test', []);
        foreach (['//evil.ci', 'https://evil.ci', '/admin//evil.ci', '/admin\\evil.ci', "/admin\r\nX: y", '/admin/../sante', '/admin/%2e%2e/x', '/admin/..'] as $retour) {
            $this->assertSame('/admin', $this->login('evil@x.test', retour: $retour)->headers()['Location'], $retour);
        }
    }

    public function test_session_ends_when_user_is_deactivated(): void
    {
        $id = $this->createUser('desactive@x.test', []);
        $this->actingAs($id);
        $this->assertSame(200, $this->request('GET', '/admin')->status());

        $this->db->update('users', ['is_active' => 0], ['id' => $id]);
        $res = $this->request('GET', '/admin');
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/connexion?retour=%2Fadmin', $res->headers()['Location']);
        $this->assertNull($this->loggedInId());
    }

    public function test_session_ends_when_session_version_changes(): void
    {
        $id = $this->createUser('version@x.test', []);
        $this->actingAs($id);
        $this->db->query('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id]);
        $this->assertSame(302, $this->request('GET', '/admin')->status());
    }

    public function test_logout_requires_post_and_ends_session(): void
    {
        $id = $this->createUser('sortie@x.test', []);
        $this->actingAs($id);
        $this->assertSame(405, $this->request('GET', '/admin/deconnexion')->status());
        $this->assertSame($id, $this->loggedInId());

        $res = $this->request('POST', '/admin/deconnexion');
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/connexion', $res->headers()['Location']);
        $this->assertNull($this->loggedInId());
        $this->assertSame(302, $this->request('GET', '/admin')->status());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.logout' AND user_id = ?", [$id]));
    }

    public function test_guest_post_is_redirected_without_return_path(): void
    {
        $res = $this->request('POST', '/admin/deconnexion');
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/connexion', $res->headers()['Location']);
    }

    public function test_login_without_csrf_token_is_refused(): void
    {
        $this->createUser('csrf@x.test', []);
        $res = $this->app()->handle(new \App\Core\Http\Request('POST', '/admin/connexion', [], ['email' => 'csrf@x.test', 'password' => self::PASSWORD]));
        $this->assertSame(419, $res->status());
        $this->assertNull($this->loggedInId());
    }

    public function test_failed_audit_write_leaves_user_logged_out(): void
    {
        $id = $this->createUser('panne@x.test', []);
        $this->db->query("CREATE TRIGGER audit_panne BEFORE INSERT ON audit_logs FOR EACH ROW
            BEGIN IF NEW.new_values LIKE '%panne@x.test%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit en panne'; END IF; END");
        try {
            try {
                $this->login('panne@x.test');
                $this->fail('l\'échec de l\'audit doit remonter');
            } catch (\PDOException) {
            }
        } finally {
            $this->db->query('DROP TRIGGER audit_panne');
        }
        $this->assertNull($this->loggedInId());
        $this->assertNull($this->db->fetchValue('SELECT last_login_at FROM users WHERE id = ?', [$id]));
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email = 'panne@x.test'"));
    }

    public function test_login_and_failure_are_audited_without_password(): void
    {
        $id = $this->createUser('audit@x.test', []);
        $this->login('AUDIT@x.test', 'mauvais mot de passe long');
        $this->login('audit@x.test');
        $this->login('fantome@x.test', 'autre secret tres long');

        $rows = $this->db->fetchAll("SELECT * FROM audit_logs WHERE action LIKE 'auth.login_%' AND new_values LIKE '%@x.test%' AND (new_values LIKE '%audit@%' OR new_values LIKE '%fantome@%') ORDER BY id");
        $this->assertSame(['auth.login_failed', 'auth.login_succeeded', 'auth.login_failed'], array_column($rows, 'action'));
        $this->assertSame([$id, $id, null], array_map(static fn ($v) => $v === null ? null : (int) $v, array_column($rows, 'user_id')));
        $this->assertSame([$id, $id, null], array_map(static fn ($v) => $v === null ? null : (int) $v, array_column($rows, 'auditable_id')));
        $this->assertSame(['email' => 'audit@x.test'], json_decode($rows[0]['new_values'], true));
        $this->assertSame('user', $rows[0]['auditable_type']);
        $this->assertSame('127.0.0.1', $rows[0]['ip']);
        $this->assertSame('PHPUnit', $rows[0]['user_agent']);
        $all = json_encode($this->db->fetchAll('SELECT * FROM audit_logs'), JSON_UNESCAPED_UNICODE);
        foreach (['mauvais mot de passe long', self::PASSWORD, 'autre secret tres long'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertNull($this->services['audit']()->verify());
    }

    public function test_blocked_attempts_are_audited_once_per_window(): void
    {
        $now = 1_800_000_000;
        $this->overrides['clock'] = static function () use (&$now): int {
            return $now;
        };
        $this->createUser('inonde@x.test', []);
        for ($i = 0; $i < 5; $i++) {
            $this->login('inonde@x.test', 'mauvais mot de passe long');
        }
        for ($i = 0; $i < 30; $i++) {
            $res = $this->login('inonde@x.test');
            $this->assertStringContainsString('Trop de tentatives : réessayez dans 15 minutes.', $res->body());
        }
        $blocked = "SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_blocked' AND new_values LIKE '%inonde@x.test%'";
        $this->assertSame(1, (int) $this->db->fetchValue($blocked));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE succeeded = 2"));
        $this->assertNull($this->loggedInId());

        // Fenêtre suivante : les échecs ont expiré, on échoue à nouveau 5 fois, le premier blocage est de nouveau journalisé.
        $now += 901;
        for ($i = 0; $i < 5; $i++) {
            $this->login('inonde@x.test', 'mauvais mot de passe long');
        }
        $this->login('inonde@x.test');
        $this->login('inonde@x.test');
        $this->assertSame(2, (int) $this->db->fetchValue($blocked));
        $this->assertNull($this->loggedInId());
        $this->assertNull($this->services['audit']()->verify());
    }

    public function test_failed_and_blocked_logins_store_the_email_only_if_it_is_valid(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->login('Mon Secret 2026!', 'mauvais mot de passe long');
        }
        $rows = $this->db->fetchAll("SELECT action, new_values FROM audit_logs WHERE action IN ('auth.login_failed', 'auth.login_blocked') ORDER BY id DESC LIMIT 6");
        $this->assertSame(['auth.login_blocked', 'auth.login_failed'], array_values(array_unique(array_column($rows, 'action'))));
        foreach ($rows as $row) {
            $this->assertSame(['email' => null, 'email_invalid' => true], json_decode($row['new_values'], true));
        }
        $this->assertStringNotContainsString('secret 2026', json_encode($this->db->fetchAll('SELECT * FROM audit_logs')));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email = 'mon secret 2026!' AND succeeded = 2"), 'clé du limiteur inchangée');
    }

    public function test_ip_limit_blocks_are_audited_once_per_ip_per_window(): void
    {
        $now = 1_800_000_000;
        $this->overrides['clock'] = static function () use (&$now): int {
            return $now;
        };
        $blocked = "SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.login_blocked'";
        $before = (int) $this->db->fetchValue($blocked);
        for ($i = 0; $i < 40; $i++) {
            $res = $this->login("tournant$i@x.test", 'mauvais mot de passe long');
        }
        $this->assertStringContainsString('Trop de tentatives : réessayez dans 15 minutes.', $res->body());
        $this->assertSame(20, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0'));
        $this->assertSame(1, (int) $this->db->fetchValue($blocked) - $before);

        // Fenêtre suivante : l'IP échoue de nouveau 20 fois, le premier blocage par IP est de nouveau journalisé.
        $now += 901;
        for ($i = 0; $i < 25; $i++) {
            $res = $this->login("suivant$i@x.test", 'mauvais mot de passe long');
        }
        $this->assertStringContainsString('Trop de tentatives : réessayez dans 15 minutes.', $res->body());
        $this->assertSame(2, (int) $this->db->fetchValue($blocked) - $before);
        $this->assertNull($this->services['audit']()->verify());
    }
}
