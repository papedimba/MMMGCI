<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class MembersSchemaTest extends DatabaseTestCase
{
    public function test_seeds_are_present_and_no_profession_or_zone_is_invented(): void
    {
        $this->assertSame(['Anciens marins', 'Gens de mer', 'Marins de la marine marchande', 'Personnels navigants et assimilés'],
            array_column($this->db->fetchAll('SELECT name FROM member_categories ORDER BY name'), 'name'));
        $this->assertSame(['actif', 'ancien_marin', 'decede', 'radie', 'suspendu'], array_column($this->db->fetchAll('SELECT code FROM member_statuses ORDER BY code'), 'code'));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_statuses WHERE is_proposed = 0'));
        $this->assertSame(['autre', 'identite', 'livret_maritime', 'photo'], array_column($this->db->fetchAll('SELECT code FROM member_document_types ORDER BY code'), 'code'));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM maritime_professions') + (int) $this->db->fetchValue('SELECT COUNT(*) FROM zones'));
    }

    public function test_members_has_fulltext_index_and_users_zone_column(): void
    {
        $this->assertSame('FULLTEXT', $this->db->fetchValue("SELECT INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND INDEX_NAME = 'ft_members_name' AND SEQ_IN_INDEX = 1"));
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'members' AND INDEX_NAME = 'ft_members_name'"));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'zone_id'"));
    }

    public function test_settings_are_defined_unvalidated_and_new_permissions_scoped(): void
    {
        $this->assertSame(['card_conditions', 'card_validity_months', 'number_format', 'retention_months'],
            array_column($this->db->fetchAll("SELECT `key` FROM settings WHERE `group` = 'members' AND status = 'non_configure' ORDER BY `key`"), 'key'));
        foreach (['delegue', 'point_focal'] as $role) {
            $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id WHERE r.name = ? AND p.name IN ('members.scope.all', 'members.status')", [$role]));
        }
        $this->assertSame(['members'], array_column($this->db->fetchAll("SELECT DISTINCT module FROM permissions WHERE name IN ('members.status', 'members.scope.all')"), 'module'));
    }
}
