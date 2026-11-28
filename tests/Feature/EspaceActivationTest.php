<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Response;
use App\Modules\Espace\MemberAccountService;
use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

final class EspaceActivationTest extends AdminTestCase
{
    use EspaceFixtures;

    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
        $this->db->query("UPDATE settings SET value = '14' WHERE `group` = 'espace' AND `key` = 'activation_code_days'");
        $this->manager = $this->createUser('gest-' . bin2hex(random_bytes(3)) . '@x.test', ['gest_adherents']);
    }

    /** @return array{member: int, account: int, code: string, number: string} */
    private function pending(array $member = []): array
    {
        $id = $this->memberForAccess($member);
        $created = $this->accounts()->create($id, 'lettre', $this->manager, null);
        return ['member' => $id, 'account' => $created['account_id'], 'code' => $created['code'], 'number' => $this->memberNumber($id)];
    }

    private function assertNeutral(Response $res, string $case): void
    {
        $this->assertSame(200, $res->status(), $case);
        $this->assertStringContainsString(e(MemberAccountService::FAILED), $res->body(), $case);
        $this->assertNull($this->loggedAccount(), $case);
    }

    private function accountStatus(int $account): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM member_accounts WHERE id = ?', [$account]);
    }

    public function test_activation_with_printed_letter_code_only_no_email(): void
    {
        $p = $this->pending(); // fiche sans e-mail (C37)
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/D', $p['code']);
        $this->assertSame(200, $this->request('GET', '/espace/activer')->status());
        $res = $this->activateVia(strtolower($p['number']), strtolower(str_replace('-', ' ', $p['code']))); // casse et séparateurs libres
        $this->assertSame(302, $res->status());
        $this->assertSame('/espace', $res->headers()['Location']);
        $this->assertSame($p['account'], $this->loggedAccount());
        $row = $this->db->fetch('SELECT status, activated_at, password FROM member_accounts WHERE id = ?', [$p['account']]);
        $this->assertSame('actif', $row['status']);
        $this->assertNotNull($row['activated_at']);
        $this->assertTrue(password_verify(self::NEW_MEMBER_PASSWORD, (string) $row['password']));
        $this->assertCount(0, $this->mails());
        $audit = $this->db->fetch("SELECT * FROM audit_logs WHERE action = 'espace.activated' ORDER BY id DESC LIMIT 1");
        $this->assertNull($audit['user_id']);
        $this->assertSame((string) $p['account'], (string) $audit['auditable_id']);
        $this->forgetApp();
        $this->assertSame(302, $this->espaceLogin($p['number'], self::NEW_MEMBER_PASSWORD)->status(), 'connexion avec le mot de passe choisi');
    }

    public function test_expired_code_refused(): void
    {
        $p = $this->pending();
        $this->db->query('UPDATE member_activation_codes SET expires_at = ? WHERE member_account_id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $p['account']]);
        $this->assertNeutral($this->activateVia($p['number'], $p['code']), 'expiré');
        $this->assertSame('en_attente', $this->accountStatus($p['account']));
    }

    public function test_used_code_refused_on_second_use(): void
    {
        $p = $this->pending();
        $this->assertSame(302, $this->activateVia($p['number'], $p['code'])->status());
        $this->forgetApp();
        $this->assertNeutral($this->activateVia($p['number'], $p['code'], self::BIRTH_DATE, 'une autre phrase de passe 77'), 'déjà utilisé');
        $this->assertTrue(password_verify(self::NEW_MEMBER_PASSWORD, (string) $this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$p['account']])));
    }

    public function test_code_of_another_account_refused(): void
    {
        $a = $this->pending();
        $b = $this->pending();
        $this->assertNeutral($this->activateVia($a['number'], $b['code']), 'code de B pour A');
        $this->assertSame(['en_attente', 'en_attente'], [$this->accountStatus($a['account']), $this->accountStatus($b['account'])]);
        $this->assertSame(302, $this->activateVia($b['number'], $b['code'])->status(), 'le code de B reste valable pour B');
    }

    public function test_wrong_birth_date_refused_with_same_message(): void
    {
        $p = $this->pending(['phone' => '0755443322']);
        $since = (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
        $this->assertNeutral($this->activateVia($p['number'], $p['code'], '1980-05-18'), 'date fausse');
        $this->assertNeutral($this->activateVia($p['number'], $p['code'], '17/05/1980'), 'date mal formée');
        $this->assertNeutral($this->activateVia($p['number'], $p['code'], ''), 'date vide');
        $this->assertNeutral($this->activateVia('INCONNU-' . getmypid(), $p['code']), 'identifiant inconnu');
        $this->assertNeutral($this->activateVia($p['number'], 'ABCD-EFGH-JKMN'), 'code faux');
        $q = $this->pending();
        $this->accounts()->setDisabled($q['member'], true, $this->manager, null);
        $this->assertNeutral($this->activateVia($q['number'], $q['code']), 'compte désactivé');
        $r = $this->pending();
        $this->db->query("UPDATE members SET status = 'decede' WHERE id = ?", [$r['member']]);
        $this->assertNeutral($this->activateVia($r['number'], $r['code']), 'membre décédé');
        // Aucune trace de l'identifiant saisi ni de la date dans l'audit des échecs (ils ne sont pas journalisés).
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action LIKE 'espace.activat%'", [$since]));
    }

    public function test_activation_throttled(): void
    {
        $p = $this->pending(['phone' => '0766554433']);
        foreach (['0766554433', '+225 07 66 55 44 33', '07.66.55.44.33', '00225 0766554433', '07-66-55-44-33'] as $form) {
            $this->assertNeutral($this->activateVia($form, 'ABCD-EFGH-JKMN'), $form);
        }
        $blocked = $this->activateVia('0766554433', $p['code']);
        $this->assertStringContainsString(MemberAccountService::BLOCKED, $blocked->body(), 'bon code refusé une fois la limite atteinte');
        $this->assertSame('en_attente', $this->accountStatus($p['account']));
        $this->activateVia('+225 0766554433', $p['code']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'espace.activation_blocked'"), 'blocage journalisé une fois');
        $this->assertSame(5, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email = 'espace-activation:0766554433' AND succeeded = 0"));
        // La connexion a sa propre clé : la limite d'activation ne la bloque pas.
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM login_attempts WHERE email LIKE 'espace:%'"));
        $this->db->query('UPDATE login_attempts SET created_at = ?', [gmdate('Y-m-d H:i:s', time() - 901)]);
        $this->assertSame(302, $this->activateVia('0766554433', $p['code'])->status(), 'après la fenêtre');
    }

    public function test_two_simultaneous_activations_with_same_code_succeed_once(): void
    {
        $p = $this->pending();
        $svc = $this->accounts();
        $output = $this->race(fn () => $this->assertSame($p['account'], $svc->activate($p['number'], self::BIRTH_DATE, $p['code'],
            self::NEW_MEMBER_PASSWORD, self::NEW_MEMBER_PASSWORD, '10.0.0.1', time())),
            ['activate', $p['number'], self::BIRTH_DATE, $p['code'], 'phrase du second processus', '10.0.0.2']);
        $this->assertSame(MemberAccountService::FAILED, $output);
        $this->assertTrue(password_verify(self::NEW_MEMBER_PASSWORD, (string) $this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$p['account']])));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'espace.activated' AND auditable_id = ?", [$p['account']]));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT session_version FROM member_accounts WHERE id = ?', [$p['account']]));
    }

    public function test_activation_days_setting_applied(): void
    {
        foreach (['3' => 3, '30' => 30, '"abc"' => 14, '0' => 14, 'null' => 14] as $value => $days) {
            $this->db->query("UPDATE settings SET value = ? WHERE `group` = 'espace' AND `key` = 'activation_code_days'", [$value === 'null' ? null : $value]);
            $before = time();
            $p = $this->pending();
            $expires = strtotime($this->db->fetchValue('SELECT expires_at FROM member_activation_codes WHERE member_account_id = ?', [$p['account']]) . ' UTC');
            $this->assertEqualsWithDelta($before + $days * 86400, $expires, 5, "valeur $value");
        }
        // Un code de 3 jours est refusé au quatrième.
        $this->db->query("UPDATE settings SET value = '3' WHERE `group` = 'espace' AND `key` = 'activation_code_days'");
        $p = $this->pending();
        $this->overrides = ['clock' => static fn (): int => time() + 3 * 86400 + 60];
        $this->forgetApp();
        $this->assertNeutral($this->activateVia($p['number'], $p['code']), 'après 3 jours');
    }

    public function test_activation_refuses_disabled_account_even_with_open_code(): void
    {
        $p = $this->pending();
        // Défense en profondeur : la désactivation ferme les codes, mais un code resté ouvert ne doit pas suffire.
        $this->db->query("UPDATE member_accounts SET status = 'desactive' WHERE id = ?", [$p['account']]);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_activation_codes WHERE member_account_id = ? AND used_at IS NULL', [$p['account']]));
        $this->assertNeutral($this->activateVia($p['number'], $p['code']), 'compte désactivé, code ouvert');
        $this->assertSame('desactive', $this->accountStatus($p['account']));
        $this->assertNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$p['account']]));
    }

    public function test_manager_reset_during_activation_with_old_code_succeeds_once(): void
    {
        $p = $this->pending();
        $this->assertSame(302, $this->activateVia($p['number'], $p['code'])->status());
        $old = (string) $this->accounts()->resetByManager($p['member'], 'lettre', true, $this->manager, null);
        // Le gestionnaire délivre un nouveau code pendant que l'adhérent saisit l'ancien : l'ancien doit être refusé.
        $new = '';
        $output = $this->race(function () use ($p, &$new): void {
            $new = (string) $this->accounts()->resetByManager($p['member'], 'lettre', true, $this->manager, null);
        }, ['activate', $p['number'], self::BIRTH_DATE, $old, 'phrase du second processus', '10.0.0.3']);
        $this->assertSame(MemberAccountService::FAILED, $output);
        $this->assertNull($this->db->fetchValue('SELECT password FROM member_accounts WHERE id = ?', [$p['account']]), 'aucun mot de passe posé par l\'ancien code');
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'espace.password_reset' AND auditable_id = ?", [$p['account']]));
        $this->forgetApp();
        $this->assertSame(302, $this->activateVia($p['number'], $new, self::BIRTH_DATE, 'phrase après la course 42')->status(), 'le nouveau code reste valable');
    }
}
