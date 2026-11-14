<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Modules\Users\UserService;
use DomainException;
use PDOException;
use Tests\DatabaseTestCase;

final class UserServiceTest extends DatabaseTestCase
{
    private const LAST_ADMIN = 'Opération impossible : il doit rester au moins un administrateur actif.';
    private const PROTECTED = 'Cette opération est réservée aux administrateurs détenant déjà ces droits : demandez-la à un super administrateur.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM users');
    }

    private function service(?Database $db = null): UserService
    {
        $db ??= $this->db;
        return new UserService($db, new Audit($db, (string) self::config()->get('APP_KEY')), new Gate($db));
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles, bool $active = true): int
    {
        $id = $this->db->insert('users', ['name' => 'U ' . $email, 'email' => $email, 'password' => 'x', 'is_active' => $active ? 1 : 0]);
        foreach ($roles as $role) {
            $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        }
        return $id;
    }

    /** @return list<string> */
    private function roles(int $id): array
    {
        return array_column($this->db->fetchAll('SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.name', [$id]), 'name');
    }

    private function assertDomain(string $message, callable $fn): void
    {
        try {
            $fn();
            $this->fail('DomainException attendue : ' . $message);
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_last_active_user_manager_cannot_be_deactivated_or_demoted(): void
    {
        $a = $this->user('a@x.test', ['super_admin']);
        $this->user('inactif@x.test', ['super_admin'], false); // un détenteur inactif ne compte pas
        $actor = null; // CLI : exempté des règles de rôles protégés, pas de la règle du dernier administrateur
        $svc = $this->service();

        $this->assertDomain(self::LAST_ADMIN, fn () => $svc->setActive($a, false, $actor));
        $this->assertDomain(self::LAST_ADMIN, fn () => $svc->setRoles($a, ['gest_cms'], $actor));
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$a]));
        $this->assertSame(['super_admin'], $this->roles($a));

        $svc->setRoles($a, ['admin_general'], $actor); // garde users.manage par un autre rôle
        $b = $this->user('b@x.test', ['admin_general']);
        $svc->setRoles($a, ['gest_cms'], $actor);
        $this->assertSame(['gest_cms'], $this->roles($a));
        $this->assertDomain(self::LAST_ADMIN, fn () => $svc->setActive($b, false, $actor));
        $this->assertTrue($svc->wouldLeaveNoAdmin($b, deactivate: true));
        $this->assertFalse($svc->wouldLeaveNoAdmin($a, deactivate: true));
    }

    public function test_admin_check_locks_holder_rows_against_a_concurrent_demotion(): void
    {
        $a = $this->user('a2@x.test', ['super_admin']);
        $b = $this->user('b2@x.test', ['super_admin']);
        $actor = null;
        $other = Database::connect(self::config(), test: true);
        $other->query('SET SESSION innodb_lock_wait_timeout = 1');

        $caught = $this->db->transaction(function () use ($a, $b, $actor, $other): ?PDOException {
            $this->assertFalse($this->service()->wouldLeaveNoAdmin($a, deactivate: true));
            try {
                $this->service($other)->setActive($b, false, $actor); // doit attendre la première transaction
                return null;
            } catch (PDOException $e) {
                return $e;
            }
        });
        $this->assertNotNull($caught, 'La seconde rétrogradation aurait dû attendre le verrou.');
        $this->assertStringContainsString('Lock wait timeout', $caught->getMessage());
    }

    public function test_unknown_role_name_is_rejected(): void
    {
        $id = $this->user('r@x.test', ['gest_cms']);
        $actor = $this->user('adm@x.test', ['super_admin']);
        $msg = 'Rôle inconnu : inexistant. Choisissez les rôles dans la liste proposée.';
        $this->assertDomain($msg, fn () => $this->service()->setRoles($id, ['gest_cms', 'inexistant'], $actor));
        $this->assertDomain($msg, fn () => $this->service()->create(['name' => 'N', 'email' => 'n@x.test', 'roles' => ['inexistant']], $actor));
        $this->assertSame(['gest_cms'], $this->roles($id));
        $this->assertNull($this->db->fetchValue("SELECT id FROM users WHERE email = 'n@x.test'"));
    }

    public function test_create_normalizes_and_sets_an_unusable_password(): void
    {
        $actor = $this->user('adm3@x.test', ['super_admin']);
        $id = $this->service()->create(['name' => ' Awa Koné ', 'email' => ' Awa@X.test ', 'phone' => '+225 07 12 34 56 78', 'roles' => ['gest_cms', 'lecture_seule']], $actor);

        $row = $this->db->fetch('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame(['Awa Koné', 'awa@x.test', '0712345678', 1], [$row['name'], $row['email'], $row['phone'], (int) $row['is_active']]);
        $this->assertNotNull(password_get_info($row['password'])['algo']); // un vrai haché, d'un secret jamais connu
        $this->assertFalse(password_verify('', $row['password']));
        $this->assertSame(['gest_cms', 'lecture_seule'], $this->roles($id));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM user_roles WHERE user_id = ? AND scope_zone_id IS NOT NULL', [$id]));
        $log = $this->db->fetch("SELECT user_id, new_values FROM audit_logs WHERE action = 'user.created' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$id]);
        $this->assertSame($actor, (int) $log['user_id']);
        $this->assertSame(['gest_cms', 'lecture_seule'], json_decode($log['new_values'], true)['roles']);
    }

    public function test_update_rejects_email_of_another_account_and_validates(): void
    {
        $actor = $this->user('adm4@x.test', ['super_admin']);
        $id = $this->user('cible@x.test', []);
        $this->user('pris@x.test', []);
        $this->assertDomain('Cette adresse e-mail est déjà utilisée par un autre compte.',
            fn () => $this->service()->update($id, ['name' => 'C', 'email' => ' PRIS@x.test'], $actor));
        $this->assertDomain('Le champ nom est obligatoire.', fn () => $this->service()->update($id, ['name' => ' ', 'email' => 'cible@x.test'], $actor));
        $this->assertDomain('Numéro de téléphone invalide : saisissez 10 chiffres (ex. 07 12 34 56 78).',
            fn () => $this->service()->update($id, ['name' => 'C', 'email' => 'cible@x.test', 'phone' => '12'], $actor));

        $this->service()->update($id, ['name' => 'Nouveau nom', 'email' => 'CIBLE@x.test'], $actor); // même compte : autorisé
        $log = $this->db->fetch("SELECT old_values, new_values FROM audit_logs WHERE action = 'user.updated' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$id]);
        $this->assertSame(['name' => 'U cible@x.test'], json_decode($log['old_values'], true));
        $this->assertSame(['name' => 'Nouveau nom'], json_decode($log['new_values'], true));
    }

    private function roleId(string $name): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM roles WHERE name = ?', [$name]);
    }

    public function test_admin_general_cannot_assign_protected_roles(): void
    {
        $ag = $this->user('ag@x.test', ['admin_general']);
        $svc = $this->service();
        foreach (['super_admin', 'pca', 'gest_adherents', 'gest_social'] as $i => $role) {
            $this->assertDomain(self::PROTECTED, fn () => $svc->create(['name' => 'P', 'email' => "p$i@x.test", 'roles' => ['lecture_seule', $role]], $ag));
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE email LIKE 'p_@x.test'"));

        $ok = ['admin_general', 'controleur', 'lecture_seule', 'redacteur', 'secretaire_general', 'tresorier_general'];
        $id = $svc->create(['name' => 'Ok', 'email' => 'ok@x.test', 'roles' => $ok], $ag);
        $this->assertSame($ok, $this->roles($id));
        $this->assertDomain(self::PROTECTED, fn () => $svc->setRoles($id, [...$ok, 'pca'], $ag));

        $sa = $this->user('sa@x.test', ['super_admin']); // un super administrateur peut tout attribuer
        $all = array_column($this->db->fetchAll('SELECT name FROM roles ORDER BY name'), 'name');
        $svc->setRoles($id, $all, $sa);
        $this->assertCount(count($all), $this->roles($id));
    }

    public function test_admin_general_cannot_act_on_holders_of_protected_roles(): void
    {
        $ag = $this->user('ag2@x.test', ['admin_general']);
        $sa = $this->user('sa2@x.test', ['super_admin']);
        $pca = $this->user('pca2@x.test', ['pca']);
        $svc = $this->service();
        foreach ([$sa, $pca] as $target) {
            $this->assertDomain(self::PROTECTED, fn () => $svc->update($target, ['name' => 'X', 'email' => "autre$target@x.test"], $ag));
            $this->assertDomain(self::PROTECTED, fn () => $svc->setActive($target, false, $ag));
            $this->assertDomain(self::PROTECTED, fn () => $svc->setRoles($target, ['lecture_seule'], $ag));
        }
        $this->assertSame(['super_admin'], $this->roles($sa));
        $this->assertSame('sa2@x.test', $this->db->fetchValue('SELECT email FROM users WHERE id = ?', [$sa]));

        $other = $this->user('ag3@x.test', ['admin_general']); // même droits que l'acteur : autorisé
        $svc->setActive($other, false, $ag);
        $svc->setRoles($pca, ['lecture_seule'], $sa);
        $this->assertSame(['lecture_seule'], $this->roles($pca));
    }

    public function test_cli_without_actor_is_exempt(): void
    {
        $id = $this->service()->create(['name' => 'Admin', 'email' => 'cli@x.test', 'roles' => ['super_admin']], null);
        $this->assertSame(['super_admin'], $this->roles($id));
        $this->assertNull($this->db->fetchValue("SELECT user_id FROM audit_logs WHERE action = 'user.created' AND auditable_id = ?", [$id]));
    }

    public function test_email_change_and_deactivation_invalidate_open_tokens(): void
    {
        $sa = $this->user('sa4@x.test', ['super_admin']);
        $id = $this->user('jeton@x.test', ['lecture_seule']);
        $resets = new \App\Modules\Auth\PasswordReset($this->db);
        $token = $resets->issue($id, time());

        $this->service()->update($id, ['name' => 'Autre nom', 'email' => 'jeton@x.test'], $sa); // e-mail inchangé : jeton gardé
        $this->assertSame($id, $resets->check($token, time()));
        $this->service()->update($id, ['name' => 'Autre nom', 'email' => 'nouveau@x.test'], $sa);
        $this->assertNull($resets->check($token, time()));

        $token = $resets->issue($id, time());
        $this->service()->setActive($id, false, $sa);
        $this->service()->setActive($id, true, $sa);
        $this->assertNull($resets->check($token, time()));
    }

    public function test_role_change_that_strips_the_last_holder_is_detected(): void
    {
        $this->user('seul@x.test', ['super_admin']);
        $this->user('lecteur@x.test', ['lecture_seule']);
        $svc = $this->service();
        $super = $this->roleId('super_admin');

        $this->assertTrue($svc->wouldLeaveNoAdminAfterRoleChange($super, ['audit.view']));
        $this->assertTrue($svc->wouldLeaveNoAdminAfterRoleChange($super, deleted: true));
        $this->assertFalse($svc->wouldLeaveNoAdminAfterRoleChange($super, ['audit.view', 'users.manage']));
        $this->assertFalse($svc->wouldLeaveNoAdminAfterRoleChange($super));
        $this->assertFalse($svc->wouldLeaveNoAdminAfterRoleChange($this->roleId('lecture_seule'), deleted: true));

        $this->user('autre@x.test', ['admin_general']);
        $this->assertFalse($svc->wouldLeaveNoAdminAfterRoleChange($super, deleted: true));
        $this->assertFalse($svc->wouldLeaveNoAdminAfterRoleChange($this->roleId('admin_general'), [])); // le super_admin reste détenteur
    }

    public function test_target_roles_are_rechecked_after_the_lock(): void
    {
        $sa = $this->user('sa5@x.test', ['super_admin']);
        $ag = $this->user('ag5@x.test', ['admin_general']);
        $target = $this->user('cible5@x.test', ['gest_cms']);

        // Le super administrateur promeut la cible sans valider encore ; l'admin_general tente de la désactiver en parallèle.
        [$proc, $pipes] = $this->db->transaction(function () use ($sa, $ag, $target): array {
            $this->service()->setRoles($target, ['super_admin'], $sa);
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/user_worker.php', 'setActive', (string) $target, '0', (string) $ag],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $observer = Database::connect(self::config(), test: true);
            for ($i = 0; $i < 200 && (int) $observer->fetchValue("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE '%FOR UPDATE%'") === 0; $i++) {
                usleep(25_000); // attend que le processus fils soit bloqué sur une lecture verrouillante (sans privilège PROCESS : ses propres connexions)
            }
            $this->assertLessThan(200, $i, 'Le processus fils aurait dû attendre le verrou.');
            return [$proc, $pipes];
        });
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);

        $this->assertSame(self::PROTECTED, $output);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT is_active FROM users WHERE id = ?', [$target]));
        $this->assertSame(['super_admin'], $this->roles($target));
    }
}
