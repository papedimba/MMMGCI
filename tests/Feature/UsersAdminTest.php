<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Auth\PasswordReset;
use Tests\Support\AdminTestCase;

final class UsersAdminTest extends AdminTestCase
{
    private const SELF = 'Vous ne pouvez pas modifier vos propres rôles ni désactiver votre propre compte : demandez-le à un autre administrateur.';
    private const DUPLICATE = 'Cette adresse e-mail est déjà utilisée par un autre compte.';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM users');
        $this->admin = $this->createUser('admin@x.test', ['super_admin']);
        $this->actingAs($this->admin);
    }

    /** @return list<string> */
    private function roles(int $id): array
    {
        return array_column($this->db->fetchAll('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name', [$id]), 'name');
    }

    public function test_admin_creates_user_and_invitation_email_is_sent(): void
    {
        $res = $this->request('POST', '/admin/utilisateurs/nouveau', ['name' => 'Awa Koné', 'email' => ' Awa@X.test ', 'phone' => '07 12 34 56 78', 'roles' => ['gest_cms']]);

        $id = (int) $this->db->fetchValue("SELECT id FROM users WHERE email = 'awa@x.test'");
        $this->assertGreaterThan(0, $id);
        $this->assertSame(302, $res->status());
        $this->assertSame("/admin/utilisateurs/$id", $res->headers()['Location']);
        $this->assertSame(['gest_cms'], $this->roles($id));

        $this->assertCount(1, $this->mail->messages);
        $mail = $this->mail->messages[0];
        $text = base64_decode(str_replace("\r\n", '', $mail['body']));
        $this->assertSame('awa@x.test', $mail['to']);
        $this->assertSame(mb_encode_mimeheader("Invitation à l'administration de la plateforme MMMGCI", 'UTF-8', 'B', "\r\n"), $mail['subject']);
        $this->assertStringContainsString('Bonjour Awa Koné', $text);
        $this->assertStringContainsString('Ce lien est valable 72 heures', $text);
        $this->assertStringContainsString('choisissez votre mot de passe', $text);
        $this->assertMatchesRegularExpression('#/admin/reinitialiser/[a-f0-9]{64}#', $text);
        preg_match('#/admin/reinitialiser/([a-f0-9]{64})#', $text, $m);

        $resets = new PasswordReset($this->db);
        $this->assertSame($id, $resets->check($m[1], time() + 71 * 3600));
        $this->assertNull($resets->check($m[1], time() + 72 * 3600 + 60));
        $this->assertSame(302, $this->request('POST', '/admin/reinitialiser/' . $m[1], ['password' => 'ma phrase secrète 2027', 'password_confirmation' => 'ma phrase secrète 2027'])->status());
        $this->assertTrue(password_verify('ma phrase secrète 2027', (string) $this->db->fetchValue('SELECT password FROM users WHERE id = ?', [$id])));
    }

    public function test_duplicate_email_with_different_case_is_rejected(): void
    {
        $this->createUser('doublon@x.test', []);
        $res = $this->request('POST', '/admin/utilisateurs/nouveau', ['name' => 'Autre', 'email' => ' DOUBLON@X.TEST', 'roles' => []]);

        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(self::DUPLICATE), $res->body());
        $this->assertStringContainsString('value="Autre"', $res->body()); // saisie conservée
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE email = 'doublon@x.test'"));
        $this->assertCount(0, $this->mail->messages);
    }

    public function test_user_cannot_change_own_roles_or_deactivate_self(): void
    {
        $res = $this->request('POST', "/admin/utilisateurs/{$this->admin}", ['name' => 'Moi', 'email' => 'admin@x.test', 'roles_field' => '1', 'roles' => ['gest_cms']]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(self::SELF), $res->body());
        $this->assertSame(['super_admin'], $this->roles($this->admin));

        $res = $this->request('POST', "/admin/utilisateurs/{$this->admin}/activation", ['active' => '0']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(self::SELF), $res->body());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$this->admin]));

        // Son propre nom reste modifiable (le formulaire n'envoie pas les rôles pour soi-même).
        $this->assertSame(302, $this->request('POST', "/admin/utilisateurs/{$this->admin}", ['name' => 'Moi', 'email' => 'admin@x.test'])->status());
        $this->assertSame('Moi', $this->db->fetchValue('SELECT name FROM users WHERE id = ?', [$this->admin]));
    }

    public function test_deactivation_logs_out_the_user(): void
    {
        $target = $this->createUser('cible@x.test', ['gest_cms']);
        $version = (int) $this->db->fetchValue('SELECT session_version FROM users WHERE id = ?', [$target]);

        $res = $this->request('POST', "/admin/utilisateurs/$target/activation", ['active' => '0']);
        $this->assertSame(302, $res->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$target]));
        $this->assertSame('user.deactivated', $this->db->fetchValue("SELECT action FROM audit_logs WHERE auditable_id = ? AND action LIKE 'user.%' ORDER BY id DESC LIMIT 1", [$target]));

        $this->request('POST', "/admin/utilisateurs/$target/activation", ['active' => '1']);
        $this->assertSame('user.activated', $this->db->fetchValue("SELECT action FROM audit_logs WHERE auditable_id = ? AND action LIKE 'user.%' ORDER BY id DESC LIMIT 1", [$target]));

        // La session ouverte avant la désactivation reste fermée, même après réactivation.
        $this->services['session']->set('auth.user_id', $target);
        $this->services['session']->set('auth.session_version', $version);
        $res = $this->request('GET', '/admin');
        $this->assertSame(302, $res->status());
        $this->assertStringStartsWith('/admin/connexion', $res->headers()['Location']);
    }

    public function test_role_change_is_audited_with_before_and_after(): void
    {
        $target = $this->createUser('roles@x.test', ['gest_cms']);
        $form = $this->request('GET', "/admin/utilisateurs/$target");
        $this->assertSame(200, $form->status());
        $this->assertMatchesRegularExpression('#value="gest_cms" checked#', $form->body());
        $this->assertSame(200, $this->request('GET', '/admin/utilisateurs/nouveau')->status());
        $res = $this->request('POST', "/admin/utilisateurs/$target", ['name' => 'Utilisateur roles', 'email' => 'roles@x.test', 'roles_field' => '1', 'roles' => ['lecture_seule', 'gest_cms']]);

        $this->assertSame(302, $res->status());
        $this->assertSame(['gest_cms', 'lecture_seule'], $this->roles($target));
        $log = $this->db->fetch("SELECT user_id, old_values, new_values FROM audit_logs WHERE action = 'user.roles_changed' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$target]);
        $this->assertSame($this->admin, (int) $log['user_id']);
        $this->assertSame(['roles' => ['gest_cms']], json_decode($log['old_values'], true));
        $this->assertSame(['roles' => ['gest_cms', 'lecture_seule']], json_decode($log['new_values'], true));
        $this->assertNull($this->db->fetchValue("SELECT id FROM audit_logs WHERE action = 'user.updated' AND auditable_id = ?", [$target])); // rien d'autre n'a changé
    }

    public function test_list_search_escapes_output(): void
    {
        $id = $this->db->insert('users', ['name' => '<b>X</b>', 'email' => 'xss@x.test', 'password' => 'x']);
        $res = $this->request('GET', '/admin/utilisateurs?q=' . rawurlencode('<b>'));

        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('&lt;b&gt;X&lt;/b&gt;', $res->body());
        $this->assertStringNotContainsString('<b>X</b>', $res->body());
        $this->assertStringContainsString("/admin/utilisateurs/$id", $res->body());
        $this->assertStringNotContainsString('admin@x.test', $res->body());

        // % et _ sont cherchés littéralement.
        $this->assertStringNotContainsString('xss@x.test', $this->request('GET', '/admin/utilisateurs?q=%25')->body());
        $this->assertStringNotContainsString('xss@x.test', $this->request('GET', '/admin/utilisateurs?q=_')->body());
    }

    public function test_list_is_paginated_by_50(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->db->insert('users', ['name' => sprintf('Membre %02d', $i), 'email' => "m$i@x.test", 'password' => 'x']);
        }
        $page1 = $this->request('GET', '/admin/utilisateurs')->body();
        $page2 = $this->request('GET', '/admin/utilisateurs?page=2')->body();

        $this->assertSame(50, substr_count($page1, '<tr data-user'));
        $this->assertSame(6, substr_count($page2, '<tr data-user')); // 56 comptes avec l'administrateur
        $this->assertStringContainsString('page=2', $page1);
    }

    public function test_unknown_user_gives_404(): void
    {
        $this->assertSame(404, $this->request('GET', '/admin/utilisateurs/999999')->status());
        $this->assertSame(404, $this->request('POST', '/admin/utilisateurs/999999/activation', ['active' => '0'])->status());
    }

    public function test_admin_general_cannot_create_a_super_admin_over_http(): void
    {
        $this->actingAs($this->createUser('ag@x.test', ['admin_general']));
        $res = $this->request('POST', '/admin/utilisateurs/nouveau', ['name' => 'Escalade', 'email' => 'esc@x.test', 'roles' => ['super_admin']]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e('Cette opération est réservée aux administrateurs détenant déjà ces droits : demandez-la à un super administrateur.'), $res->body());
        $this->assertNull($this->db->fetchValue("SELECT id FROM users WHERE email = 'esc@x.test'"));
    }

    public function test_user_is_created_without_token_when_app_url_is_not_configured(): void
    {
        $this->env = ['APP_URL' => ''];
        $this->forgetApp();
        $this->actingAs($this->admin);
        $log = tempnam(sys_get_temp_dir(), 'errlog');
        $previous = ini_set('error_log', $log);
        try {
            $res = $this->request('POST', '/admin/utilisateurs/nouveau', ['name' => 'Sans lien', 'email' => 'sanslien@x.test', 'roles' => []]);
        } finally {
            ini_set('error_log', (string) $previous);
            unlink($log);
        }
        $id = (int) $this->db->fetchValue("SELECT id FROM users WHERE email = 'sanslien@x.test'");
        $this->assertSame(302, $res->status());
        $this->assertGreaterThan(0, $id);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM password_resets WHERE user_id = ?', [$id]));
        $this->assertCount(0, $this->mail->messages);
        $this->assertSame("Compte créé, mais l'invitation n'a pas pu être envoyée (adresse du site non configurée) : utilisez « Mot de passe oublié » une fois la configuration corrigée.",
            $this->services['session']->pullFlash('status'));
    }

    public function test_activation_rejects_values_other_than_0_or_1(): void
    {
        $target = $this->createUser('valeur@x.test', ['gest_cms']);
        foreach (['', 'x', 'false'] as $value) {
            $res = $this->request('POST', "/admin/utilisateurs/$target/activation", ['active' => $value]);
            $this->assertSame(400, $res->status());
            $this->assertStringContainsString('Demande d&#039;activation invalide', $res->body());
        }
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$target]));
        $this->assertNull($this->db->fetchValue("SELECT id FROM audit_logs WHERE auditable_id = ? AND action LIKE 'user.%activated'", [$target]));
    }
}
