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
        $actor = $this->user('cms@x.test', ['gest_cms']);
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
        $actor = $this->user('cms2@x.test', ['gest_cms']);
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
}
