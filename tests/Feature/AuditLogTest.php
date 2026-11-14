<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\AuditLog\SensitiveAccess;
use Tests\Support\AdminTestCase;

final class AuditLogTest extends AdminTestCase
{
    private function admin(string $email): int
    {
        $id = $this->createUser($email, ['super_admin']);
        $this->actingAs($id);
        return $id;
    }

    /** Ligne d'audit antidatée (rompt la chaîne : le test de vérification repart de la genèse). */
    private function logAt(?int $userId, string $action, string $createdAt, ?array $new = null): int
    {
        $id = $this->services['audit']()->log($userId, $action, 'test', 1, null, $new, 'REF-' . $action);
        $this->db->update('audit_logs', ['created_at' => $createdAt], ['id' => $id]);
        return $id;
    }

    public function test_audit_list_filters_by_date_user_and_action_prefix(): void
    {
        $admin = $this->admin('journal1@example.test');
        $other = $this->createUser('autre1@example.test', []);
        $this->logAt($other, 'zz.alpha_old', '2026-03-09 23:59:59');
        $this->logAt($other, 'zz.alpha_in', '2026-03-10 00:00:00');
        $this->logAt($admin, 'zz.beta_in', '2026-03-10 12:00:00');
        $this->logAt($other, 'zzx.gamma_in', '2026-03-11 23:59:59');
        $this->logAt($other, 'zz.alpha_late', '2026-03-12 00:00:00');
        $this->logAt(null, 'zz.system', '2026-03-10 08:00:00');

        $res = $this->request('GET', '/admin/journal?du=2026-03-10&au=2026-03-11');
        $this->assertSame(200, $res->status());
        $this->assertSame('no-store', $res->headers()['Cache-Control'] ?? null);
        $body = $res->body();
        foreach (['zz.alpha_in', 'zz.beta_in', 'zzx.gamma_in', 'zz.system'] as $a) {
            $this->assertStringContainsString($a, $body);
        }
        $this->assertStringNotContainsString('zz.alpha_old', $body);
        $this->assertStringNotContainsString('zz.alpha_late', $body);
        $this->assertStringContainsString('système', $body);
        $this->assertStringContainsString('10/03/2026 12:00', $body);

        $body = $this->request('GET', "/admin/journal?du=2026-03-10&au=2026-03-11&utilisateur=$other")->body();
        $this->assertStringContainsString('zz.alpha_in', $body);
        $this->assertStringNotContainsString('zz.beta_in', $body);
        $this->assertStringNotContainsString('zz.system', $body);

        $body = $this->request('GET', '/admin/journal?du=2026-03-10&au=2026-03-11&action=zz.')->body();
        $this->assertStringContainsString('zz.beta_in', $body);
        $this->assertStringNotContainsString('zzx.gamma_in', $body);

        // « _ » saisi est un caractère littéral, pas un joker LIKE.
        $body = $this->request('GET', '/admin/journal?du=2026-03-10&au=2026-03-11&action=zz_')->body();
        $this->assertStringNotContainsString('zz.alpha_in', $body);

        $this->assertSame(422, $this->request('GET', '/admin/journal?action=zz%25')->status());
    }

