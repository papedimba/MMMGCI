<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Gate;
use App\Modules\Members\MemberScope;
use App\Modules\Members\MemberSearch;
use Tests\DatabaseTestCase;

final class MemberSearchTest extends DatabaseTestCase
{
    private int $zoneA;
    private int $zoneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM members');
        $this->db->query('DELETE FROM users');
        $this->db->query('DELETE FROM zones');
        $this->zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $this->zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
    }

    private function user(string $role, ?int $zone): int
    {
        $id = $this->db->insert('users', ['name' => $role, 'email' => $role . random_int(1, 99999) . '@x.test', 'password' => 'x', 'zone_id' => $zone]);
        $this->db->query('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = ?', [$id, $role]);
        return $id;
    }

    private function search(): MemberSearch
    {
        return new MemberSearch($this->db, new MemberScope($this->db, new Gate($this->db)));
    }

    public function test_search_is_limited_to_the_users_zone(): void
    {
        $this->db->insert('members', ['member_number' => 'M1', 'last_name' => 'Kouassi', 'first_names' => 'Yao', 'zone_id' => $this->zoneA, 'phone' => '0712345678']);
        $this->db->insert('members', ['member_number' => 'M2', 'last_name' => 'Kouamé', 'first_names' => 'Awa', 'zone_id' => $this->zoneB]);
        $this->db->insert('members', ['member_number' => 'M3', 'last_name' => 'Kouadio', 'first_names' => 'Zoé', 'zone_id' => $this->zoneA, 'deleted_at' => '2026-01-01 00:00:00']);
        $delegate = $this->user('delegue', $this->zoneA);
        $all = $this->user('gest_adherents', null);
        $none = $this->user('delegue', null);
        $r = $this->search()->run($delegate, ['q' => 'kou'], 'name', 1);
        $this->assertSame(['M1'], array_column($r['rows'], 'member_number'));
        $this->assertSame(1, $r['total']);
        $this->assertSame(2, $this->search()->run($all, ['q' => 'kou'], 'name', 1)['total']);
        $this->assertSame(0, $this->search()->run($none, [], 'name', 1)['total']);
        $this->assertSame(1, $this->search()->run($all, ['q' => '07 12 34'], 'name', 1)['total'], 'téléphone');
        $this->assertSame(1, $this->search()->run($all, ['q' => 'M2'], 'name', 1)['total'], 'numéro');
        $this->assertSame(1, $this->search()->run($all, ['q' => 'awa kouam'], 'name', 1)['total'], 'prénom + nom');
        $this->assertSame(0, $this->search()->run($all, ['q' => "%"], 'name', 1)['total'], 'jokers échappés');
        $this->assertSame(['M2', 'M1'], array_column($this->search()->run($all, [], 'bogus; DROP', 1)['rows'], 'member_number'), 'tri inconnu : nom');
    }

    public function test_search_under_one_second_on_2000_members(): void
    {
        $this->db->query('DELETE FROM sequences');
        foreach (array_chunk(range(1, 2000), 500) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $i) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?)';
                array_push($params, sprintf('P-%05d', $i), 'Nom' . ($i % 700), 'Prenom' . $i, sprintf('07%08d', $i), $this->zoneA, 'actif', date('Y-m-d', strtotime("2020-01-01 +$i days")));
            }
            $this->db->query('INSERT INTO members (member_number, last_name, first_names, phone, zone_id, status, joined_at) VALUES ' . implode(', ', $rows), $params);
        }
        $admin = $this->user('gest_adherents', null);
        $worst = 0.0;
        foreach ([['q' => 'nom12'], ['q' => 'Prenom19 nom'], ['q' => '0700001'], ['q' => 'P-0012'], []] as $c) {
            $t = microtime(true);
            $r = $this->search()->run($admin, $c, 'name', 2);
            $worst = max($worst, microtime(true) - $t);
        }
        fwrite(STDERR, sprintf("\n[perf] recherche 2000 adhérents, pire cas : %.1f ms\n", $worst * 1000));
        $this->assertLessThan(1.0, $worst);
        $this->assertSame(2000, $r['total']);
        $this->assertCount(50, $r['rows']);
        $this->assertSame(40, $r['pages']);
    }
}
