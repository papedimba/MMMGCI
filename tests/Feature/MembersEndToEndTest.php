<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\AdminTestCase;

/** Parcours complet du lot L5 : demande d'un délégué, validation, périmètre, statut, export, carte, vérification publique. */
final class MembersEndToEndTest extends AdminTestCase
{
    public function test_full_member_lifecycle_respects_delegate_scope(): void
    {
        foreach (['membership_cards', 'sensitive_access_logs', 'member_documents', 'membership_application_files', 'membership_applications', 'member_status_history', 'member_beneficiaries', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $zoneA = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'region']);
        $zoneB = $this->db->insert('zones', ['name' => 'San-Pédro', 'type' => 'port']);
        // Le délégué réel n'a pas members.export ni members.update : un rôle de test les ajoute pour vérifier le périmètre de ces routes.
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('e2e_extra', 'E2E')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'e2e_extra' AND p.name IN ('members.export', 'members.update')");
        $delegateA = $this->createUser('da@x.test', ['delegue', 'e2e_extra']);
        $delegateB = $this->createUser('db@x.test', ['delegue', 'e2e_extra']);
        $this->db->update('users', ['zone_id' => $zoneA], ['id' => $delegateA]);
        $this->db->update('users', ['zone_id' => $zoneB], ['id' => $delegateB]);
        $manager = $this->createUser('g@x.test', ['gest_adherents']);

        // 1. Le délégué A saisit une demande ; la zone est imposée par le serveur.
        $this->actingAs($delegateA);
        $res = $this->request('POST', '/admin/adherents/demandes/nouvelle', ['last_name' => 'Bamba', 'first_names' => 'Awa', 'birth_date' => '1985-03-02', 'phone' => '0707070707', 'zone_id' => (string) $zoneB]);
        $this->assertSame(302, $res->status());
        $app = $this->db->fetch('SELECT * FROM membership_applications');
        $this->assertSame('recue', $app['status']);
        $this->assertSame((string) $zoneA, (string) json_decode($app['payload'], true)['zone_id']);

        // 2. Le gestionnaire valide : la fiche est créée.
        $this->actingAs($manager);
        $this->assertSame(302, $this->request('POST', "/admin/adherents/demandes/{$app['id']}/valider")->status());
        $m = (int) $this->db->fetchValue('SELECT member_id FROM membership_applications WHERE id = ?', [$app['id']]);
        $this->assertGreaterThan(0, $m);
        $this->assertSame('actif', $this->db->fetchValue('SELECT status FROM members WHERE id = ?', [$m]));

        // 3. Visible du délégué de la zone A, invisible du délégué de la zone B (liste, fiche, export, impression, carte).
        $this->actingAs($delegateA);
        $this->assertStringContainsString('Bamba', $this->request('GET', '/admin/adherents')->body());
        $this->assertSame(200, $this->request('GET', "/admin/adherents/$m")->status());
        $this->assertStringContainsString('Bamba', $this->request('GET', '/admin/adherents/export.csv')->body());
        $this->assertSame(200, $this->request('GET', "/admin/adherents/$m/carte")->status());
        $this->actingAs($delegateB);
        $this->assertStringNotContainsString('Bamba', $this->request('GET', '/admin/adherents')->body());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$m")->status());
        $this->assertStringNotContainsString('Bamba', $this->request('GET', '/admin/adherents/export.csv')->body());
        $this->assertStringNotContainsString('Bamba', $this->request('GET', '/admin/adherents/imprimer')->body());
        $this->assertSame(404, $this->request('GET', "/admin/adherents/$m/carte")->status());
        $this->assertSame(404, $this->request('POST', "/admin/adherents/$m/carte")->status());

        // 4. Changement de statut par le gestionnaire, historisé (3 lignes : création, suspension, réactivation).
        $this->actingAs($manager);
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$m/statut", ['to' => 'suspendu', 'reason' => 'Contrôle', 'decision_ref' => 'PV-1'])->status());
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$m/statut", ['to' => 'actif', 'reason' => 'Régularisé', 'decision_ref' => 'PV-2'])->status());
        $this->assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM member_status_history WHERE member_id = ?', [$m]));

        // 5. Carte délivrée ; vérification publique valide, sans connexion.
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$m/carte")->status());
        $token = (string) $this->db->fetchValue('SELECT verify_token FROM membership_cards WHERE member_id = ? AND revoked_at IS NULL', [$m]);
        $this->services['session']->set('auth.user_id', null);
        $ok = $this->request('GET', "/verifier/$token");
        $this->assertStringContainsString('Carte valide', $ok->body());

        // 6. Radié : la vérification devient neutre.
        $this->actingAs($manager);
        $this->assertSame(302, $this->request('POST', "/admin/adherents/$m/statut", ['to' => 'radie', 'reason' => 'Décision', 'decision_ref' => 'PV-3'])->status());
        $this->services['session']->set('auth.user_id', null);
        $body = $this->request('GET', "/verifier/$token")->body();
        $this->assertStringContainsString('Carte non valide', $body);
        $this->assertStringNotContainsString('Bamba', $body);
    }
}
