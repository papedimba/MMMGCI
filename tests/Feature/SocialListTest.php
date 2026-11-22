<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;
use Tests\Support\SocialFixtures;

/** Liste, recherche et onglet de la fiche adhérent : périmètre MemberScope, 404 sans fuite, aucune donnée médicale ni description. */
final class SocialListTest extends AdminTestCase
{
    use SocialFixtures;

    private int $zoneViewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
        $this->zoneViewer = $this->socialUser('zoneview', ['so_zone'], $this->zoneA);
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('so_zview', 'so_zview')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_zview' AND p.name = 'social.view'");
        $this->db->query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'so_zview'", [$this->zoneViewer]);
    }

    private function as(int $user): void
    {
        $this->actingAs($user);
        $this->services['gate']()->forget($user);
    }

    private function list(string $qs = ''): string
    {
        $res = $this->request('GET', '/admin/demandes-sociales' . $qs);
        self::assertSame(200, $res->status());
        return $res->body();
    }

    public function test_list_limited_to_scope_and_404_free(): void
    {
        $a = $this->registerRequest();
        $b = $this->registerRequest(['member_id' => $this->memberB]);
        $refA = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$a]);
        $refB = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$b]);
        $this->as($this->zoneViewer);
        $body = $this->list();
        self::assertStringContainsString($refA, $body);
        self::assertStringNotContainsString($refB, $body);
        self::assertStringNotContainsString('Demande d&#039;aide', $body); // jamais la description
        // Rechercher la référence d'une autre zone ne la révèle pas ; compte sans zone : rien ; compte désactivé : rien.
        self::assertStringContainsString('0 demande.', $this->list('?q=' . $refB));
        $this->as($this->socialUser('nozone', ['so_zview']));
        self::assertStringNotContainsString($refA, $this->list());
        $off = $this->socialUser('off', ['so_view'], null, false);
        $this->as($off);
        self::assertSame(302, $this->request('GET', '/admin/demandes-sociales')->status()); // pas de session pour un compte désactivé
        // Une demande de la zone B reste 404 pour le délégué de A (page), comme avant.
        $this->as($this->zoneViewer);
        self::assertSame(404, $this->request('GET', "/admin/demandes-sociales/$b")->status());
    }

    public function test_delegate_of_zone_a_never_sees_zone_b(): void
    {
        $this->registerRequest(['member_id' => $this->memberB]);
        $this->as($this->zoneViewer);
        foreach (['', '?zone_id=' . $this->zoneB, '?q=Konan', '?status=recue'] as $qs) {
            $body = $this->list($qs);
            self::assertStringContainsString('0 demande.', $body, $qs);
            self::assertStringNotContainsString('/admin/adherents/' . $this->memberB, $body, $qs);
        }
    }

    public function test_filters_and_sort_on_known_dataset(): void
    {
        $normal = $this->registerRequest(['received_at' => '2026-01-10']);
        $old = $this->registerRequest(['received_at' => '2026-01-02']);
        $urgent = $this->registerRequest(['received_at' => '2026-01-01', 'priority' => 'urgente']);
        $this->socialSvc()->assign($old, $this->manager, $this->manager);
        $ref = fn (int $id): string => (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]);
        $this->as($this->manager);
        $body = $this->list();
        self::assertLessThan(strpos($body, $ref($normal)), strpos($body, $ref($urgent)), 'urgente d\'abord');
        self::assertLessThan(strpos($body, $ref($old)), strpos($body, $ref($normal)), 'puis date de réception décroissante');
        $only = fn (string $qs, array $in, array $out) => array_map(function (int $id) use ($qs, $in) {
            self::assertStringContainsString($this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]), $this->list($qs), $qs);
        }, $in) + array_map(function (int $id) use ($qs) {
            self::assertStringNotContainsString($this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]), $this->list($qs), $qs);
        }, $out);
        $only('?priority=urgente', [$urgent], [$normal, $old]);
        $only('?status=en_instruction', [$old], [$normal, $urgent]);
        $only('?assignee=moi', [$old], [$normal, $urgent]);
        $only('?assignee=non', [$normal, $urgent], [$old]);
        $only('?from=2026-01-05', [$normal], [$old, $urgent]);
        $only('?to=2026-01-02', [$old, $urgent], [$normal]);
        $only('?type_id=' . $this->typeId, [$normal, $old, $urgent], []);
        $only('?q=' . $ref($old), [$old], [$normal, $urgent]);
        $only('?q=Kouassi+Ama', [$normal, $old, $urgent], []);
        $only('?status[]=x&priority=zz', [$normal, $old, $urgent], []); // critère forgé ignoré, jamais d'erreur
    }

    public function test_zone_filter_ignored_without_scope_all(): void
    {
        $a = $this->registerRequest();
        $refA = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$a]);
        $this->as($this->zoneViewer);
        self::assertStringContainsString($refA, $this->list('?zone_id=' . $this->zoneB)); // ignoré : sa zone seulement
        self::assertStringNotContainsString('name="zone_id"', $this->list());
        $this->as($this->manager); // members.scope.all
        self::assertStringContainsString('name="zone_id"', $this->list());
        self::assertStringNotContainsString($refA, $this->list('?zone_id=' . $this->zoneB));
        self::assertStringContainsString($refA, $this->list('?zone_id=' . $this->zoneA));
    }

    public function test_member_tab_lists_only_this_member_requests(): void
    {
        $a = $this->registerRequest(['description' => 'SENTINELLE-DESCRIPTION']);
        $b = $this->registerRequest(['member_id' => $this->memberB]);
        $ref = fn (int $id): string => (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]);
        foreach (['so_tab' => ['social.view'], 'so_tabc' => ['social.view', 'social.create']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ([...$perms, 'members.view', 'members.scope.all'] as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $this->as($this->socialUser('tab', ['so_tab']));
        $res = $this->request('GET', "/admin/adherents/{$this->memberA}?onglet=demandes");
        self::assertSame(200, $res->status());
        self::assertStringContainsString($ref($a), $res->body());
        self::assertStringNotContainsString($ref($b), $res->body());
        self::assertStringNotContainsString('SENTINELLE', $res->body());
        self::assertStringNotContainsString('Nouvelle demande', $res->body()); // pas social.create
        $this->as($this->socialUser('tabc', ['so_tabc']));
        self::assertStringContainsString('?membre=' . $this->memberA, $this->request('GET', "/admin/adherents/{$this->memberA}?onglet=demandes")->body());
        // Hors périmètre : la fiche est introuvable ; sans social.view l'onglet n'existe pas.
        $zv = $this->socialUser('tabz', ['so_tabc'], $this->zoneA);
        $this->db->query("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name = 'so_tabc' AND p.name = 'members.scope.all'");
        $this->as($zv);
        self::assertSame(404, $this->request('GET', "/admin/adherents/{$this->memberB}?onglet=demandes")->status());
        $mv = $this->createUser('mv@x.test', ['secretaire_general']);
        $this->db->query("DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id JOIN roles r ON r.id = rp.role_id WHERE r.name = 'secretaire_general' AND p.name = 'social.view'");
        $this->as($mv);
        $body = $this->request('GET', "/admin/adherents/{$this->memberA}?onglet=demandes")->body();
        self::assertStringNotContainsString($ref($a), $body);
        self::assertStringNotContainsString('onglet=demandes', $body);
    }
}
