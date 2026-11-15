<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Sequence;
use App\Core\Settings;
use App\Modules\Members\MemberService;
use DomainException;
use Tests\DatabaseTestCase;

final class MemberServiceTest extends DatabaseTestCase
{
    private MemberService $svc;
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['members', 'sequences'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('DELETE FROM users');
        $this->actor = $this->db->insert('users', ['name' => 'Agent', 'email' => 'a' . random_int(1, 99999) . '@x.test', 'password' => 'x']);
        $this->setFormat('MMMGCI-{YYYY}-{NNNNN}');
        $this->svc = new MemberService($this->db, new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32))), new Settings($this->db), new Sequence($this->db));
    }

    private function setFormat(string $f): void
    {
        $this->db->query("UPDATE settings SET `value` = ? WHERE `group` = 'members' AND `key` = 'number_format'", [json_encode($f)]);
    }

    /** @return array<string,mixed> */
    private function data(array $over = []): array
    {
        return $over + ['last_name' => 'Kouassi', 'first_names' => 'Yao Jean', 'birth_date' => '1980-05-12'];
    }

    public function test_member_number_is_sequential_and_follows_the_configured_format(): void
    {
        $year = date('Y');
        $a = $this->svc->create($this->data(), $this->actor);
        $b = $this->svc->create($this->data(['last_name' => 'Traoré']), $this->actor);
        $this->assertSame("MMMGCI-$year-00001", $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$a]));
        $this->assertSame("MMMGCI-$year-00002", $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$b]));
        $this->setFormat('ADH/{NNNNN}');
        $c = $this->svc->create($this->data(['last_name' => 'Koné']), $this->actor);
        $this->assertSame('ADH/00001', $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$c]));
        $this->svc->update($c, $this->data(['last_name' => 'Koné', 'employer' => 'Port']), $this->actor);
        $this->assertSame('ADH/00001', $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$c]), 'le numéro ne change jamais');
        $this->assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'member.created' AND user_id = ?", [$this->actor]));
        $this->assertNotNull($this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'member.updated' AND auditable_id = ?", [$c]));
    }

    public function test_number_format_not_validated_still_uses_the_technical_default(): void
    {
        $year = date('Y');
        foreach (['', 'sans-jeton', '{NNNNN}{NNNNN}', 'X-{YYYY}-{ABC}-{NNNNN}'] as $bad) {
            $this->setFormat($bad);
            $id = $this->svc->create($this->data(), $this->actor);
            $this->assertStringStartsWith("MMMGCI-$year-", (string) $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$id]), "format « $bad »");
        }
        $this->db->query("UPDATE settings SET `value` = NULL WHERE `group` = 'members' AND `key` = 'number_format'");
        $this->assertStringStartsWith("MMMGCI-$year-", (string) $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$this->svc->create($this->data(), $this->actor)]));
    }

    public function test_duplicate_detected_ignoring_case_and_accents(): void
    {
        $id = $this->svc->create($this->data(['last_name' => 'Éla', 'first_names' => 'Zoé  Marie']), $this->actor);
        $hit = $this->svc->findDuplicates(['last_name' => ' ELA ', 'first_names' => 'zoe marie', 'birth_date' => '12/05/1980']);
        $this->assertSame([$id], array_map('intval', array_column($hit, 'id')));
        $this->assertSame([], $this->svc->findDuplicates(['last_name' => 'Ela', 'first_names' => 'Zoe Marie', 'birth_date' => '1981-05-12']));
        $this->assertSame([], $this->svc->findDuplicates(['last_name' => 'Ela', 'first_names' => 'Zoe Marie', 'birth_date' => '1980-05-12'], $id));
    }

    public function test_duplicate_by_phone_with_different_formatting(): void
    {
        $id = $this->svc->create($this->data(['phone' => '07 12-34.56 78']), $this->actor);
        $this->assertSame('0712345678', $this->db->fetchValue('SELECT phone FROM members WHERE id = ?', [$id]));
        foreach (['0712345678', '+225 07 12 34 56 78', '00225 0712345678'] as $p) {
            $this->assertSame([$id], array_map('intval', array_column($this->svc->findDuplicates(['phone' => $p]), 'id')), $p);
        }
        $this->assertSame('+33612345678', MemberService::normalizePhone('+33 6 12 34 56 78'));
    }

    public function test_future_birth_date_is_refused(): void
    {
        foreach ([date('Y-m-d', strtotime('+2 days')), '1899-12-31'] as $d) {
            try {
                $this->svc->create($this->data(['birth_date' => $d]), $this->actor);
                $this->fail("$d accepté");
            } catch (DomainException $e) {
                $this->assertStringContainsString('naissance', $e->getMessage());
            }
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM members'));
    }

    public function test_member_without_phone_or_email_can_be_created(): void
    {
        $id = $this->svc->create(['last_name' => 'Diallo', 'first_names' => 'Awa'], $this->actor);
        $row = $this->db->fetch('SELECT phone, email, status FROM members WHERE id = ?', [$id]);
        $this->assertNull($row['phone']);
        $this->assertNull($row['email']);
        $this->assertSame('actif', $row['status']);
    }

    public function test_update_of_a_deleted_member_is_not_found(): void
    {
        $id = $this->svc->create($this->data(), $this->actor);
        $this->db->query('UPDATE members SET deleted_at = NOW() WHERE id = ?', [$id]);
        $this->expectException(\App\Core\NotFound::class);
        $this->svc->update($id, $this->data(), $this->actor);
    }
}
