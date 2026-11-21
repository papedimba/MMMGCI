<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Http\Response;
use PDOException;
use App\Modules\Auth\PasswordReset;
use Tests\Support\AdminTestCase;

final class PasswordResetTest extends AdminTestCase
{
    private const NEUTRAL = "Si un compte actif correspond à cette adresse, un e-mail contenant un lien de réinitialisation vient d'être envoyé. Le lien est valable 1 heure.";
    private const INVALID = "Ce lien n'est plus valable : demandez un nouveau lien de réinitialisation.";
    private const NEW_PASSWORD = 'nouvelle phrase bien longue 2027';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
        $this->db->query('DELETE FROM password_resets');
    }

    private function forgot(string $email): Response
    {
        return $this->request('POST', '/admin/mot-de-passe-oublie', ['email' => $email]);
    }

    /** @return list<array{to:string,subject:string,text:string}> */
    private function mails(): array
    {
        $this->app();
        return array_map(static fn (array $m): array => ['to' => $m['to'], 'subject' => $m['subject'], 'text' => base64_decode(str_replace("\r\n", '', $m['body']))], $this->mail->messages);
    }

    private function tokenFromLastMail(): string
    {
        $mails = $this->mails();
        $this->assertNotEmpty($mails);
        $this->assertMatchesRegularExpression('#/admin/reinitialiser/([a-f0-9]{64})#', end($mails)['text']);
        preg_match('#/admin/reinitialiser/([a-f0-9]{64})#', end($mails)['text'], $m);
        return $m[1];
    }

    private function reset(string $token, string $password = self::NEW_PASSWORD): Response
    {
        return $this->request('POST', '/admin/reinitialiser/' . $token, ['password' => $password, 'password_confirmation' => $password]);
    }

    private function passwordHash(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT password FROM users WHERE id = ?', [$id]);
    }

    public function test_forgot_password_answers_identically_for_unknown_email(): void
    {
        $this->createUser('connu@x.test', []);
        $known = $this->forgot('connu@x.test');
        $unknown = $this->forgot('inconnu@x.test');

        $this->assertSame(200, $known->status());
        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->body(), $unknown->body());
        $this->assertStringContainsString(e(self::NEUTRAL), $unknown->body());
        $this->assertCount(1, $this->mails());
        $this->assertSame('connu@x.test', $this->mails()[0]['to']);
    }

    public function test_reset_email_contains_working_link(): void
    {
        $id = $this->createUser('lien@x.test', []);
        $this->forgot('  LIEN@x.test ');

        $mail = $this->mails()[0];
        $this->assertSame('lien@x.test', $mail['to']);
        $this->assertSame(mb_encode_mimeheader('Réinitialisation de votre mot de passe — MMMGCI', 'UTF-8', 'B', "\r\n"), $mail['subject']);
        $this->assertStringContainsString('Utilisateur lien', $mail['text']);
        $this->assertStringContainsString("Ce lien est valable 1 heure et ne peut servir qu'une fois.", $mail['text']);
        $this->assertStringContainsString(rtrim((string) self::config()->get('APP_URL'), '/') . '/admin/reinitialiser/', $mail['text']);
        $token = $this->tokenFromLastMail();

        $page = $this->request('GET', '/admin/reinitialiser/' . $token);
        $this->assertSame(200, $page->status());
        $this->assertSame('no-referrer', $page->headers()['Referrer-Policy']);
        $this->assertSame('no-store', $page->headers()['Cache-Control']);
        $this->assertStringContainsString('name="password"', $page->body());

        $res = $this->reset($token);
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/connexion', $res->headers()['Location']);
        $this->assertTrue(password_verify(self::NEW_PASSWORD, $this->passwordHash($id)));
        $this->assertStringContainsString('Votre mot de passe a été réinitialisé', $this->request('GET', '/admin/connexion')->body());
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.password_reset' AND user_id = ?", [$id]));
    }

    public function test_fourth_request_in_an_hour_sends_no_email(): void
    {
        $this->createUser('quatre@x.test', []);
        for ($i = 0; $i < 4; $i++) {
            $this->assertStringContainsString(e(self::NEUTRAL), $this->forgot('quatre@x.test')->body());
        }
        $this->assertCount(3, $this->mails());
    }

    public function test_throttled_ip_gets_neutral_page_and_no_email(): void
    {
        $this->createUser('limite@x.test', []);
        for ($i = 0; $i < 20; $i++) {
            $this->forgot("inconnu$i@x.test");
        }
        $this->assertStringContainsString(e(self::NEUTRAL), $this->forgot('limite@x.test')->body());
        $this->assertCount(0, $this->mails());
    }

    public function test_token_cannot_be_reused(): void
    {
        $id = $this->createUser('reuse@x.test', []);
        $this->forgot('reuse@x.test');
        $token = $this->tokenFromLastMail();

        $this->assertSame(302, $this->reset($token)->status());
        $hash = $this->passwordHash($id);

        $again = $this->reset($token, 'autre phrase tout aussi longue');
        $this->assertSame(200, $again->status());
        $this->assertStringContainsString(e(self::INVALID), $again->body());
        $this->assertSame($hash, $this->passwordHash($id));
        $this->assertStringContainsString(e(self::INVALID), $this->request('GET', '/admin/reinitialiser/' . $token)->body());
    }

    public function test_expired_token_is_refused(): void
    {
        $id = $this->createUser('expire@x.test', []);
        $token = (new PasswordReset($this->db))->issue($id, time() - 3601);
        $hash = $this->passwordHash($id);

        $this->assertStringContainsString(e(self::INVALID), $this->request('GET', '/admin/reinitialiser/' . $token)->body());
        $this->assertStringContainsString(e(self::INVALID), $this->reset($token)->body());
        $this->assertSame($hash, $this->passwordHash($id));
    }

    public function test_token_refused_if_user_deactivated_meanwhile(): void
    {
        $id = $this->createUser('desactive@x.test', []);
        $this->forgot('desactive@x.test');
        $token = $this->tokenFromLastMail();
        $this->db->update('users', ['is_active' => 0], ['id' => $id]);
        $hash = $this->passwordHash($id);

        $this->assertStringContainsString(e(self::INVALID), $this->reset($token)->body());
        $this->assertSame($hash, $this->passwordHash($id));
    }

    public function test_reset_logs_out_other_sessions(): void
    {
        $id = $this->createUser('autres@x.test', []);
        $this->actingAs($id);
        $this->assertSame(200, $this->request('GET', '/admin')->status());
        $token = (new PasswordReset($this->db))->issue($id, time());

        $this->assertSame(302, $this->reset($token)->status());

        $this->assertSame(2, (int) $this->db->fetchValue('SELECT session_version FROM users WHERE id = ?', [$id]));
        $res = $this->request('GET', '/admin');
        $this->assertSame(302, $res->status());
        $this->assertStringStartsWith('/admin/connexion', $res->headers()['Location']);
    }

    public function test_weak_new_password_is_refused_with_policy_message(): void
    {
        $id = $this->createUser('faible@x.test', []);
        $this->forgot('faible@x.test');
        $token = $this->tokenFromLastMail();
        $hash = $this->passwordHash($id);

        $res = $this->reset($token, 'court');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString(e('Mot de passe trop court : utilisez au moins 12 caractères'), $res->body());
        $this->assertSame($hash, $this->passwordHash($id));
        $this->assertSame(302, $this->reset($token)->status(), 'le jeton reste utilisable après un refus de la politique');
    }

    public function test_change_password_requires_current_password(): void
    {
        $id = $this->createUser('change@x.test', []);
        $this->actingAs($id);
        $hash = $this->passwordHash($id);
        $this->assertSame(200, $this->request('GET', '/admin/mon-compte/mot-de-passe')->status());

        $wrong = $this->request('POST', '/admin/mon-compte/mot-de-passe', ['current_password' => 'pas le bon mot de passe', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        $this->assertSame(200, $wrong->status());
        $this->assertStringContainsString(e('Mot de passe actuel incorrect'), $wrong->body());
        $this->assertSame($hash, $this->passwordHash($id));

        $ok = $this->request('POST', '/admin/mon-compte/mot-de-passe', ['current_password' => 'phrase secrète longue 2026', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        $this->assertSame(302, $ok->status());
        $this->assertTrue(password_verify(self::NEW_PASSWORD, $this->passwordHash($id)));
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT session_version FROM users WHERE id = ?', [$id]));
        $this->assertSame(200, $this->request('GET', '/admin')->status(), 'la session courante reste valide');
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.password_changed' AND user_id = ?", [$id]));
    }

    public function test_token_never_written_to_audit_log(): void
    {
        $id = $this->createUser('audit@x.test', []);
        $this->forgot('audit@x.test');
        $token = $this->tokenFromLastMail();
        $this->reset($token);

        $rows = json_encode($this->db->fetchAll('SELECT * FROM audit_logs WHERE user_id = ?', [$id]));
        $this->assertStringContainsString('auth.password_reset', $rows);
        $this->assertStringNotContainsString($token, $rows);
        $this->assertStringNotContainsString(hash('sha256', $token), $rows);
    }

    public function test_request_path_defers_all_account_work_for_known_and_unknown_addresses(): void
    {
        $jobs = [];
        $this->overrides['defer'] = static function (callable $job) use (&$jobs): void { $jobs[] = $job; };
        $this->createUser('differe@x.test', []);

        $known = $this->forgot('differe@x.test');
        $unknown = $this->forgot('inconnu-differe@x.test');

        $this->assertSame($known->body(), $unknown->body());
        $this->assertCount(2, $jobs, 'un travail différé par demande, adresse connue ou non');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM password_resets'));
        $this->assertCount(0, $this->mails());
        foreach ($jobs as $job) {
            $job();
        }
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM password_resets'));
        $this->assertCount(1, $this->mails());
    }

    public function test_wrong_current_password_is_throttled_and_audited(): void
    {
        $id = $this->createUser('force@x.test', []);
        $this->actingAs($id);
        $hash = $this->passwordHash($id);
        $post = fn (string $current) => $this->request('POST', '/admin/mon-compte/mot-de-passe', ['current_password' => $current, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        for ($i = 0; $i < 5; $i++) {
            $this->assertStringContainsString(e('Mot de passe actuel incorrect'), $post('mauvais essai numéro ' . $i)->body());
        }

        $blocked = $post('phrase secrète longue 2026');
        $this->assertStringContainsString(e('Trop de tentatives : réessayez dans 15 minutes.'), $blocked->body());
        $this->assertSame($hash, $this->passwordHash($id));
        $this->assertSame(5, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'auth.password_change_failed' AND user_id = ?", [$id]));
    }

    public function test_change_password_invalidates_pending_reset_tokens(): void
    {
        $id = $this->createUser('jetons@x.test', []);
        $token = (new PasswordReset($this->db))->issue($id, time());
        $this->actingAs($id);

        $ok = $this->request('POST', '/admin/mon-compte/mot-de-passe', ['current_password' => 'phrase secrète longue 2026', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
        $this->assertSame(302, $ok->status());
        $this->assertNull((new PasswordReset($this->db))->check($token, time()));
    }

    /** @param array<string,string> $env */
    private function assertForgotLogsAndSendsNothing(string $email, array $env, string $expectedLog): void
    {
        $this->env = $env;
        $this->createUser($email, []);
        $log = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $log);
        try {
            $res = $this->forgot($email);
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertStringContainsString(e(self::NEUTRAL), $res->body());
        $this->assertCount(0, $this->mails());
        $this->assertStringContainsString($expectedLog, (string) file_get_contents($log));
        unlink($log);
    }

    public function test_relative_app_url_sends_nothing_and_logs_a_clear_error(): void
    {
        $this->assertForgotLogsAndSendsNothing('sansurl@x.test', ['APP_URL' => ''], 'APP_URL doit être une adresse absolue');
    }

    public function test_plain_http_app_url_outside_local_sends_nothing(): void
    {
        $this->assertForgotLogsAndSendsNothing('http@x.test', ['APP_ENV' => 'staging', 'APP_URL' => 'http://mmmgci.example'], 'APP_URL doit commencer par https://');
    }

    public function test_consume_invalidates_the_users_other_tokens(): void
    {
        $id = $this->createUser('deux@x.test', []);
        $resets = new PasswordReset($this->db);
        [$first, $second] = [$resets->issue($id, time()), $resets->issue($id, time())];

        $this->assertSame($id, $resets->consume($first, time()));
        $this->assertNull($resets->check($second, time()));
        $this->assertNull($resets->consume($second, time()));
    }

    public function test_consume_locks_the_account_before_the_tokens_and_has_a_single_winner(): void
    {
        $id = $this->createUser('ordre@x.test', []);
        $token = (new PasswordReset($this->db))->issue($id, time());
        $other = Database::connect(self::config(), test: true);
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            // Comme UserService::setActive : compte verrouillé d'abord, puis jetons.
            $probe = $this->db->transaction(function () use ($id, $token, $other): ?string {
                $this->db->fetchValue('SELECT id FROM users WHERE id = ? FOR UPDATE', [$id]);
                return $other->transaction(function () use ($id, $token, $other): ?string {
                    try {
                        (new PasswordReset($other))->consume($token, time());
                        $this->fail('consume() aurait dû attendre le verrou du compte.');
                    } catch (PDOException $e) {
                        $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
                    }
                    // consume() en attente ne doit tenir aucun verrou sur les jetons : sinon interblocage avec setActive.
                    try {
                        $this->db->query('UPDATE password_resets SET used_at = used_at WHERE user_id = ?', [$id]);
                        return null;
                    } catch (PDOException $e) {
                        return $e->getMessage();
                    }
                });
            });
        } finally {
            $this->db->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        $this->assertNull($probe);

        // Un seul gagnant : la consommation tenue par une transaction fait attendre l'autre, qui ne trouve ensuite plus rien.
        $this->db->transaction(function () use ($id, $token, $other): void {
            $this->assertSame($id, (new PasswordReset($this->db))->consume($token, time()));
            try {
                (new PasswordReset($other))->consume($token, time());
                $this->fail('La seconde consommation aurait dû attendre.');
            } catch (PDOException $e) {
                $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
            }
        });
        $this->assertNull((new PasswordReset($other))->consume($token, time()));
    }

    public function test_token_expires_exactly_after_one_hour(): void
    {
        $id = $this->createUser('borne@x.test', []);
        $resets = new PasswordReset($this->db);
        $issuedAt = time() - 7200;
        $token = $resets->issue($id, $issuedAt);

        $this->assertSame($id, $resets->check($token, $issuedAt + 3599));
        $this->assertNull($resets->check($token, $issuedAt + 3600));
    }
}
