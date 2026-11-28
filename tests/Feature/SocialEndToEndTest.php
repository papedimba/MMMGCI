<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Uploads;
use App\Modules\Social\SocialMemberView;
use Tests\Support\AdminTestCase;
use Tests\Support\DisbursementFixtures;
use ZipArchive;

/**
 * Parcours de bout en bout du lot L7, par les écrans et les rôles réels (delegue, gest_social, pca, tresorier_general), plus les rôles de test
 * dec_appr (deux approbateurs) et dec_pay (un payeur) : aucun rôle n'a disbursement.approve ni disbursement.pay à l'installation.
 * Toutes les écritures passent par l'application (clé APP_KEY) : la chaîne d'audit de la base de test est vérifiée à la fin de chaque scénario.
 */
final class SocialEndToEndTest extends AdminTestCase
{
    use DisbursementFixtures;

    private const SANTE = 'SENTINELLE-SANTE-7Q';
    private const LIBELLE = 'SENTINELLE-LIBELLE-7Q';

    private string $root;
    private int $zoneA;
    private int $memberA;
    private int $memberB;
    private int $delegueA;
    private int $delegueB;
    private int $gest;
    private int $pca;
    private int $treasurer;
    private int $typeId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-se2e-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0'); // chaque scénario part sans demande ni décaissement (le journal d'audit n'est jamais touché)
        foreach (['social_request_medical', 'social_request_documents', 'social_request_reviews', 'social_decisions', 'social_request_events', 'social_request_notes', 'social_requests',
            'disbursement_payments', 'financial_transactions', 'sensitive_access_logs'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->setUpCircuit(); // supprime les comptes : les rôles réels sont créés après
        $this->db->query("UPDATE financial_accounts SET is_active = 0 WHERE type = 'solidarity_fund'");
        $this->db->update('financial_accounts', ['type' => 'solidarity_fund'], ['id' => $this->account]);
        $n = bin2hex(random_bytes(3));
        $this->zoneA = $this->db->insert('zones', ['name' => "Zone A $n", 'type' => 'region']);
        $zoneB = $this->db->insert('zones', ['name' => "Zone B $n", 'type' => 'region']);
        $this->memberA = $this->db->insert('members', ['member_number' => "SE-A-$n", 'last_name' => 'Kouassi', 'first_names' => 'Ama', 'zone_id' => $this->zoneA, 'status' => 'actif']);
        $this->memberB = $this->db->insert('members', ['member_number' => "SE-B-$n", 'last_name' => 'Konan', 'first_names' => 'Yao', 'zone_id' => $zoneB, 'status' => 'actif']);
        $this->delegueA = $this->createUser("delA$n@se.test", ['delegue']);
        $this->delegueB = $this->createUser("delB$n@se.test", ['delegue']);
        $this->db->update('users', ['zone_id' => $this->zoneA], ['id' => $this->delegueA]);
        $this->db->update('users', ['zone_id' => $zoneB], ['id' => $this->delegueB]);
        $this->gest = $this->createUser("gest$n@se.test", ['gest_social']);
        $this->pca = $this->createUser("pca$n@se.test", ['pca']);
        $this->treasurer = $this->createUser("tres$n@se.test", ['tresorier_general']);
        $this->typeId = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'deces'");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function as(int $user): void
    {
        $this->actingAs($user);
        $this->services['gate']()->forget($user);
    }

    /** @return array<string,mixed> */
    private function pdf(string $name = 'piece.pdf'): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    private function social(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM social_requests WHERE id = ?', [$id]);
    }

    private function post(string $path, array $fields = [], array $files = []): int
    {
        return $this->request('POST', $path, $fields, $files)->status();
    }

    /** @return array<string,string> */
    private function hidden(string $url): array
    {
        preg_match_all('/<input type="hidden" name="(key|seen_revision|seen_amount)" value="([^"]*)"/', $this->request('GET', $url)->body(), $m);
        return $m[1] === [] ? [] : array_combine($m[1], $m[2]);
    }

    /** Le délégué de la zone A enregistre ; le gestionnaire affecte, instruit, émet un avis et soumet. Renvoie l'id de la demande soumise. */
    private function submittedRequest(): int
    {
        $this->as($this->delegueA);
        $this->assertSame(302, $this->post('/admin/demandes-sociales/nouvelle', ['member_id' => (string) $this->memberA, 'type_id' => (string) $this->typeId,
            'received_at' => date('Y-m-d'), 'channel' => 'delegue', 'priority' => 'normale', 'description' => 'Décès du conjoint : soutien demandé.']));
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM social_requests');
        $this->assertSame('recue', $this->social($id));
        $this->as($this->gest);
        $base = "/admin/demandes-sociales/$id";
        $this->assertSame(302, $this->post("$base/affecter", ['assignee' => (string) $this->gest]));
        $this->assertSame(302, $this->post("$base/avis", ['opinion' => 'favorable', 'recommended_amount' => '', 'comment' => 'Dossier complet']));
        $this->assertSame(302, $this->post("$base/soumettre"));
        $this->assertSame('soumise_decision', $this->social($id));
        return $id;
    }

    private function decide(int $id, array $fields): void
    {
        $this->as($this->pca);
        $this->assertSame(302, $this->post("/admin/demandes-sociales/$id/decision", $fields + ['reason' => 'Motif interne', 'member_message' => 'Message à l\'adhérent',
            'decision_ref' => 'PV-CA-2026-07', 'deciding_body' => 'Conseil d\'administration', 'decided_at' => '']));
    }

    private function grantFinancial(int $id): void
    {
        $this->decide($id, ['decision' => 'accordee', 'aid_kind' => 'financiere', 'amount' => '50 000']);
        $this->assertSame('accordee', $this->social($id));
    }

    /** Le gestionnaire (disbursement.initiate) prépare le brouillon, y joint un justificatif non médical et le soumet. Renvoie l'id du décaissement. */
    private function prepareAndSubmit(int $id): int
    {
        $this->as($this->gest);
        $this->assertSame(200, $this->request('GET', "/admin/demandes-sociales/$id/decaissement")->status());
        $this->assertSame(302, $this->post("/admin/demandes-sociales/$id/decaissement", ['account_id' => (string) $this->account, 'category_id' => (string) $this->category,
            'beneficiary_name' => 'Kouassi Ama']));
        $this->assertSame('en_paiement', $this->social($id));
        $d = $this->db->fetch("SELECT dr.* FROM disbursement_requests dr JOIN social_decisions sd ON sd.id = dr.social_decision_id
            WHERE sd.social_request_id = ? AND dr.status = 'brouillon'", [$id]);
        $this->assertNotNull($d);
        $did = (int) $d['id'];
        $this->assertSame('50000.00', $d['amount']);
        $this->assertSame($this->gest, (int) $d['initiated_by']);
        $fields = ['amount' => '50 000', 'beneficiary_name' => (string) $d['beneficiary_name'], 'account_id' => (string) $d['account_id'], 'category_id' => (string) $d['category_id'],
            'purpose' => (string) $d['purpose'], 'internal_note' => '', 'reason' => '', 'label' => 'Acte de décès'];
        $this->assertSame(200, $this->request('GET', "/admin/decaissements/$did/modifier")->status());
        // Montant d'un décaissement lié : figé par la décision.
        $this->assertSame(422, $this->post("/admin/decaissements/$did/modifier", ['amount' => '60 000'] + $fields));
        $this->assertSame('50000.00', (string) $this->db->fetchValue('SELECT amount FROM disbursement_requests WHERE id = ?', [$did]));
        $this->assertSame(302, $this->post("/admin/decaissements/$did/modifier", $fields, ['justificatif' => $this->pdf('acte.pdf')]));
        $this->assertSame(302, $this->post("/admin/decaissements/$did/soumettre"));
        $this->assertSame('soumis', $this->state($did));
        return $did;
    }

    private function approveVia(int $did, int $user): int
    {
        $this->as($user);
        return $this->post("/admin/decaissements/$did/approuver", ['seen_revision' => (string) $this->revision($did)]);
    }

    private function closeVia(int $id): void
    {
        $this->as($this->gest);
        $this->assertSame(302, $this->post("/admin/demandes-sociales/$id/cloturer", ['closure_note' => 'Dossier soldé']));
        $this->assertSame('cloturee', $this->social($id));
    }

    private function auditText(): string
    {
        return (string) $this->db->fetchValue("SELECT GROUP_CONCAT(CONCAT_WS('|', action, old_values, new_values) SEPARATOR '\n') FROM audit_logs");
    }

    private function assertChainIntact(): void
    {
        $this->assertNull($this->audit()->verify(), 'chaîne d\'audit intacte sur la base de test');
    }

    public function test_full_journey_from_delegate_to_payment_and_closure(): void
    {
        // 1. Le délégué de la zone A n'enregistre que dans sa zone.
        $this->as($this->delegueA);
        $this->assertSame(200, $this->request('GET', '/admin/demandes-sociales/nouvelle?membre=' . $this->memberA)->status());
        $this->assertSame(404, $this->request('GET', '/admin/demandes-sociales/nouvelle?membre=' . $this->memberB)->status());
        $this->assertSame(404, $this->post('/admin/demandes-sociales/nouvelle', ['member_id' => (string) $this->memberB, 'type_id' => (string) $this->typeId,
            'received_at' => date('Y-m-d'), 'channel' => 'delegue', 'priority' => 'normale', 'description' => 'Hors zone']));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_requests WHERE member_id = ?', [$this->memberB]));
        $this->assertSame(302, $this->post('/admin/demandes-sociales/nouvelle', ['member_id' => (string) $this->memberA, 'type_id' => (string) $this->typeId,
            'received_at' => date('Y-m-d'), 'channel' => 'delegue', 'priority' => 'urgente', 'description' => 'Décès du conjoint : soutien demandé.']));
        $id = (int) $this->db->fetchValue('SELECT MAX(id) FROM social_requests');
        $ref = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]);
        $this->assertMatchesRegularExpression('/^AS-\d{4}-\d{4}$/', $ref);
        $base = "/admin/demandes-sociales/$id";
        $this->assertSame(200, $this->request('GET', $base)->status());
        $this->assertStringContainsString($ref, $this->request('GET', '/admin/demandes-sociales')->body());
        // Saut d'étape : le délégué n'a pas social.decide ; le décideur ne peut pas décider une demande reçue.
        $this->assertSame(403, $this->post("$base/decision", ['decision' => 'accordee']));
        // Lecture hors zone : 404, liste vide.
        $this->as($this->delegueB);
        $this->assertSame(404, $this->request('GET', $base)->status());
        $this->assertStringNotContainsString($ref, $this->request('GET', '/admin/demandes-sociales')->body());
        $this->as($this->pca);
        $res = $this->request('POST', "$base/decision", ['decision' => 'accordee', 'aid_kind' => 'financiere', 'amount' => '50 000', 'reason' => 'x', 'member_message' => 'x',
            'decision_ref' => 'PV', 'deciding_body' => 'CA']);
        $this->assertSame(422, $res->status());
        $this->assertSame('recue', $this->social($id));

        // 2. Le gestionnaire affecte (e-mail : référence seule), saisit la santé et une pièce médicale.
        $this->as($this->gest);
        $this->assertSame(302, $this->post("$base/affecter", ['assignee' => (string) $this->gest]));
        $this->assertSame('en_instruction', $this->social($id));
        $this->assertCount(1, $this->mail->messages);
        $this->assertSame(302, $this->post("$base/medical", ['details' => self::SANTE]));
        $this->assertSame(302, $this->post("$base/pieces", ['label' => self::LIBELLE, 'is_medical' => '1', 'kind' => 'piece'], ['piece' => $this->pdf('certificat.pdf')]));
        $doc = (int) $this->db->fetchValue('SELECT id FROM social_request_documents WHERE social_request_id = ? AND is_medical = 1', [$id]);
        $this->assertGreaterThan(0, $doc);
        $this->assertSame(200, $this->request('GET', "$base/pieces/$doc")->status());
        // Décision sans avis : la soumission est refusée, la décision aussi (étape non atteinte).
        $this->assertSame(422, $this->post("$base/soumettre"));
        $this->as($this->pca);
        $this->assertSame(422, $this->post("$base/decision", ['decision' => 'accordee', 'aid_kind' => 'financiere', 'amount' => '50 000', 'reason' => 'x', 'member_message' => 'x',
            'decision_ref' => 'PV', 'deciding_body' => 'CA']));
        // Pièce médicale sans social.medical.view : 403 ; sa ligne n'apparaît pas.
        $this->assertSame(403, $this->request('GET', "$base/pieces/$doc")->status());
        $this->assertStringNotContainsString(self::LIBELLE, $this->request('GET', "$base?onglet=pieces")->body());
        $this->assertSame(403, $this->post("$base/medical/afficher"));

        // 3. Pièces demandées, reprise, avis, soumission.
        $this->as($this->gest);
        $this->assertSame(302, $this->post("$base/pieces-demandees", ['documents' => 'Acte de décès']));
        $this->assertSame('en_attente_pieces', $this->social($id));
        $this->assertSame(302, $this->post("$base/reprendre", ['comment' => 'Pièces reçues']));
        $this->assertSame('en_instruction', $this->social($id));
        $this->assertSame(302, $this->post("$base/avis", ['opinion' => 'favorable', 'recommended_amount' => '50 000', 'comment' => 'Dossier complet']));
        $this->assertSame(302, $this->post("$base/soumettre"));
        $this->assertSame('soumise_decision', $this->social($id));
        $this->assertSame(403, $this->post("$base/decision", ['decision' => 'accordee'])); // le gestionnaire ne décide pas

        // 4. Décision motivée par le PCA (social.decide) : aucun mouvement financier, aucun décaissement.
        $this->as($this->pca);
        $this->assertSame(422, $this->post("$base/decision", ['decision' => 'accordee', 'aid_kind' => 'financiere', 'amount' => '50 000', 'reason' => '', 'member_message' => '',
            'decision_ref' => 'PV-CA-2026-07', 'deciding_body' => 'CA']), 'motif et message obligatoires');
        $this->grantFinancial($id);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_requests WHERE social_decision_id IS NOT NULL'));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions'));
        $this->assertSame(403, $this->post("$base/decaissement", ['account_id' => (string) $this->account, 'category_id' => (string) $this->category, 'beneficiary_name' => 'X']));

