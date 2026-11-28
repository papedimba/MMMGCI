<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\NotFound;
use App\Modules\Auth\AuthMaintenance;
use App\Modules\Espace\AccountRuleViolation;
use App\Modules\Espace\MemberAccountService;
use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

final class EspacePasswordTest extends AdminTestCase
{
    use EspaceFixtures;

    private const OTHER_PASSWORD = 'pirogue rouge sur la lagune';

    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
        $this->manager = $this->createUser('gest-' . bin2hex(random_bytes(3)) . '@x.test', ['gest_adherents']);
    }

    /** Compte actif avec MEMBER_PASSWORD. @return array{member: int, account: int, number: string} */
    private function active(array $member = []): array
    {
        $ids = $this->memberWithAccount($member + ['birth_date' => self::BIRTH_DATE, 'last_name' => 'Kouadio', 'first_names' => 'Aya']);
        return $ids + ['number' => $this->memberNumber($ids['member'])];
    }

    private function forgot(string $identifier): string
    {
        return $this->espacePost('/espace/mot-de-passe-oublie', ['identifiant' => $identifier])->body();
    }

    private function tokenFromLastMail(): string
    {
        $mails = $this->mails();
        $this->assertNotEmpty($mails);
        $this->assertMatchesRegularExpression('#/espace/reinitialiser/([a-f0-9]{64})\s#', end($mails)['text']);
        preg_match('#/espace/reinitialiser/([a-f0-9]{64})#', end($mails)['text'], $m);
        return $m[1];
    }

    private function resetVia(string $token, string $password = self::OTHER_PASSWORD): \App\Core\Http\Response
    {
        return $this->espacePost("/espace/reinitialiser/$token", ['password' => $password, 'password_confirmation' => $password]);
    }

    private function version(int $account): int
    {
        return (int) $this->db->fetchValue('SELECT session_version FROM member_accounts WHERE id = ?', [$account]);
    }

    /** Session courante ramenée à une version donnée (une autre session ouverte avant le changement). */
    private function sessionAt(int $account, int $version): void
    {
        $this->app();
        $this->services['member_session']->set('espace.account_id', $account);
        $this->services['member_session']->set('espace.session_version', $version);
    }

    private function assertLoginWorks(string $identifier, string $password, bool $works, string $case): void
    {
        $this->forgetApp();
        $this->assertSame($works ? 302 : 200, $this->espaceLogin($identifier, $password)->status(), $case);
        $this->forgetApp();
    }

    public function test_forgot_same_response_for_known_unknown_and_throttled(): void
    {
        $known = $this->active(['email' => 'connu.esp@x.test']);
        $this->active(['email' => null, 'phone' => '0712121212']);
        $this->assertStringContainsString('action="/espace/mot-de-passe-oublie"', $this->request('GET', '/espace/mot-de-passe-oublie')->body());
        $bodies = [
            'connu' => $this->forgot('connu.esp@x.test'),
            'inconnu' => $this->forgot('inconnu.esp@x.test'),
            'sans e-mail' => $this->forgot('0712121212'),
            'saisie invalide' => $this->forgot("é\u{00A0}x"),
        ];
        $this->assertCount(1, $this->mails());
        $this->assertSame('connu.esp@x.test', $this->mails()[0]['to']);
        foreach (range(1, 6) as $i) { // 3 jetons par heure au plus, puis limite de 5 demandes par clé et IP (la sixième ne fait rien)
            $bodies["répété $i"] = $this->forgot($known['number']);
        }
        $this->assertCount(3, $this->mails());
        $this->assertSame(5, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE succeeded = 0 AND email = ?', ['espace-oubli:' . strtolower($known['number'])]));
        foreach ($bodies as $case => $body) {
            $this->assertSame($bodies['connu'], $body, $case);
        }
        $this->assertStringContainsString('Le lien est valable 1 heure', $bodies['connu']);
    }

    public function test_forgot_keeps_old_password_until_link_used(): void
    {
        $a = $this->active(['email' => 'garde@x.test']);
        $this->forgot('garde@x.test');
        $token = $this->tokenFromLastMail();
        $this->assertLoginWorks($a['number'], self::MEMBER_PASSWORD, true, 'ancien mot de passe toujours valable');
        $this->assertSame(302, $this->resetVia($token)->status());
        $this->assertLoginWorks($a['number'], self::MEMBER_PASSWORD, false, 'ancien mot de passe refusé après le lien');
        $this->assertLoginWorks($a['number'], self::OTHER_PASSWORD, true, 'nouveau mot de passe');
    }

    public function test_reset_link_single_use_one_hour_and_closes_sessions(): void
    {
        $a = $this->active(['email' => 'lien@x.test']);
        $before = $this->version($a['account']);
        $this->forgot('lien@x.test');
        $token = $this->tokenFromLastMail();
        $row = $this->db->fetch("SELECT created_at, expires_at, delivery, issued_by FROM member_activation_codes WHERE member_account_id = ? AND purpose = 'oubli'", [$a['account']]);
        $this->assertSame(3600, strtotime($row['expires_at']) - strtotime($row['created_at']));
        $this->assertSame(['email', null], [$row['delivery'], $row['issued_by']]);

        $page = $this->request('GET', "/espace/reinitialiser/$token");
        $this->assertSame(200, $page->status());
        $this->assertSame('no-referrer', $page->headers()['Referrer-Policy']);
        $this->assertStringContainsString('no-store', $page->headers()['Cache-Control']);
        $this->assertStringNotContainsString(e(MemberAccountService::INVALID_LINK), $page->body());

        $res = $this->resetVia($token);
        $this->assertSame(302, $res->status());
        $this->assertSame($a['account'], $this->loggedAccount());
        $this->assertSame($before + 1, $this->version($a['account']));
        $this->sessionAt($a['account'], $before);
        $this->assertSame(302, $this->request('GET', '/espace')->status(), 'session ouverte avant la réinitialisation fermée');

        $this->forgetApp();
        $again = $this->resetVia($token, 'encore une autre phrase 99');
        $this->assertStringContainsString(e(MemberAccountService::INVALID_LINK), $again->body(), 'lien à usage unique');
        $this->assertStringContainsString('/espace/mot-de-passe-oublie', $again->body());

        // Lien expiré au bout d'une heure.
        $this->forgot('lien@x.test');
        $late = $this->tokenFromLastMail();
        $this->db->query("UPDATE member_activation_codes SET expires_at = ? WHERE member_account_id = ? AND used_at IS NULL", [gmdate('Y-m-d H:i:s', time() - 1), $a['account']]);
        $this->assertStringContainsString(e(MemberAccountService::INVALID_LINK), $this->request('GET', "/espace/reinitialiser/$late")->body());
        $this->assertStringContainsString(e(MemberAccountService::INVALID_LINK), $this->resetVia($late)->body());
        $this->assertLoginWorks($a['number'], self::OTHER_PASSWORD, true, 'le lien expiré n\'a rien changé');
    }

    public function test_manager_reset_requires_identity_check_and_reset_permission(): void
    {
        $a = $this->active();
        $delegate = $this->createUser('delegue-' . bin2hex(random_bytes(3)) . '@x.test', ['delegue']); // gère les accès, sans le droit de réinitialiser
        foreach ([[$delegate, true, "Vous n'avez pas le droit d'effectuer cette opération sur les accès à l'Espace adhérent."],
            [$this->manager, false, "Confirmez avoir vérifié l'identité de l'adhérent (pièce d'identité présentée) avant de délivrer un code."]] as [$actor, $checked, $message]) {
            try {
                $this->accounts()->resetByManager($a['member'], 'lettre', $checked, $actor, null);
                $this->fail($message);
            } catch (AccountRuleViolation $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
        $this->assertLoginWorks($a['number'], self::MEMBER_PASSWORD, true, 'refus sans effet');
        $code = $this->accounts()->resetByManager($a['member'], 'lettre', true, $this->manager, '127.0.0.1');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/D', (string) $code);
        $audit = $this->db->fetch("SELECT * FROM audit_logs WHERE action = 'espace.reset_issued' ORDER BY id DESC LIMIT 1");
        $this->assertSame($this->manager, (int) $audit['user_id']);
        $this->assertSame((string) $a['account'], (string) $audit['auditable_id']);
        $new = json_decode((string) $audit['new_values'], true);
        $this->assertSame([true, 'reinitialisation', 'lettre'], [$new['identity_checked'], $new['purpose'], $new['delivery']]);
    }

    public function test_manager_reset_invalidates_old_password_and_sessions_immediately(): void
    {
        $a = $this->active();
        $this->assertSame(302, $this->espaceLogin($a['number'])->status());
        $before = $this->version($a['account']);
        $code = (string) $this->accounts()->resetByManager($a['member'], 'lettre', true, $this->manager, null);
        $this->assertSame($before + 1, $this->version($a['account']), 'toutes les sessions fermées (version)');
        $this->assertNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$a['account']]));
        $this->assertSame(302, $this->request('GET', '/espace')->status(), 'session ouverte fermée immédiatement');
        $this->assertLoginWorks($a['number'], self::MEMBER_PASSWORD, false, 'ancien mot de passe refusé immédiatement');
        // Le code se saisit sur le formulaire d'activation.
        $this->assertSame(302, $this->activateVia($a['number'], $code, self::BIRTH_DATE, self::OTHER_PASSWORD)->status());
        $this->assertSame($a['account'], $this->loggedAccount());
        $this->assertSame('espace.password_reset', $this->db->fetchValue("SELECT action FROM audit_logs WHERE auditable_type = 'member_account' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$a['account']]));
        $this->assertLoginWorks($a['number'], self::OTHER_PASSWORD, true, 'nouveau mot de passe');
    }

    public function test_manager_reset_outside_scope_is_404(): void
    {
        $n = bin2hex(random_bytes(3));
        [$zoneA, $zoneB] = [$this->db->insert('zones', ['name' => "PA $n", 'type' => 'region']), $this->db->insert('zones', ['name' => "PB $n", 'type' => 'region'])];
        $this->db->query('INSERT INTO roles (name, label) VALUES (?, ?)', ["reset_zone_$n", 'Réinitialisation en zone']);
        $this->db->query("INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = 'espace.accounts.reset'", ["reset_zone_$n"]);
        $zoneManager = $this->createUser("zone-$n@x.test", ["reset_zone_$n"]);
        $this->db->query('UPDATE users SET zone_id = ? WHERE id = ?', [$zoneA, $zoneManager]);
        $outside = $this->active(['zone_id' => $zoneB]);
        try {
            $this->accounts()->resetByManager($outside['member'], 'lettre', true, $zoneManager, null);
            $this->fail('404 attendu hors périmètre');
        } catch (NotFound) {
        }
        $this->assertNotNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$outside['account']]), 'rien de modifié');
        $inside = $this->active(['zone_id' => $zoneA]);
        $this->assertNotNull($this->accounts()->resetByManager($inside['member'], 'lettre', true, $zoneManager, null));
    }

    public function test_change_password_requires_current_closes_other_sessions_keeps_current(): void
    {
        $a = $this->active(['phone' => '0788776655', 'email' => 'compte.seul@x.test']);
        $this->active(['phone' => '0788776655']); // téléphone partagé : il n'ouvre aucun compte, il n'est pas proposé
        $this->assertSame(302, $this->espaceLogin($a['number'])->status());
        $page = $this->request('GET', '/espace/compte');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString(e($a['number']), $page->body());
        $this->assertStringContainsString('compte.seul@x.test', $page->body());
        $this->assertStringNotContainsString('0788776655', $page->body());
        $before = $this->version($a['account']);

        $wrong = $this->espacePost('/espace/compte', ['current_password' => 'pas le bon mot de passe', 'password' => self::OTHER_PASSWORD, 'password_confirmation' => self::OTHER_PASSWORD]);
        $this->assertStringContainsString(e(MemberAccountService::WRONG_CURRENT), $wrong->body());
        $this->assertSame($before, $this->version($a['account']));

        $ok = $this->espacePost('/espace/compte', ['current_password' => self::MEMBER_PASSWORD, 'password' => self::OTHER_PASSWORD, 'password_confirmation' => self::OTHER_PASSWORD]);
        $this->assertSame(302, $ok->status());
        $this->assertSame('/espace/compte', $ok->headers()['Location']);
        $this->assertSame($before + 1, $this->version($a['account']));
        $after = $this->request('GET', '/espace/compte');
        $this->assertSame(200, $after->status(), 'la session courante reste ouverte');
        $this->assertStringContainsString('Vos autres sessions ont été fermées.', $after->body());
        $this->sessionAt($a['account'], $before);
        $this->assertSame(302, $this->request('GET', '/espace')->status(), 'une autre session ouverte avant le changement est fermée');
        $this->assertLoginWorks($a['number'], self::OTHER_PASSWORD, true, 'nouveau mot de passe');

        // Limité comme la connexion (clé espace: + numéro) : 5 échecs puis blocage, même avec le bon mot de passe.
        $this->espaceLogin($a['number'], self::OTHER_PASSWORD);
        foreach (range(1, 5) as $i) {
            $this->espacePost('/espace/compte', ['current_password' => "mauvais $i", 'password' => self::NEW_MEMBER_PASSWORD, 'password_confirmation' => self::NEW_MEMBER_PASSWORD]);
        }
        $blocked = $this->espacePost('/espace/compte', ['current_password' => self::OTHER_PASSWORD, 'password' => self::NEW_MEMBER_PASSWORD, 'password_confirmation' => self::NEW_MEMBER_PASSWORD]);
        $this->assertStringContainsString(MemberAccountService::BLOCKED, $blocked->body());
    }

    public function test_password_policy_of_l1_applies(): void
    {
        $id = $this->memberForAccess(['email' => 'politique.esp@x.test']);
        $code = $this->accounts()->create($id, 'lettre', $this->manager, null)['code'];
        $number = $this->memberNumber($id);
        $cases = [
            'court' => ['court2026', 'Mot de passe trop court'],
            'courant' => ['motdepasse123', 'Mot de passe trop'],
            'nom' => ['kouadio kouadio 2026', 'Mot de passe trop facile à deviner'],
            'e-mail' => ['politique.esp et la mer', 'Mot de passe trop facile à deviner'],
        ];
        foreach ($cases as $case => [$password, $message]) {
            $body = $this->activateVia($number, $code, self::BIRTH_DATE, $password)->body();
            $this->assertStringContainsString(e($message), $body, $case);
            $this->assertNull($this->loggedAccount(), $case);
        }
        $mismatch = $this->espacePost('/espace/activer', ['identifiant' => $number, 'date_naissance' => self::BIRTH_DATE, 'code' => $code,
            'password' => self::NEW_MEMBER_PASSWORD, 'password_confirmation' => self::OTHER_PASSWORD]);
        $this->assertStringContainsString(e(MemberAccountService::MISMATCH), $mismatch->body());
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email LIKE 'espace-activation:%'"), 'un refus de politique ne compte pas comme un essai');
        $this->assertSame(302, $this->activateVia($number, $code)->status(), 'le code n\'a pas été consommé');
        $hash = (string) $this->db->fetchValue('SELECT password FROM member_accounts WHERE member_id = ?', [$id]);
        $this->assertStringStartsWith(defined('PASSWORD_ARGON2ID') ? '$argon2id$' : '$2y$', $hash, 'même hachage que L1');
    }

    public function test_audit_lines_have_no_identifier_code_or_personal_data(): void
    {
        $since = (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
        $member = $this->memberForAccess(['email' => 'trace.esp@x.test', 'phone' => '0733221100', 'last_name' => 'Tanoh', 'first_names' => 'Affoué']);
        $number = $this->memberNumber($member);
        $code = $this->accounts()->create($member, 'lettre', $this->manager, '127.0.0.1')['code'];
        $this->activateVia('0733221100', 'MAUV-AISC-ODE2');
        $this->activateVia('0733221100', $code);
        $this->forgetApp();
        $this->forgot('trace.esp@x.test');
        $token = $this->tokenFromLastMail();
        $this->resetVia($token);
        $this->espacePost('/espace/compte', ['current_password' => 'faux mot de passe', 'password' => self::NEW_MEMBER_PASSWORD, 'password_confirmation' => self::NEW_MEMBER_PASSWORD]);
        $this->espacePost('/espace/compte', ['current_password' => self::OTHER_PASSWORD, 'password' => self::NEW_MEMBER_PASSWORD, 'password_confirmation' => self::NEW_MEMBER_PASSWORD]);
        $reset = (string) $this->accounts()->resetByManager($member, 'lettre', true, $this->manager, null);
        $this->accounts()->setDisabled($member, true, $this->manager, null);
        foreach (range(1, 6) as $i) {
            $this->activateVia('trace.esp@x.test', $reset, '1999-01-01');
        }
        $rows = $this->db->fetchAll('SELECT * FROM audit_logs WHERE id > ? ORDER BY id', [$since]);
        $actions = array_column($rows, 'action');
        foreach (['espace.account_created', 'espace.code_issued', 'espace.activated', 'espace.reset_requested', 'espace.password_reset', 'espace.password_change_failed',
            'espace.password_changed', 'espace.reset_issued', 'espace.account_disabled', 'espace.activation_blocked'] as $expected) {
            $this->assertContains($expected, $actions);
        }
        $dump = json_encode($rows, JSON_UNESCAPED_UNICODE);
        foreach ([$number, strtolower($number), '0733221100', 'trace.esp', 'Tanoh', 'Affoué', self::BIRTH_DATE, '1999-01-01', $code, str_replace('-', '', $code),
            $reset, str_replace('-', '', $reset), $token, 'MAUV', self::OTHER_PASSWORD, self::NEW_MEMBER_PASSWORD] as $secret) {
            $this->assertStringNotContainsString($secret, $dump, $secret);
        }
        foreach ($rows as $row) {
            if ($row['user_id'] === null) {
                $this->assertSame('member_account', $row['auditable_type'], $row['action'] . ' : action de l\'adhérent, compte pour objet');
            }
        }
    }

    public function test_purge_removes_old_forgot_tokens_only(): void
    {
        $a = $this->active();
        $old = gmdate('Y-m-d H:i:s', time() - 90000);
        $future = gmdate('Y-m-d H:i:s', time() + 3600);
        $insert = fn (string $purpose, string $expires, ?string $used) => $this->db->insert('member_activation_codes', ['member_account_id' => $a['account'],
            'code_hash' => bin2hex(random_bytes(32)), 'purpose' => $purpose, 'delivery' => 'email', 'expires_at' => $expires, 'used_at' => $used, 'created_at' => $old]);
        $insert('oubli', $old, null);
        $insert('oubli', $future, $old);
        $keep = [$insert('oubli', $future, null), $insert('activation', $old, $old), $insert('reinitialisation', $old, null)];
        AuthMaintenance::purgeMemberForgotTokens($this->db, time());
        $this->assertSame($keep, array_map('intval', array_column($this->db->fetchAll('SELECT id FROM member_activation_codes WHERE member_account_id = ? ORDER BY id', [$a['account']]), 'id')));
    }

    public function test_two_simultaneous_reset_links_succeed_once(): void
    {
        $a = $this->active(['email' => 'course.lien@x.test']);
        $this->forgot('course.lien@x.test');
        $token = $this->tokenFromLastMail();
        $svc = $this->accounts();
        $output = $this->race(fn () => $this->assertSame($a['account'], $svc->resetWithToken($token, self::OTHER_PASSWORD, self::OTHER_PASSWORD, '10.0.0.1', time())),
            ['reset', $token, 'phrase du second processus', '10.0.0.2']);
        $this->assertSame(MemberAccountService::INVALID_LINK, $output);
        $this->assertTrue(password_verify(self::OTHER_PASSWORD, (string) $this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$a['account']])));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'espace.password_reset' AND auditable_id = ?", [$a['account']]));
    }
}
