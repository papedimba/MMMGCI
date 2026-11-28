<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Settings;
use App\Modules\Espace\MemberAccess;
use Tests\DatabaseTestCase;

final class MemberAccessTest extends DatabaseTestCase
{
    private const DEFAULT = '{"actif":"complet","suspendu":"lecture","radie":"desactive","decede":"desactive"}';

    protected function tearDown(): void
    {
        $this->db->query("DELETE FROM settings WHERE `group` = 'espace' AND `key` = 'status_access'");
        $this->db->query("INSERT INTO settings (`group`, `key`, `value`, status) VALUES ('espace', 'status_access', ?, 'non_configure')", [self::DEFAULT]);
    }

    private function access(): MemberAccess
    {
        return new MemberAccess(new Settings($this->db));
    }

    public function test_status_access_default_mapping_applied_while_non_configure(): void
    {
        $a = $this->access();
        $this->assertTrue($a->pending());
        $this->assertSame(['complet', 'lecture', 'desactive', 'desactive'], array_map($a->level(...), ['actif', 'suspendu', 'radie', 'decede']));
        // Validée par les instances avec une autre table : c'est elle qui s'applique, et le bandeau disparaît.
        $this->db->query("UPDATE settings SET `value` = ?, status = 'valide' WHERE `group` = 'espace' AND `key` = 'status_access'", ['{"actif":"lecture"}']);
        $this->assertFalse($a->pending());
        $this->assertSame('lecture', $a->level('actif'));
    }

    public function test_unknown_status_gives_read_only(): void
    {
        $this->assertSame('lecture', $this->access()->level('ancien_marin'));
        $this->assertSame('lecture', $this->access()->level('statut_inexistant'));
    }

    public function test_unreadable_setting_disables_access(): void
    {
        $set = fn (?string $json) => $this->db->query("UPDATE settings SET `value` = ? WHERE `group` = 'espace' AND `key` = 'status_access'", [$json]);
        foreach (['"complet"', '["complet"]', '{"actif":"tout"}', '{"actif":1}', null] as $json) {
            $set($json);
            $this->assertSame('desactive', $this->access()->level('actif'), (string) $json);
        }
        $this->db->query("DELETE FROM settings WHERE `group` = 'espace' AND `key` = 'status_access'");
        $this->assertSame('desactive', $this->access()->level('actif'));
        $this->assertTrue($this->access()->pending());
    }
}
