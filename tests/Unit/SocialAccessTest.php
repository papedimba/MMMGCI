<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Audit;
use App\Core\Gate;
use App\Modules\Social\SocialAccess;
use Tests\DatabaseTestCase;
use Tests\Support\SocialFixtures;

final class SocialAccessTest extends DatabaseTestCase
{
    use SocialFixtures;

    private SocialAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', ['so_medic', 'so_medic']);
        foreach (['social.manage', 'social.view', 'social.medical.view', 'members.scope.all'] as $p) {
            $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', ['so_medic', $p]);
        }
        $this->access = new SocialAccess($this->db, new Gate($this->db), new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))));
    }

    public function test_policy_by_role(): void
    {
        $id = $this->registerRequest();
        $medic = $this->socialUser('medic', ['so_medic']);
        // [voit la demande, voit l'instruction, voit le médical, écrit le médical]
        $expected = [
            'creator' => [$this->creator, [true, false, false, false]],
            'zone A' => [$this->zoneCreator, [true, false, false, false]],
            'manager' => [$this->manager, [true, true, false, false]],
            'decider' => [$this->decider, [true, true, false, false]],
            'social.view seul' => [$this->plain, [true, false, false, false]],
            'médical' => [$medic, [true, true, true, true]],
        ];
        foreach ($expected as $who => [$user, $want]) {
            self::assertSame($want, [$this->access->canSeeRequest($user, $id), $this->access->canSeeInstruction($user),
                $this->access->canSeeMedical($user), $this->access->canWriteMedical($user)], $who);
        }
    }

    public function test_out_of_zone_inactive_unknown_and_without_social_view_cannot_see_the_request(): void
    {
        $id = $this->registerRequest();
        $zoneB = $this->socialUser('zoneB', ['so_zone'], $this->zoneB);
        $inactive = $this->socialUser('inactive', ['so_zone'], $this->zoneA, false);
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', ['so_fin', 'so_fin']);
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_fin' AND p.name IN ('finance.view', 'members.scope.all')");
        $finance = $this->socialUser('finance', ['so_fin']);
        self::assertTrue($this->access->canSeeRequest($this->zoneCreator, $id));
        self::assertFalse($this->access->canSeeRequest($zoneB, $id), 'hors zone');
        self::assertFalse($this->access->canSeeRequest($inactive, $id), 'compte désactivé');
        self::assertFalse($this->access->canSeeRequest($finance, $id), 'finance.view sans social.view');
        self::assertFalse($this->access->canSeeRequest($this->manager, 999_999_999), 'demande inconnue');
        $this->db->query('UPDATE members SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$this->memberA]);
        self::assertFalse($this->access->canSeeRequest($this->manager, $id), 'adhérent supprimé logiquement');
    }

    public function test_permission_withdrawn_takes_effect_immediately(): void
    {
        self::assertTrue($this->access->canSeeInstruction($this->manager));
        $this->db->query('UPDATE users SET is_active = 0 WHERE id = ?', [$this->manager]);
        self::assertFalse($this->access->canSeeInstruction($this->manager));
    }
}