    public function test_audit_values_are_escaped(): void
    {
        $this->admin('journal2@example.test');
        $this->services['audit']()->log(null, 'test.escape', 'test', 1, ['name' => '<script>alert(1)</script>'],
            ['bio' => '<b>gras</b>', 'long' => str_repeat('a', 3000)]);
        $body = $this->request('GET', '/admin/journal?action=test.escape')->body();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringNotContainsString('<b>gras</b>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringContainsString('<details>', $body);
        $this->assertStringContainsString('… (tronqué)', $body);
        $this->assertStringNotContainsString(str_repeat('a', 2500), $body);
    }

    public function test_invalid_date_filter_shows_validation_message(): void
    {
        $this->admin('journal3@example.test');
        $res = $this->request('GET', '/admin/journal?du=2026-02-30');
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Date invalide', $res->body());
        $this->assertSame(422, $this->request('GET', '/admin/journal?onglet=sensibles&au=demain')->status());
    }

    public function test_verification_reports_intact_and_tampered_chain(): void
    {
        $admin = $this->admin('journal4@example.test');
        // Les autres tests de la classe antidatent des lignes (chaîne rompue) : journal remis à la genèse.
        $this->db->query('DELETE FROM audit_logs');
        $this->db->query("UPDATE audit_chain SET last_id = NULL, last_hash = REPEAT('0', 64), row_count = 0 WHERE id = 1");
        $target = $this->services['audit']()->log(null, 'test.tamper', 'test', 1, null, ['v' => 1]);

        $res = $this->request('POST', '/admin/journal/verification');
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/journal', $res->headers()['Location']);
        $this->assertStringContainsString('Journal intact.', $this->request('GET', '/admin/journal')->body());
        $row = $this->db->fetch("SELECT user_id, new_values FROM audit_logs WHERE action = 'audit.verified' ORDER BY id DESC LIMIT 1");
        $this->assertSame($admin, (int) $row['user_id']);
        $this->assertSame(['result' => 'intact', 'first_bad_id' => null], json_decode($row['new_values'], true));

        $this->db->update('audit_logs', ['new_values' => '{"v":2}'], ['id' => $target]);
        try {
            $this->request('POST', '/admin/journal/verification');
            $this->assertStringContainsString("Anomalie détectée à partir de l'entrée n° $target : le journal a pu être modifié. Prévenez l'organe de contrôle.",
                html_entity_decode($this->request('GET', '/admin/journal')->body(), ENT_QUOTES));
            $row = $this->db->fetch("SELECT new_values FROM audit_logs WHERE action = 'audit.verified' ORDER BY id DESC LIMIT 1");
            $this->assertSame(['result' => 'anomaly', 'first_bad_id' => $target], json_decode($row['new_values'], true));
        } finally {
            $this->db->update('audit_logs', ['new_values' => '{"v":1}'], ['id' => $target]);
        }
    }

    public function test_verification_without_chain_row_reports_the_anomaly_instead_of_failing(): void
    {
        $this->admin('journal8@example.test');
        $chain = $this->db->fetch('SELECT * FROM audit_chain WHERE id = 1');
        $this->db->query('DELETE FROM audit_chain');
        try {
            $res = $this->request('POST', '/admin/journal/verification');
        } finally {
            $this->db->insert('audit_chain', $chain);
        }
        $this->assertSame(302, $res->status());
        $flash = (string) $this->services['session']->pullFlash('status');
        $this->assertStringContainsString('Anomalie détectée', $flash);
        $this->assertStringEndsWith(" (l'enregistrement de cette vérification a échoué : vérifiez la migration 0002)", $flash);
    }

    public function test_date_from_after_date_to_is_rejected(): void
    {
        $this->admin('journal9@example.test');
        foreach (['/admin/journal?du=2026-03-11&au=2026-03-10', '/admin/journal?onglet=sensibles&du=2026-03-11&au=2026-03-10'] as $url) {
            $res = $this->request('GET', $url);
            $this->assertSame(422, $res->status());
            $this->assertStringContainsString(e('Au : la date doit être postérieure ou égale à la date « Du ».'), $res->body());
        }
        $this->assertSame(200, $this->request('GET', '/admin/journal?du=2026-03-10&au=2026-03-10')->status());
    }

    public function test_no_route_modifies_or_deletes_audit_rows(): void
    {
        $this->app();
        $journal = [];
        foreach ($this->services['router']->routes() as $r) {
            if (str_starts_with($r['pattern'], '/admin/journal')) {
                $journal[] = "{$r['method']} {$r['pattern']}";
            }
        }
        sort($journal);
        $this->assertSame(['GET /admin/journal', 'POST /admin/journal/verification'], $journal);
    }

    public function test_sensitive_access_is_recorded_and_listed(): void
    {
        $admin = $this->admin('journal6@example.test');
        $other = $this->createUser('autre6@example.test', []);
        $log = new SensitiveAccess($this->db);
        $log->record($other, 'member', 42, 'identite<b>', '10.0.0.1');
        $log->record($admin, 'member', 43, 'sante', null);

        $body = $this->request('GET', "/admin/journal?onglet=sensibles&utilisateur=$other")->body();
        $this->assertStringContainsString('identite&lt;b&gt;', $body);
        $this->assertStringContainsString('member n° 42', $body);
        $this->assertStringContainsString('10.0.0.1', $body);
        $this->assertStringNotContainsString('member n° 43', $body);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->format('Y-m-d');
        $this->assertStringContainsString('member n° 43', $this->request('GET', "/admin/journal?onglet=sensibles&du=$today&au=$today")->body());
        $this->assertStringNotContainsString('member n° 43', $this->request('GET', '/admin/journal?onglet=sensibles&au=2020-01-01')->body());
    }
}
