<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

final class RolesAdminTest extends AdminTestCase
{
    private const SYSTEM = 'Ce rôle système ne peut pas être modifié.';
    private const LAST_ADMIN = 'Opération impossible : il doit rester au moins un administrateur actif.';
    private const PROTECTED = 'Cette opération est réservée aux administrateurs détenant déjà ces droits : demandez-la à un super administrateur.';
    private const DOUBLE = 'Réservé aux personnes désignées par les instances (double validation).';
    private const OWN_ONLY = 'Vous ne pouvez attribuer que les droits que vous détenez.';

    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM users');
        $this->db->query("DELETE FROM roles WHERE name LIKE 'test\\_%'");
        $this->admin = $this->createUser('admin@x.test', ['super_admin']);
        $this->actingAs($this->admin);
    }

    private function roleId(string $name): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM roles WHERE name = ?', [$name]);
    }

    /** @return list<string> */
    private function permissions(int $roleId): array
    {
        return array_column($this->db->fetchAll('SELECT p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.name', [$roleId]), 'name');
    }

    /** @param list<string> $permissions */
    private function role(string $name, array $permissions): int
    {
        $id = $this->db->insert('roles', ['name' => $name, 'label' => "Rôle $name"]);
        foreach ($permissions as $p) {
            $this->db->query('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$id, $p]);
        }
        return $id;
    }

    public function test_role_permissions_can_be_edited_and_take_effect_immediately(): void
    {
        $role = $this->role('test_lecteur', ['members.view']);
        $holder = $this->createUser('lecteur@x.test', ['test_lecteur']);
        $this->actingAs($holder);
        $this->assertSame(403, $this->request('GET', '/admin/utilisateurs')->status()); // met ses droits en cache dans Gate

        $this->actingAs($this->admin);
        $form = $this->request('GET', "/admin/roles/$role");
        $this->assertSame(200, $form->status());
        $this->assertMatchesRegularExpression('#value="members.view" checked#', $form->body());
        $res = $this->request('POST', "/admin/roles/$role", ['label' => 'Lecteur étendu', 'description' => '', 'permissions_field' => '1', 'permissions' => ['members.view', 'users.manage']]);
        $this->assertSame(302, $res->status());
        $this->assertSame("/admin/roles/$role", $res->headers()['Location']);
        $this->assertSame(['members.view', 'users.manage'], $this->permissions($role));
        $this->assertSame('Lecteur étendu', $this->db->fetchValue('SELECT label FROM roles WHERE id = ?', [$role]));

        $this->actingAs($holder); // nouvelle requête : le droit ajouté s'applique sans reconnexion
        $this->assertSame(200, $this->request('GET', '/admin/utilisateurs')->status());
    }

    public function test_system_role_cannot_be_edited_or_deleted(): void
    {
        $super = $this->roleId('super_admin');
        $before = $this->permissions($super);
        $res = $this->request('POST', "/admin/roles/$super", ['label' => 'Pirate', 'permissions_field' => '1', 'permissions' => ['members.view']]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(self::SYSTEM), $res->body());
        $res = $this->request('POST', "/admin/roles/$super/suppression");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(self::SYSTEM), $res->body());
        $this->assertSame($before, $this->permissions($super));
        $this->assertSame('Super administrateur technique', $this->db->fetchValue('SELECT label FROM roles WHERE id = ?', [$super]));
    }

    public function test_removing_users_manage_from_the_last_admin_role_is_refused(): void
    {
        $this->db->query('DELETE FROM user_roles WHERE user_id = ?', [$this->admin]); // seul détenteur : par admin_general
        $this->db->query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'admin_general'", [$this->admin]);
        $this->createUser('inactif@x.test', ['super_admin'], active: false);
        $role = $this->roleId('admin_general');
        $before = $this->permissions($role);
        $without = array_values(array_diff($before, ['users.manage']));

        $res = $this->request('POST', "/admin/roles/$role", ['label' => 'Administrateur général', 'permissions_field' => '1', 'permissions' => $without]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(self::LAST_ADMIN), $res->body());
        $this->assertSame($before, $this->permissions($role));

        $this->createUser('autre@x.test', ['super_admin']); // un autre détenteur actif : le retrait passe
        $this->assertSame(302, $this->request('POST', "/admin/roles/$role", ['label' => 'Administrateur général', 'permissions_field' => '1', 'permissions' => $without])->status());
        $this->assertSame($without, $this->permissions($role));
    }

    public function test_role_in_use_cannot_be_deleted(): void
    {
        $role = $this->role('test_utilise', ['members.view']);
        $this->createUser('porteur@x.test', ['test_utilise'], active: false); // même un compte désactivé compte
        $res = $this->request('POST', "/admin/roles/$role/suppression");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e('Ce rôle est attribué à 1 utilisateur(s) : retirez-le de leurs comptes avant de le supprimer.'), $res->body());
        $this->assertSame($role, $this->roleId('test_utilise'));

        $this->db->query('DELETE FROM user_roles WHERE role_id = ?', [$role]);
        $res = $this->request('POST', "/admin/roles/$role/suppression");
        $this->assertSame(302, $res->status());
        $this->assertSame('/admin/roles', $res->headers()['Location']);
        $this->assertSame(0, $this->roleId('test_utilise'));
        $log = $this->db->fetch("SELECT user_id, old_values FROM audit_logs WHERE action = 'role.deleted' AND auditable_id = ?", [$role]);
        $this->assertSame($this->admin, (int) $log['user_id']);
        $this->assertSame(['members.view'], json_decode($log['old_values'], true)['permissions']);
    }

    public function test_invalid_role_name_is_rejected(): void
    {
        foreach (['', 'Majuscule', '1abc', 'ab', 'avec-tiret', 'espace x', str_repeat('a', 61)] as $name) {
            $res = $this->request('POST', '/admin/roles/nouveau', ['name' => $name, 'label' => 'Essai <b>']);
            $this->assertSame(422, $res->status(), $name);
            $this->assertStringContainsString('Identifiant de rôle invalide', $res->body());
            $this->assertStringContainsString('value="Essai &lt;b&gt;"', $res->body()); // saisie conservée, échappée
        }
        $res = $this->request('POST', '/admin/roles/nouveau', ['name' => 'pca', 'label' => 'Doublon']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e('Cet identifiant de rôle est déjà utilisé : choisissez-en un autre.'), $res->body());
        $this->assertSame(19, (int) $this->db->fetchValue('SELECT COUNT(*) FROM roles'));

        $this->assertSame(200, $this->request('GET', '/admin/roles/nouveau')->status());
        $res = $this->request('POST', '/admin/roles/nouveau', ['name' => 'test_tresorerie', 'label' => 'Trésorerie', 'description' => 'Suivi de caisse']);
        $id = $this->roleId('test_tresorerie');
        $this->assertSame(302, $res->status());
        $this->assertSame("/admin/roles/$id", $res->headers()['Location']);
        $this->assertSame([], $this->permissions($id)); // un nouveau rôle part sans permission
        $this->assertSame('role.created', $this->db->fetchValue('SELECT action FROM audit_logs WHERE auditable_id = ? AND auditable_type = ? ORDER BY id DESC LIMIT 1', [$id, 'role']));
    }

    public function test_permission_change_is_audited(): void
    {
        $role = $this->role('test_finance', ['finance.view', 'members.view']);
        $res = $this->request('POST', "/admin/roles/$role", ['label' => 'Rôle test_finance', 'permissions_field' => '1', 'permissions' => ['finance.view', 'disbursement.pay', 'disbursement.approve']]);
        $this->assertSame(302, $res->status()); // un super administrateur désigne les approbateurs (décision des instances)

        $log = $this->db->fetch("SELECT user_id, old_values, new_values FROM audit_logs WHERE action = 'role.permissions_changed' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$role]);
        $this->assertSame($this->admin, (int) $log['user_id']);
        $this->assertSame(['permissions' => ['finance.view', 'members.view']], json_decode($log['old_values'], true));
        $this->assertSame(['permissions' => ['disbursement.approve', 'disbursement.pay', 'finance.view']], json_decode($log['new_values'], true));
        $this->assertNull($this->db->fetchValue("SELECT id FROM audit_logs WHERE action = 'role.updated' AND auditable_id = ?", [$role])); // libellé inchangé
    }

    public function test_admin_cannot_grant_a_permission_they_do_not_hold(): void
    {
        $role = $this->role('test_caisse', ['finance.view']);
        $this->actingAs($this->createUser('ag@x.test', ['admin_general']));
        $form = $this->request('GET', "/admin/roles/$role")->body();
        $this->assertMatchesRegularExpression('#value="disbursement.approve" disabled#', $form);
        $this->assertStringContainsString(e(self::OWN_ONLY), $form);
        $this->assertMatchesRegularExpression('#value="members.view">#', $form); // détenue : cochable

        $res = $this->request('POST', "/admin/roles/$role", ['label' => 'Rôle test_caisse', 'permissions_field' => '1', 'permissions' => ['finance.view', 'disbursement.approve']]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e("Vous ne pouvez attribuer que les droits que vous détenez : demandez à un super administrateur d'ajouter disbursement.approve."), $res->body());
        $this->assertSame(['finance.view'], $this->permissions($role));

        $this->assertSame(302, $this->request('POST', "/admin/roles/$role", ['label' => 'Rôle test_caisse', 'permissions_field' => '1', 'permissions' => ['members.view']])->status());
        $this->assertSame(['members.view'], $this->permissions($role)); // retirer finance.view et ajouter un droit détenu : permis
    }

    public function test_admin_general_cannot_edit_or_delete_a_protected_role(): void
    {
        $this->actingAs($this->createUser('ag2@x.test', ['admin_general']));
        foreach (['gest_social', 'pca'] as $name) { // social.medical.view ; décisions que l'acteur ne détient pas
            $id = $this->roleId($name);
            $before = $this->permissions($id);
            foreach ([['label' => 'X', 'permissions_field' => '1', 'permissions' => []], ['label' => 'X']] as $post) {
                $res = $this->request('POST', "/admin/roles/$id", $post);
                $this->assertSame(422, $res->status(), $name);
                $this->assertStringContainsString(e(self::PROTECTED), $res->body());
            }
            $this->assertSame(422, $this->request('POST', "/admin/roles/$id/suppression")->status());
            $this->assertSame($before, $this->permissions($id));
            $this->assertSame($id, $this->roleId($name));
        }
        // admin_general est protégé (users.manage) mais l'acteur en détient tous les droits : modifiable.
        $ag = $this->roleId('admin_general');
        $this->assertSame(302, $this->request('POST', "/admin/roles/$ag", ['label' => 'Admin général'])->status());
    }

    public function test_role_screens_group_permissions_and_escape_output(): void
    {
        $role = $this->role('test_xss', []);
        $this->db->update('roles', ['label' => '<script>x</script>'], ['id' => $role]);
        $list = $this->request('GET', '/admin/roles');
        $this->assertSame(200, $list->status());
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $list->body());
        $this->assertStringNotContainsString('<script>x</script>', $list->body());

        $form = $this->request('GET', "/admin/roles/$role")->body();
        $this->assertStringNotContainsString('<script>x</script>', $form);
        $this->assertStringContainsString('<legend>finance</legend>', $form);
        $this->assertStringContainsString('Approuver un décaissement', $form);
        $this->assertSame(2, substr_count($form, e(self::DOUBLE)));
        $this->assertSame(3, substr_count($form, 'name="_csrf"')); // déconnexion, rôle, suppression
        $this->assertSame(404, $this->request('GET', '/admin/roles/999999')->status());
        $this->assertSame(404, $this->request('POST', '/admin/roles/999999/suppression')->status());
    }
}