        // 5. Préparation (brouillon seulement), justificatif, soumission ; les contournements sont refusés.
        $did = $this->prepareAndSubmit($id);
        $purpose = (string) $this->db->fetchValue('SELECT purpose FROM disbursement_requests WHERE id = ?', [$did]);
        $this->assertStringContainsString($ref, $purpose);
        foreach ([self::SANTE, self::LIBELLE, 'Décès', 'Motif interne'] as $s) {
            $this->assertStringNotContainsString($s, $purpose, 'objet neutre');
        }
        $this->as($this->gest);
        $this->assertSame(422, $this->post("$base/decaissement", ['account_id' => (string) $this->account, 'category_id' => (string) $this->category, 'beneficiary_name' => 'X']));
        $this->assertSame(422, $this->post("$base/decaissement/echec"), 'décaissement vivant');
        $this->assertSame(422, $this->post("$base/cloturer"), 'aide financière non payée');
        // Paiement direct et approbation par l'initiateur ou le trésorier : refusés (permissions L6).
        $this->assertSame(403, $this->approveVia($did, $this->gest));
        $this->assertSame(403, $this->post("/admin/decaissements/$did/payer", ['key' => bin2hex(random_bytes(16))]));
        $this->assertSame(403, $this->approveVia($did, $this->treasurer));
        $this->assertSame(403, $this->post("/admin/decaissements/$did/payer", ['key' => bin2hex(random_bytes(16))]));
        $this->assertSame(403, $this->approveVia($did, $this->pca));
        $this->db->query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'dec_appr'", [$this->gest]);
        $this->assertSame(422, $this->approveVia($did, $this->gest), 'l\'initiateur ne peut jamais approuver');
        $this->db->query("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = 'dec_appr'", [$this->gest]);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$did]));
        // Le trésorier voit l'origine sociale sur la page du décaissement.
        $this->as($this->treasurer);
        $this->assertStringContainsString('Aide sociale ' . $ref, $this->request('GET', "/admin/decaissements/$did")->body());

        // 6. Deux approbations distinctes, paiement par un tiers avec justificatif.
        $this->assertSame(302, $this->approveVia($did, $this->approverA));
        $this->assertSame(422, $this->approveVia($did, $this->approverA), 'même approbateur');
        $this->assertSame(302, $this->approveVia($did, $this->approverB));
        $this->assertSame('approuve_2', $this->state($did));
        $this->as($this->payer);
        $fields = $this->hidden("/admin/decaissements/$did/payer") + ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'external_ref' => 'VIR-AS', 'confirm' => '1'];
        $this->assertSame(302, $this->post("/admin/decaissements/$did/payer", $fields, ['preuve' => $this->pdf('preuve.pdf')]));
        $this->assertSame('paye', $this->state($did));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE direction = 'depense' AND source_type = 'disbursement' AND source_id = ?", [$did]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE direction = 'depense'"));

        // 7. Clôture ; la demande est en lecture seule.
        $this->closeVia($id);
        $this->as($this->gest);
        $this->assertDoesNotMatchRegularExpression('/data-action="/', $this->request('GET', $base)->body());
        foreach (["$base/affecter" => ['assignee' => (string) $this->gest], "$base/avis" => ['opinion' => 'favorable', 'comment' => 'x'], "$base/notes" => ['body' => 'x'],
            "$base/pieces-demandees" => ['documents' => 'x'], "$base/cloturer" => [], "$base/decaissement/echec" => [], "$base/medical" => ['details' => 'x']] as $url => $f) {
            $this->assertSame(422, $this->post($url, $f), $url);
        }
        $this->assertSame('cloturee', $this->social($id));
        $this->as($this->pca);
        $this->assertSame(302, $this->request('GET', "$base/decision")->status());

        // 8. La sentinelle de santé n'est ni dans l'audit, ni dans les e-mails, ni dans l'export, ni dans l'objet du décaissement.
        $this->as($this->gest);
        $csv = $this->request('GET', '/admin/demandes-sociales/export.csv')->body();
        $this->assertStringContainsString($ref, $csv);
        $zip = $this->root . '/x.xlsx';
        file_put_contents($zip, $this->request('GET', '/admin/demandes-sociales/export.xlsx')->body());
        $z = new ZipArchive();
        $this->assertTrue($z->open($zip));
        $sheet = (string) $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        $mails = implode("\n", array_map(static fn (array $m): string => $m['subject'] . $m['body'] . (string) base64_decode(preg_replace('~\s+~', '', $m['body']) ?? '', true), $this->mail->messages));
        foreach (['audit' => $this->auditText(), 'e-mails' => $mails, 'csv' => $csv, 'xlsx' => $sheet, 'objets' => (string) $this->db->fetchValue('SELECT GROUP_CONCAT(purpose) FROM disbursement_requests')] as $where => $text) {
            foreach ([self::SANTE, self::LIBELLE, 'Décès du conjoint', 'Dossier complet', 'Motif interne', 'Message à l\'adhérent', 'Dossier soldé'] as $s) {
                $this->assertStringNotContainsString($s, $text, "$s dans $where");
            }
        }
        $this->assertGreaterThan(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE field_group = 'donnees_medicales' AND user_id = ?", [$this->gest]));

        // 9. Vue adhérent : liste blanche seulement.
        $view = (new SocialMemberView($this->db))->requestsOf($this->memberA);
        $this->assertCount(1, $view);
        $this->assertSame(['amount_granted', 'decision', 'message', 'received_at', 'reference', 'status', 'type'], $this->sortedKeys($view[0]));
        $this->assertSame([$ref, 'Clôturée', 'Accordée', 'Message à l\'adhérent', 5_000_000], [$view[0]['reference'], $view[0]['status'], $view[0]['decision'], $view[0]['message'], $view[0]['amount_granted']]);
        $this->assertStringNotContainsString(self::SANTE, (string) json_encode($view));
        $this->assertChainIntact();
    }

    /** @param array<string,mixed> $a @return list<string> */
    private function sortedKeys(array $a): array
    {
        $k = array_keys($a);
        sort($k);
        return $k;
    }

    public function test_refusal_then_closure(): void
    {
        $id = $this->submittedRequest();
        $this->as($this->pca);
        $this->assertSame(422, $this->post("/admin/demandes-sociales/$id/decision", ['decision' => 'refusee', 'aid_kind' => 'financiere', 'amount' => '10 000', 'reason' => 'Hors critères',
            'member_message' => 'Refusée', 'decision_ref' => 'PV-R', 'deciding_body' => 'CA']), 'un refus ne porte ni sorte ni montant');
        $this->decide($id, ['decision' => 'refusee', 'aid_kind' => '', 'amount' => '']);
        $this->assertSame('refusee', $this->social($id));
        $this->as($this->gest);
        $this->assertSame(422, $this->post("/admin/demandes-sociales/$id/decaissement", ['account_id' => (string) $this->account, 'category_id' => (string) $this->category, 'beneficiary_name' => 'X']));
        $this->closeVia($id);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_requests'));
        $view = (new SocialMemberView($this->db))->requestsOf($this->memberA);
        $this->assertSame(['decision', 'message', 'received_at', 'reference', 'status', 'type'], $this->sortedKeys($view[0]));
        $this->assertSame('Refusée', $view[0]['decision']);
        $this->assertChainIntact();
    }

    public function test_guidance_aid_closes_directly(): void
    {
        $id = $this->submittedRequest();
        $this->decide($id, ['decision' => 'accordee', 'aid_kind' => 'orientation', 'amount' => '']);
        $this->assertSame('accordee', $this->social($id));
        $this->as($this->gest);
        $this->assertSame(302, $this->request('GET', "/admin/demandes-sociales/$id/decaissement")->status(), 'pas de décaissement pour une orientation');
        $this->closeVia($id);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_requests'));
        $this->assertArrayNotHasKey('amount_granted', (new SocialMemberView($this->db))->requestsOf($this->memberA)[0]);
        $this->assertChainIntact();
    }

    public function test_rejected_disbursement_returns_to_granted_and_can_be_prepared_again(): void
    {
        $id = $this->submittedRequest();
        $this->grantFinancial($id);
        $first = $this->prepareAndSubmit($id);
        $this->as($this->approverA);
        $this->assertSame(302, $this->post("/admin/decaissements/$first/rejeter", ['seen_revision' => (string) $this->revision($first), 'reason' => 'Pièce illisible']));
        $this->assertSame('rejete', $this->state($first));
        $this->assertSame('en_paiement', $this->social($id));
        $this->as($this->gest);
        $this->assertSame(422, $this->post("/admin/demandes-sociales/$id/cloturer"), 'rien n\'est payé');
        $this->assertSame(302, $this->post("/admin/demandes-sociales/$id/decaissement/echec"));
        $this->assertSame('accordee', $this->social($id));
        $second = $this->prepareAndSubmit($id);
        $this->assertNotSame($first, $second);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM disbursement_requests WHERE social_decision_id IS NOT NULL AND status NOT IN ('rejete', 'annule')"));
        // Nouveau circuit complet : les approbations du premier ne comptent pas.
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$second]));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_transactions'));
        $this->assertChainIntact();
    }
}
