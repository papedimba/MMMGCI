<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\NotFound;
use App\Modules\Espace\AccountRuleViolation;
use App\Modules\Espace\ActivationCode;
use App\Modules\Espace\MemberAccountService;
use Tests\Support\AdminTestCase;
use Tests\Support\EspaceFixtures;

final class MemberAccountServiceTest extends AdminTestCase
{
    use EspaceFixtures;

    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM login_attempts');
        $this->manager = $this->createUser('gest-' . bin2hex(random_bytes(3)) . '@x.test', ['gest_adherents']);
    }

    private function assertViolation(string $expected, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Refus attendu : $expected");
        } catch (AccountRuleViolation $e) {
            $this->assertSame($expected, $e->getMessage());
        }
    }

    public function test_code_and_token_never_stored_in_clear(): void
    {
        $member = $this->memberForAccess(['email' => 'clair@x.test']);
        $log = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $log);
        try {
            $code = $this->accounts()->create($member, 'lettre', $this->manager, '127.0.0.1')['code'];
            $this->assertSame(302, $this->activateVia('clair@x.test', $code)->status());
            $this->forgetApp();
            $this->espacePost('/espace/mot-de-passe-oublie', ['identifiant' => 'clair@x.test']);
            $mails = $this->mails();
            preg_match("#/espace/reinitialiser/([a-f0-9]{64})#", (string) end($mails)["text"], $m);
            $token = $m[1];
            error_log('trace de test sans secret');
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $account = (int) $this->db->fetchValue('SELECT id FROM member_accounts WHERE member_id = ?', [$member]);
        $rows = $this->db->fetchAll('SELECT * FROM member_activation_codes WHERE member_account_id = ? ORDER BY id', [$account]);
        $this->assertCount(2, $rows);
        $key = (string) self::config()->get('APP_KEY');
        $this->assertSame(ActivationCode::hash($code, $key), $rows[0]['code_hash']);
        $this->assertSame(hash_hmac('sha256', strtoupper($token), $key), $rows[1]['code_hash']);
        $haystacks = [json_encode($rows), json_encode($this->db->fetchAll('SELECT * FROM audit_logs')), (string) file_get_contents($log)];
        $this->assertStringContainsString('trace de test sans secret', $haystacks[2], 'le journal capturé est bien celui de l\'application');
        foreach ($haystacks as $i => $h) {
            foreach ([$code, ActivationCode::normalize($code), strtolower(ActivationCode::normalize($code)), $token, strtoupper($token)] as $secret) {
                $this->assertStringNotContainsString($secret, $h, "secret en clair dans la source $i");
            }
        }
        unlink($log);
    }

    public function test_new_code_closes_previous_open_codes(): void
    {
        $member = $this->memberForAccess();
        $first = $this->accounts()->create($member, 'lettre', $this->manager, null)['code'];
        $second = $this->accounts()->reissueActivation($member, 'lettre', $this->manager, null);
        $this->assertNotSame($first, $second);
        $rows = $this->db->fetchAll('SELECT used_at FROM member_activation_codes c JOIN member_accounts a ON a.id = c.member_account_id WHERE a.member_id = ? ORDER BY c.id', [$member]);
        $this->assertNotNull($rows[0]['used_at'], 'ancien code fermé');
        $this->assertNull($rows[1]['used_at']);
        $number = $this->memberNumber($member);
        $this->assertStringContainsString(e(MemberAccountService::FAILED), $this->activateVia($number, $first)->body());
        $this->assertSame(302, $this->activateVia($number, $second)->status());
        // Accès actif : plus de code d'activation à délivrer (la réinitialisation passe par le gestionnaire habilité).
        $this->assertViolation("Un nouveau code d'activation ne peut être délivré qu'à un accès en attente d'activation.",
            fn () => $this->accounts()->reissueActivation($member, 'lettre', $this->manager, null));
    }

    public function test_account_creation_requires_birth_date_scope_and_permission(): void
    {
        $this->assertViolation(MemberAccountService::NO_BIRTH_DATE,
            fn () => $this->accounts()->create($this->memberForAccess(['birth_date' => null]), 'lettre', $this->manager, null));

        $nobody = $this->createUser('sansdroit-' . bin2hex(random_bytes(3)) . '@x.test', ['gest_cms']);
        $this->assertViolation("Vous n'avez pas le droit d'effectuer cette opération sur les accès à l'Espace adhérent.",
            fn () => $this->accounts()->create($this->memberForAccess(), 'lettre', $nobody, null));

        $n = bin2hex(random_bytes(3));
        [$zoneA, $zoneB] = [$this->db->insert('zones', ['name' => "ZA $n", 'type' => 'region']), $this->db->insert('zones', ['name' => "ZB $n", 'type' => 'region'])];
        $delegate = $this->createUser("delegue-$n@x.test", ['delegue']);
        $this->db->query('UPDATE users SET zone_id = ? WHERE id = ?', [$zoneA, $delegate]);
        $outside = $this->memberForAccess(['zone_id' => $zoneB]);
        try {
            $this->accounts()->create($outside, 'lettre', $delegate, null);
            $this->fail('Hors périmètre : 404 attendu');
        } catch (NotFound) {
        }
        $this->assertNull($this->db->fetchValue('SELECT id FROM member_accounts WHERE member_id = ?', [$outside]));
        $inside = $this->memberForAccess(['zone_id' => $zoneA]);
        $created = $this->accounts()->create($inside, 'lettre', $delegate, '127.0.0.1');
        $this->assertSame(['en_attente', $delegate], array_values($this->db->fetch('SELECT status, created_by FROM member_accounts WHERE id = ?', [$created['account_id']])));
        $this->assertSame(['espace.account_created', 'espace.code_issued'], array_column($this->db->fetchAll(
            "SELECT action FROM audit_logs WHERE auditable_type = 'member_account' AND auditable_id = ? ORDER BY id", [$created['account_id']]), 'action'));

        $this->assertViolation("Cet adhérent a déjà un accès à l'Espace adhérent.", fn () => $this->accounts()->create($inside, 'lettre', $delegate, null));
        $this->assertViolation("Le statut de cet adhérent ne permet pas l'accès à l'Espace adhérent.",
            fn () => $this->accounts()->create($this->memberForAccess(['status' => 'radie']), 'lettre', $this->manager, null));
        $this->assertViolation("Le statut de cet adhérent ne permet pas l'accès à l'Espace adhérent.",
            fn () => $this->accounts()->create($this->memberForAccess(['anonymized_at' => '2026-01-01 00:00:00']), 'lettre', $this->manager, null));
        $deleted = $this->memberForAccess(['deleted_at' => '2026-01-01 00:00:00']);
        $this->expectException(NotFound::class);
        $this->accounts()->create($deleted, 'lettre', $this->manager, null);
    }

    public function test_email_delivery_only_when_address_and_mailer_ready(): void
    {
        $impossible = "Envoi par e-mail impossible (aucune adresse dans la fiche, ou envoi d'e-mails non configuré) : choisissez la lettre imprimée.";
        $this->assertViolation($impossible, fn () => $this->accounts()->create($this->memberForAccess(), 'email', $this->manager, null));
        $this->assertViolation('Choisissez la remise du code : par e-mail ou par lettre imprimée.',
            fn () => $this->accounts()->create($this->memberForAccess(), 'sms', $this->manager, null));

        $this->env = ['APP_URL' => ''];
        $this->forgetApp();
        $log = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $log);
        try {
            $this->assertViolation($impossible, fn () => $this->accounts()->create($this->memberForAccess(['email' => 'pas-de-config@x.test']), 'email', $this->manager, null));
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
        $this->assertCount(0, $this->mails());

        $this->env = [];
        $this->forgetApp();
        $member = $this->memberForAccess(['email' => 'avec-adresse@x.test']);
        $created = $this->accounts()->create($member, 'email', $this->manager, null);
        $this->assertArrayNotHasKey('code', $created, 'code jamais rendu pour un envoi par e-mail');
        $this->assertCount(1, $this->mails());
        $this->assertSame('avec-adresse@x.test', $this->mails()[0]['to']);
        $this->assertSame('email', $this->db->fetchValue('SELECT delivery FROM member_activation_codes WHERE member_account_id = ?', [$created['account_id']]));
        // Lettre : aucun e-mail, même si la fiche a une adresse.
        $this->accounts()->create($this->memberForAccess(['email' => 'lettre@x.test']), 'lettre', $this->manager, null);
        $this->assertCount(1, $this->mails());
    }

    public function test_activation_mail_contains_no_name_number_or_status(): void
    {
        $member = $this->memberForAccess(['email' => 'contenu@x.test', 'last_name' => 'Bléhoua', 'first_names' => 'Akissi Prisca', 'phone' => '0709080706']);
        $this->accounts()->create($member, 'email', $this->manager, null);
        $mail = $this->mails()[0];
        $this->assertMatchesRegularExpression('#https?://[^/\s]+/espace/activer\s#', $mail['text']);
        $this->assertMatchesRegularExpression('/\b[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}\b/', $mail['text']);
        foreach (['Bléhoua', 'Akissi', 'Prisca', $this->memberNumber($member), '0709080706', self::BIRTH_DATE, '17/05/1980', 'actif', 'en_attente', 'contenu@x.test'] as $leak) {
            $this->assertStringNotContainsString($leak, $mail['text'] . $mail['subject'], $leak);
        }
        $code = [];
        preg_match('/\b([A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4})\b/', $mail['text'], $code);
        $this->assertSame(302, $this->activateVia('contenu@x.test', $code[1])->status(), 'le code de l\'e-mail ouvre l\'accès');
    }

    public function test_disable_closes_sessions_and_codes_enable_restores(): void
    {
        $member = $this->memberForAccess();
        $code = $this->accounts()->create($member, 'lettre', $this->manager, null)['code'];
        $this->accounts()->setDisabled($member, true, $this->manager, null);
        $this->assertStringContainsString(e(MemberAccountService::FAILED), $this->activateVia($this->memberNumber($member), $code)->body());
        $this->accounts()->setDisabled($member, false, $this->manager, null);
        $this->assertSame('en_attente', $this->db->fetchValue('SELECT status FROM member_accounts WHERE member_id = ?', [$member]), 'sans mot de passe : en attente');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_activation_codes c JOIN member_accounts a ON a.id = c.member_account_id
            WHERE a.member_id = ? AND c.used_at IS NULL', [$member]), 'le code fermé à la désactivation ne revit pas');

        $this->forgetApp();
        $this->assertSame(302, $this->activateVia($this->memberNumber($member), (string) $this->accounts()->reissueActivation($member, 'lettre', $this->manager, null))->status());
        $version = (int) $this->db->fetchValue('SELECT session_version FROM member_accounts WHERE member_id = ?', [$member]);
        $this->accounts()->setDisabled($member, true, $this->manager, null);
        $this->assertSame($version + 1, (int) $this->db->fetchValue('SELECT session_version FROM member_accounts WHERE member_id = ?', [$member]));
        $this->assertSame(302, $this->request('GET', '/espace')->status(), 'session fermée');
        $this->accounts()->setDisabled($member, false, $this->manager, null);
        $this->assertSame('actif', $this->db->fetchValue('SELECT status FROM member_accounts WHERE member_id = ?', [$member]));
    }
}
