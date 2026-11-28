<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Response;
use App\Modules\Espace\LoginController;
use App\Modules\Espace\RequireMember;
use App\Modules\Espace\RequireMemberWrite;
use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

final class EspaceLoginTest extends AdminTestCase
{
    use EspaceFixtures;

    private const NEUTRAL = 'Identifiant ou mot de passe incorrect.';
    private const BLOCKED = 'Trop de tentatives : réessayez dans 15 minutes.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
    }

    private function assertRefused(Response $res, string $case): void
    {
        $this->assertSame(200, $res->status(), $case);
        $this->assertStringContainsString(self::NEUTRAL, $res->body(), $case);
        $this->assertNull($this->loggedAccount(), $case);
    }

    public function test_login_by_member_number_phone_or_email(): void
    {
        $ids = $this->memberWithAccount(['phone' => '0701020304', 'email' => 'marin.un@x.test']);
        foreach ([$this->memberNumber($ids['member']), '+225 07 01 02 03 04', '07.01.02.03.04', '  Marin.UN@x.test '] as $identifier) {
            $this->forgetApp();
            $res = $this->espaceLogin($identifier);
            $this->assertSame(302, $res->status(), $identifier);
            $this->assertSame('/espace', $res->headers()['Location']);
            $this->assertSame($ids['account'], $this->loggedAccount(), $identifier);
        }
        $this->assertNotNull($this->db->fetchValue('SELECT last_login_at FROM member_accounts WHERE id = ?', [$ids['account']]));
        $row = $this->db->fetch("SELECT * FROM audit_logs WHERE action = 'espace.login' ORDER BY id DESC LIMIT 1");
        $this->assertNull($row['user_id']);
        $this->assertSame(['member_account', (string) $ids['account']], [$row['auditable_type'], (string) $row['auditable_id']]);
        $this->assertSame(200, $this->request('GET', '/espace')->status());
        // Déjà connecté : la page de connexion renvoie à l'accueil ; déconnexion par POST.
        $this->assertSame('/espace', $this->request('GET', '/espace/connexion')->headers()['Location']);
        $this->assertSame('/espace/connexion', $this->espacePost('/espace/deconnexion')->headers()['Location']);
        $this->assertNull($this->loggedAccount());
        $this->assertSame(302, $this->request('GET', '/espace')->status());
    }

    public function test_shared_phone_or_email_is_treated_as_unknown(): void
    {
        $a = $this->memberWithAccount(['phone' => '0711111111', 'email' => 'partage@x.test']);
        $this->memberWithAccount(['phone' => '0711111111', 'email' => 'partage@x.test']);
        $this->assertRefused($this->espaceLogin('0711111111'), 'téléphone partagé');
        $this->assertRefused($this->espaceLogin('partage@x.test'), 'e-mail partagé');
        $this->assertSame(302, $this->espaceLogin($this->memberNumber($a['member']))->status(), 'le numéro reste unique');

        // Fiche sans compte ou supprimée qui partage le téléphone : seule la fiche avec compte compte.
        $this->forgetApp();
        $b = $this->memberWithAccount(['phone' => '0722222222']);
        $this->db->insert('members', ['member_number' => 'SANS-COMPTE-' . getmypid(), 'last_name' => 'Autre', 'first_names' => 'Marin', 'phone' => '0722222222']);
        $c = $this->memberWithAccount(['phone' => '0722222222']);
        $this->db->query('UPDATE members SET deleted_at = NOW() WHERE id = ?', [$c['member']]);
        $this->assertSame(302, $this->espaceLogin('0722222222')->status());
        $this->assertSame($b['account'], $this->loggedAccount());
    }

    public function test_neutral_message_for_unknown_wrong_pending_disabled_or_struck_off(): void
    {
        $audit = (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
        $ok = $this->memberWithAccount();
        $this->assertRefused($this->espaceLogin('INCONNU-999'), 'inconnu');
        $this->assertRefused($this->espaceLogin('inconnu@x.test'), 'e-mail inconnu');
        $this->assertRefused($this->espaceLogin($this->memberNumber($ok['member']), 'mauvais mot de passe long'), 'mauvais mot de passe');
        $cases = [
            'en attente' => $this->memberWithAccount([], 'en_attente'),
            'désactivé' => $this->memberWithAccount([], 'desactive'),
            'sans mot de passe' => $this->memberWithAccount([], 'actif', null),
            'radié' => $this->memberWithAccount(['status' => 'radie']),
            'décédé' => $this->memberWithAccount(['status' => 'decede']),
        ];
        foreach ($cases as $case => $ids) {
            $this->assertRefused($this->espaceLogin($this->memberNumber($ids['member'])), $case);
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE id > ?", [$audit]), 'un échec ne va pas dans l\'audit');
        $this->assertSame(8, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND email LIKE 'espace:%'"));
        // Suspendu : connexion en consultation seule.
        $s = $this->memberWithAccount(['status' => 'suspendu']);
        $this->assertSame(302, $this->espaceLogin($this->memberNumber($s['member']))->status());
        $this->assertStringContainsString('consultation seule', $this->request('GET', '/espace')->body());
    }

    public function test_unknown_identifier_costs_a_password_verify(): void
    {
        $ids = $this->memberWithAccount();
        $this->app();
        $auth = $this->services['member_auth']();
        $time = static function (callable $f): float {
            $best = INF;
            for ($i = 0; $i < 3; $i++) {
                $t = hrtime(true);
                $f();
                $best = min($best, hrtime(true) - $t);
            }
            return $best;
        };
        $known = $time(fn () => $auth->attempt($this->memberNumber($ids['member']), 'mauvais mot de passe long', time()));
        $unknown = $time(fn () => $auth->attempt('INCONNU-' . random_int(1, 1_000_000), 'mauvais mot de passe long', time()));
        $pending = $this->memberWithAccount([], 'en_attente');
        $refused = $time(fn () => $auth->attempt($this->memberNumber($pending['member']), self::MEMBER_PASSWORD, time()));
        $this->assertGreaterThan($known * 0.5, $unknown, 'identifiant inconnu : un password_verify factice');
        $this->assertGreaterThan($known * 0.5, $refused, 'compte refusé : un password_verify factice');
    }

    public function test_login_throttled_per_identifier_and_ip_then_per_ip(): void
    {
        $now = 1_800_000_000;
        $this->overrides['clock'] = static function () use (&$now): int {
            return $now;
        };
        $ids = $this->memberWithAccount(['phone' => '0733333333']);
        // Formats différents du même téléphone : une seule clé.
        foreach (['0733333333', '+225 07 33 33 33 33', '07 33 33 33 33', '002250733333333', '07-33-33-33-33'] as $variant) {
            $this->assertRefused($this->espaceLogin($variant, 'mauvais mot de passe long'), $variant);
        }
        $res = $this->espaceLogin('0733333333');
        $this->assertStringContainsString(self::BLOCKED, $res->body());
        $this->assertNull($this->loggedAccount());
        $this->assertSame(5, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email = 'espace:0733333333' AND succeeded = 0"));
        // Le numéro de membre est une autre clé : pas encore bloqué.
        $this->assertSame(302, $this->espaceLogin($this->memberNumber($ids['member']))->status());

        // Limite par IP : 20 échecs, toutes clés confondues.
        $this->forgetApp();
        $now += 901;
        for ($i = 0; $i < 25; $i++) {
            $res = $this->espaceLogin("INCONNU-$i", 'mauvais mot de passe long');
        }
        $this->assertStringContainsString(self::BLOCKED, $res->body());
        $this->assertStringContainsString(self::BLOCKED, $this->espaceLogin($this->memberNumber($ids['member']))->body());
        $this->assertNull($this->loggedAccount());
        $now += 901;
        $this->assertSame(302, $this->espaceLogin($this->memberNumber($ids['member']))->status());
    }

    public function test_first_blocked_attempt_audited_once_without_identifier(): void
    {
        $now = 1_800_000_000;
        $this->overrides['clock'] = static function () use (&$now): int {
            return $now;
        };
        $ids = $this->memberWithAccount(['email' => 'bloque@x.test']);
        $audit = (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
        for ($i = 0; $i < 5; $i++) {
            $this->espaceLogin('bloque@x.test', 'mauvais mot de passe long');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->assertStringContainsString(self::BLOCKED, $this->espaceLogin('bloque@x.test')->body());
        }
        $rows = $this->db->fetchAll("SELECT * FROM audit_logs WHERE action = 'espace.login_blocked' AND id > ?", [$audit]);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['user_id']);
        $this->assertNull($rows[0]['auditable_id']);
        $this->assertSame(['scope' => 'account'], json_decode((string) $rows[0]['new_values'], true));
        $all = json_encode($this->db->fetchAll('SELECT * FROM audit_logs'), JSON_UNESCAPED_UNICODE);
        foreach (['bloque@x.test', 'mauvais mot de passe long', self::MEMBER_PASSWORD, $this->memberNumber($ids['member'])] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
        $this->assertNull($this->services['audit']()->verify());
    }

    public function test_retour_only_accepts_internal_espace_paths(): void
    {
        $ids = $this->memberWithAccount();
        $number = $this->memberNumber($ids['member']);
        $cases = ['/espace/fiche' => '/espace/fiche', '/espace/demandes?page=2' => '/espace/demandes?page=2', '/admin' => '/espace', '/admin/utilisateurs' => '/espace',
            '//evil.test' => '/espace', 'https://evil.test/espace/' => '/espace', '/espace//evil.test' => '/espace', '/espace/../admin' => '/espace',
            '/espace/%2e%2e/admin' => '/espace', '/espacex' => '/espace', '/espace' => '/espace', "/espace/a\r\nX: y" => '/espace', '/espace/\\evil' => '/espace'];
        foreach ($cases as $retour => $expected) {
            $this->forgetApp();
            $this->assertSame($expected, $this->espaceLogin($number, self::MEMBER_PASSWORD, $retour)->headers()['Location'], $retour);
        }
        // Visiteur non connecté sur une rubrique : retour vers ce chemin.
        $this->forgetApp();
        $this->app();
        $this->services['router']->get('/espace/sonde', static fn (): Response => new Response('ok'), [$this->services['require_member']]);
        $this->assertSame('/espace/connexion?retour=%2Fespace%2Fsonde', $this->request('GET', '/espace/sonde')->headers()['Location']);
        $this->assertSame('/espace/connexion', $this->request('GET', '/espace')->headers()['Location']);
        $this->assertStringContainsString('name="retour" value="/espace/sonde"', $this->request('GET', '/espace/connexion?retour=/espace/sonde')->body());
    }

    public function test_struck_off_or_deceased_member_session_closed_on_next_request(): void
    {
        foreach (['radie', 'decede'] as $status) {
            $this->forgetApp();
            $ids = $this->memberWithAccount();
            $this->espaceLogin($this->memberNumber($ids['member']));
            $this->assertSame(200, $this->request('GET', '/espace')->status());
            $this->db->query('UPDATE members SET status = ? WHERE id = ?', [$status, $ids['member']]);
            $this->assertSame(302, $this->request('GET', '/espace')->status(), $status);
            $this->assertNull($this->loggedAccount());
        }
        // Compte désactivé ou version de session changée (réinitialisation) : même fermeture.
        foreach (["UPDATE member_accounts SET status = 'desactive' WHERE id = ?", 'UPDATE member_accounts SET session_version = session_version + 1 WHERE id = ?'] as $sql) {
            $this->forgetApp();
            $ids = $this->memberWithAccount();
            $this->espaceLogin($this->memberNumber($ids['member']));
            $this->db->query($sql, [$ids['account']]);
            $this->assertSame(302, $this->request('GET', '/espace')->status(), $sql);
            $this->assertNull($this->loggedAccount(), $sql);
        }
    }

    public function test_soft_deleted_or_anonymized_member_cannot_log_in(): void
    {
        foreach (['deleted_at', 'anonymized_at'] as $col) {
            $this->forgetApp();
            $ids = $this->memberWithAccount(['email' => "$col@x.test"]);
            $number = $this->memberNumber($ids['member']);
            $this->espaceLogin($number);
            $this->db->query("UPDATE members SET $col = NOW() WHERE id = ?", [$ids['member']]);
            $this->assertSame('/espace/connexion', $this->request('GET', '/espace/connexion')->headers()['Location'] ?? null, "$col : session fermée, page rechargée");
            $this->assertSame(200, $this->request('GET', '/espace/connexion')->status());
            $this->assertSame(302, $this->request('GET', '/espace')->status(), "$col : session fermée");
            $this->assertNull($this->loggedAccount());
            $this->assertRefused($this->espaceLogin($number), $col);
            $this->assertRefused($this->espaceLogin("$col@x.test"), $col);
        }
    }

    public function test_read_only_member_gets_403_on_write_routes(): void
    {
        $this->app();
        $router = $this->services['router'];
        $router->post('/espace/sonde-ecriture', static fn (): Response => new Response('écrit'), [$this->services['require_member'], $this->services['require_member_write']]);
        $writes = array_filter($router->routes(), static fn (array $r): bool => (bool) array_filter($r['middleware'], static fn ($m) => $m instanceof RequireMemberWrite));
        $this->assertNotEmpty($writes);
        foreach ($writes as $r) {
            $this->assertTrue(str_starts_with($r['pattern'], '/espace'), $r['pattern']);
            $mw = $r['middleware'];
            $member = array_search(true, array_map(static fn ($m) => $m instanceof RequireMember, $mw), true);
            $write = array_search(true, array_map(static fn ($m) => $m instanceof RequireMemberWrite, $mw), true);
            $this->assertNotFalse($member, "{$r['pattern']} : RequireMember manquant");
            $this->assertLessThan($write, $member, "{$r['pattern']} : RequireMember doit précéder RequireMemberWrite");
        }
        $s = $this->memberWithAccount(['status' => 'suspendu']);
        $this->espaceLogin($this->memberNumber($s['member']));
        foreach ($writes as $r) {
            $path = preg_replace('/\{\w+(?::(?:[^{}]|\{\d+(?:,\d*)?\})+)?\}/', '1', $r['pattern']);
            $res = $r['method'] === 'GET' ? $this->request('GET', $path) : $this->espacePost($path);
            $this->assertSame(403, $res->status(), $r['pattern']);
            $this->assertStringContainsString('Votre accès est en consultation seule. Contactez la Mutuelle.', $res->body());
            $this->assertStringContainsString('Espace adhérent', $res->body());
        }
        // Accès complet : la même route écrit.
        $this->forgetApp();
        $this->app();
        $this->services['router']->post('/espace/sonde-ecriture', static fn (): Response => new Response('écrit'), [$this->services['require_member'], $this->services['require_member_write']]);
        $ok = $this->memberWithAccount();
        $this->espaceLogin($this->memberNumber($ok['member']));
        $this->assertSame('écrit', $this->espacePost('/espace/sonde-ecriture')->body());
    }

    public function test_espace_pages_are_no_store_and_noindex(): void
    {
        $ids = $this->memberWithAccount();
        $responses = ['GET /espace/connexion' => $this->request('GET', '/espace/connexion'), 'GET /espace (invité)' => $this->request('GET', '/espace'),
            'GET /espace/inconnu' => $this->request('GET', '/espace/inconnu'), 'POST sans jeton' => $this->request('POST', '/espace/connexion', ['_csrf' => 'faux'])];
        $responses['POST connexion'] = $this->espaceLogin($this->memberNumber($ids['member']));
        $responses['GET /espace'] = $this->request('GET', '/espace');
        foreach ($responses as $case => $res) {
            $this->assertSame('private, no-store', $res->headers()['Cache-Control'] ?? null, $case);
            $this->assertSame('noindex', $res->headers()['X-Robots-Tag'] ?? null, $case);
        }
        $this->assertSame(419, $responses['POST sans jeton']->status());
        $this->assertStringContainsString('href="/espace/connexion"', $responses['POST sans jeton']->body());
        $this->assertSame(LoginController::safeReturn('/espace/x'), '/espace/x');
    }
}
