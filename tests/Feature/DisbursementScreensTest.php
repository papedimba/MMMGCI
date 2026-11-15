<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Http\PermissionGuard;
use App\Core\Uploads;
use App\Modules\Disbursements\DisbursementBusy;
use App\Modules\Disbursements\DisbursementGate;
use App\Modules\Disbursements\DisbursementService;
use Tests\Support\AdminTestCase;
use Tests\Support\DisbursementFixtures;

/** Écrans du circuit de décaissement : aucune règle dans l'écran, le service décide ; affichage fidèle, erreurs métier lisibles. */
final class DisbursementScreensTest extends AdminTestCase
{
    use DisbursementFixtures;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-dec-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        $this->db->query('DELETE FROM sensitive_access_logs');
        $this->setUpCircuit();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @return array<string,mixed> entrée de $_FILES (PDF valide) */
    private function pdf(string $name = 'facture.pdf'): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    private function as(int $user): void
    {
        $this->actingAs($user);
        $this->services['gate']()->forget($user);
    }

    /** @return array<string,string> champs du formulaire de paiement tels que la page les a rendus */
    private function payFields(int $id, string $key = ''): array
    {
        $html = $this->request('GET', "/admin/decaissements/$id/payer")->body();
        preg_match_all('/<input type="hidden" name="(key|seen_revision|seen_amount)" value="([^"]*)"/', $html, $m);
        $f = array_combine($m[1], $m[2]);
        return ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'external_ref' => '', 'confirm' => '1'] + ($key !== '' ? ['key' => $key] : []) + $f;
    }

    /** @return list<string> */
    private function renderedActions(int $id): array
    {
        $res = $this->request('GET', "/admin/decaissements/$id");
        $this->assertSame(200, $res->status());
        preg_match_all('/data-action="([a-z]+)"/', $res->body(), $m);
        $a = array_values(array_unique($m[1]));
        sort($a);
        return $a;
    }

    public function test_routes_require_the_right_permission_each(): void
    {
        $this->app();
        $expected = [
            'GET /admin/decaissements' => 'read', 'GET /admin/decaissements/inoperant' => 'read', 'GET /admin/decaissements/{id:\d+}' => 'read',
            'GET /admin/decaissements/{id:\d+}/pieces/{fid:\d+}' => 'read',
            'GET /admin/decaissements/nouveau' => 'disbursement.initiate', 'POST /admin/decaissements/nouveau' => 'disbursement.initiate',
            'GET /admin/decaissements/{id:\d+}/modifier' => 'disbursement.initiate', 'POST /admin/decaissements/{id:\d+}/modifier' => 'disbursement.initiate',
            'POST /admin/decaissements/{id:\d+}/soumettre' => 'disbursement.initiate', 'GET /admin/decaissements/{id:\d+}/annuler' => 'disbursement.initiate',
            'POST /admin/decaissements/{id:\d+}/annuler' => 'disbursement.initiate', 'POST /admin/decaissements/{id:\d+}/approuver' => 'disbursement.approve',
            'GET /admin/decaissements/{id:\d+}/rejeter' => 'disbursement.approve', 'POST /admin/decaissements/{id:\d+}/rejeter' => 'disbursement.approve',
            'GET /admin/decaissements/{id:\d+}/payer' => 'disbursement.pay', 'POST /admin/decaissements/{id:\d+}/payer' => 'disbursement.pay',
        ];
        $found = [];
        foreach ($this->services['router']->routes() as $r) {
            if (str_starts_with($r['pattern'], '/admin/decaissements')) {
                $g = array_values(array_filter($r['middleware'], static fn ($m) => $m instanceof PermissionGuard))[0];
                $found["{$r['method']} {$r['pattern']}"] = $g->alternatives === [] ? $g->permission
                    : ([$g->permission, ...$g->alternatives] === ['finance.view', 'finance.control', 'disbursement.initiate', 'disbursement.approve', 'disbursement.pay'] ? 'read' : 'autre');
            }
        }
        ksort($expected);
        ksort($found);
        $this->assertSame($expected, $found);

        // Comportement : chaque rôle n'atteint que ses routes ; la lecture est ouverte aux acteurs du circuit et aux finances.
        $submitted = $this->submitted();
        $this->as($this->payer);
        $this->assertSame(403, $this->request('GET', '/admin/decaissements/nouveau')->status());
        $this->assertSame(403, $this->request('POST', "/admin/decaissements/$submitted/approuver", ['seen_revision' => '1'])->status());
        $this->assertSame(200, $this->request('GET', "/admin/decaissements/$submitted")->status());
        $this->as($this->initiator);
        $this->assertSame(403, $this->request('POST', "/admin/decaissements/$submitted/approuver", ['seen_revision' => '1'])->status());
        $this->assertSame(403, $this->request('GET', "/admin/decaissements/$submitted/payer")->status());
        $this->as($this->approverA);
        $this->assertSame(403, $this->request('POST', "/admin/decaissements/$submitted/payer")->status());
        $this->assertSame(403, $this->request('POST', "/admin/decaissements/$submitted/soumettre")->status());
        $this->as($this->user('sansrole', []));
        $this->assertSame(403, $this->request('GET', '/admin/decaissements')->status());
        // Initiateur seul : uniquement ses propres demandes (liste et page ; autre demande = 404).
        $other = $this->user('init2', ['dec_init']);
        $this->as($other);
        $this->assertSame(404, $this->request('GET', "/admin/decaissements/$submitted")->status());
        $this->assertStringNotContainsString('Veuve Kouassi', $this->request('GET', '/admin/decaissements')->body());
        $this->assertGreaterThan(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'access.denied' AND user_id = ? AND new_values LIKE '%disbursement.pay%'", [$this->approverA]));
        // finance.view seul : lecture, aucun bouton.
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (\'dec_fin_view\', \'dec_fin_view\')');
        $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = \'dec_fin_view\' AND p.name = \'finance.view\'');
        $this->as($this->user('lecteur', ['dec_fin_view']));
        $this->assertSame([], $this->renderedActions($submitted));
        $this->assertStringContainsString('Veuve Kouassi', $this->request('GET', '/admin/decaissements')->body());
    }

    public function test_buttons_match_service_authorisation(): void
    {
        $draft = $this->draft();
        $draftWithFile = $this->draft();
        $this->svc()->revise($draftWithFile, ['add_files' => [$this->file() => 'Devis']], '', $this->initiator);
        $submitted = $this->submitted();
        $one = $this->submitted();
        $this->approveNow($one, $this->approverA);
        $two = $this->approved();
        $paid = $this->approved();
        $this->payNow($paid);
        $rejected = $this->submitted();
        $this->rejectNow($rejected, $this->approverA, 'Hors objet');
        $cancelled = $this->submitted();
        $this->svc()->cancel($cancelled, $this->initiator);
        $seen = [];
        foreach ([$this->initiator, $this->approverA, $this->approverB, $this->payer] as $user) {
            $this->as($user);
            foreach ([$draft, $draftWithFile, $submitted, $one, $two, $paid, $rejected, $cancelled] as $id) {
                $allowed = $this->svc()->allowedActions($id, $user);
                sort($allowed);
                $this->assertSame($allowed, $this->renderedActions($id), "utilisateur $user, demande {$this->state($id)}");
                $seen = [...$seen, ...$allowed];
            }
        }
        // Le jeu couvre chaque action au moins une fois (sinon la comparaison serait vide de sens).
        $this->assertEqualsCanonicalizing(DisbursementService::ACTIONS, array_values(array_unique($seen)));
        $this->assertNotContains('cancel', $this->svc()->allowedActions($draft, $this->initiator), 'un brouillon n\'est pas annulable');
    }

    public function test_not_operational_page_when_fewer_than_two_approvers(): void
    {
        $submitted = $this->submitted();
        $this->db->query('DELETE FROM user_roles WHERE user_id = ?', [$this->approverB]);
        $this->as($this->approverA);
        $page = $this->request('GET', '/admin/decaissements/inoperant');
        $this->assertSame(409, $page->status());
        $this->assertStringContainsString('deux personnes distinctes', $page->body());
        $this->assertStringContainsString('une troisième personne', $page->body());
        $this->assertStringContainsString('Circuit inopérant', $this->request('GET', '/admin/decaissements')->body());
        $this->assertStringContainsString('Circuit inopérant', $this->request('GET', "/admin/decaissements/$submitted")->body());
        // Page d'action (GET) : explication ; POST : message explicite, rien d'écrit.
        $this->assertStringContainsString(e('Ce que doit faire l\'installateur'), $this->request('GET', "/admin/decaissements/$submitted/rejeter")->body());
        $res = $this->request('POST', "/admin/decaissements/$submitted/approuver", ['seen_revision' => '1']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(DisbursementGate::MESSAGE), $res->body());
        $this->assertSame('soumis', $this->state($submitted));
    }

    public function test_forged_post_to_pay_without_approvals_is_refused_with_clear_message(): void
    {
        $submitted = $this->submitted();
        $one = $this->submitted();
        $this->approveNow($one, $this->approverA);
        $this->as($this->payer);
        foreach ([$submitted => 'soumise', $one => "en cours d'approbation"] as $id => $label) {
            $res = $this->request('POST', "/admin/decaissements/$id/payer", ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'key' => bin2hex(random_bytes(16)),
                'seen_revision' => '1', 'seen_amount' => '5000000'], ['preuve' => $this->pdf()]);
            $this->assertSame(422, $res->status());
            $this->assertStringContainsString(e('Impossible de payer cette demande : elle est ' . $label . '.'), $res->body());
            $this->assertStringNotContainsString('Exception', $res->body());
        }
        // GET de la page de paiement : refusé (pas de formulaire), message à la place.
        $this->assertSame(302, $this->request('GET', "/admin/decaissements/$submitted/payer")->status());
        // Approbation forgée par l'initiateur détenant aussi disbursement.approve.
        $both = $this->user('initappr', ['dec_init_appr']);
        $own = $this->submitted(5_000_000, $both);
        $this->as($both);
        $res = $this->request('POST', "/admin/decaissements/$own/approuver", ['seen_revision' => '1']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e('L\'initiateur d\'une demande ne peut jamais l\'approuver.'), $res->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$own]));
    }

    public function test_validation_steps_show_actual_state_and_actors(): void
    {
        $this->db->query("UPDATE users SET name = 'Awa Approbatrice' WHERE id = ?", [$this->approverA]);
        $this->db->query("UPDATE users SET name = 'Bakary Approbateur' WHERE id = ?", [$this->approverB]);
        $this->db->query("UPDATE users SET name = 'Paul Payeur' WHERE id = ?", [$this->payer]);
        $steps = function (int $id): array {
            preg_match('#<ol class="steps".*?</ol>#s', $this->request('GET', "/admin/decaissements/$id")->body(), $m);
            preg_match_all('#<li class="step step-(\w+)".*?<strong>(.*?)</strong>.*?</li>#s', $m[0], $li, PREG_SET_ORDER);
            return array_map(static fn (array $x): string => $x[1] . ':' . html_entity_decode($x[2]) . (preg_match('#class="step-meta">([^<·]*)#', $x[0], $w) ? ':' . trim($w[1]) : ''), $li);
        };
        $this->as($this->payer);
        $draft = $this->draft();
        $this->assertSame(['current:Demande initiée:init', 'todo:1re approbation', 'todo:2e approbation', 'todo:Paiement'], $steps($draft));
        $id = $this->submitted();
        $this->assertSame(['done:Demande initiée:init', 'current:1re approbation', 'todo:2e approbation', 'todo:Paiement'], $steps($id));
        $this->approveNow($id, $this->approverA);
        $this->assertSame(['done:Demande initiée:init', 'done:1re approbation:Awa Approbatrice', 'current:2e approbation', 'todo:Paiement'], $steps($id));
        $this->approveNow($id, $this->approverB);
        $this->assertSame(['done:Demande initiée:init', 'done:1re approbation:Awa Approbatrice', 'done:2e approbation:Bakary Approbateur', 'current:Paiement'], $steps($id));
        $this->payNow($id);
        $this->assertSame(['done:Demande initiée:init', 'done:1re approbation:Awa Approbatrice', 'done:2e approbation:Bakary Approbateur', 'done:Payée:Paul Payeur'], $steps($id));
        $rej = $this->submitted();
        $this->approveNow($rej, $this->approverA);
        $this->rejectNow($rej, $this->approverB, 'Pièce illisible');
        $this->assertSame(['done:Demande initiée:init', 'done:1re approbation:Awa Approbatrice', 'rejected:2e approbation : rejetée:Bakary Approbateur', 'todo:Paiement'], $steps($rej));
        $this->assertStringContainsString('Pièce illisible', $this->request('GET', "/admin/decaissements/$rej")->body());
        // Révision : approbation caduque affichée dans l'historique, état courant revenu à « soumis » sans approbation valide.
        $rev = $this->submitted();
        $this->approveNow($rev, $this->approverA);
        $this->svc()->revise($rev, ['amount' => 6_000_000], 'Montant corrigé', $this->initiator);
        $this->assertSame(['done:Demande initiée:init', 'current:1re approbation', 'todo:2e approbation', 'todo:Paiement'], $steps($rev));
        $body = $this->request('GET', "/admin/decaissements/$rev")->body();
        $this->assertStringContainsString('Caduque (demande modifiée)', $body);
        $this->assertStringContainsString('Montant corrigé', $body);
        $this->assertStringContainsString('0 sur 2 requises', $body);
        $this->assertStringNotContainsString('Il manque des approbateurs possibles', $body);
        // Blocage : tranche à 3 approbations, 2 approbateurs possibles ; après la 1re, il en manque 2 pour 1 restant.
        $this->db->query('UPDATE approval_rules SET approvals_required = 3');
        $three = $this->submitted();
        $this->approveNow($three, $this->approverA);
        $body = $this->request('GET', "/admin/decaissements/$three")->body();
        $this->assertStringContainsString('Il manque des approbateurs possibles : désignez-en un autre', $body);
        $this->assertStringContainsString('3e approbation', $body);
    }

    public function test_hostile_beneficiary_and_purpose_are_escaped(): void
    {
        $this->as($this->initiator);
        $hostile = '<script>alert(1)</script>';
        $res = $this->request('POST', '/admin/decaissements/nouveau', ['amount' => '150 000', 'beneficiary_name' => $hostile, 'account_id' => (string) $this->account,
            'category_id' => (string) $this->category, 'purpose' => '"><img src=x onerror=alert(2)>', 'internal_note' => '<b>note</b>', 'label' => '<i>lib</i>'], ['justificatif' => $this->pdf('<x>.pdf')]);
        $this->assertSame(302, $res->status());
        $id = (int) $this->db->fetchValue('SELECT id FROM disbursement_requests ORDER BY id DESC LIMIT 1');
        $this->assertSame('150000.00', $this->db->fetchValue('SELECT amount FROM disbursement_requests WHERE id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_attachments f JOIN files x ON x.id = f.file_id WHERE f.attachable_type = 'disbursement_request' AND f.attachable_id = ? AND x.uploaded_by = ?", [$id, $this->initiator]));
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/soumettre")->status());
        foreach ([$this->initiator => [], $this->approverA => ["/admin/decaissements/$id/rejeter"]] as $u => $more) {
            $this->as($u);
            foreach (["/admin/decaissements/$id", '/admin/decaissements', ...$more] as $url) {
                $body = $this->request('GET', $url)->body();
                $this->assertStringNotContainsString('<script>alert', $body, $url);
                $this->assertStringNotContainsString('<img src=x', $body, $url);
                $this->assertStringNotContainsString('<b>note', $body, $url);
                $this->assertStringNotContainsString('<i>lib', $body, $url);
                $this->assertStringContainsString('&lt;script&gt;', $body, $url);
                $this->assertSame(1, substr_count($body, '<h1'), $url);
                $this->assertStringNotContainsString('style="', $body, $url);
            }
        }
    }

    public function test_proof_download_is_logged_and_protected(): void
    {
        $id = $this->approved();
        $other = $this->approved();
        $this->payNow($other);
        $this->as($this->payer);
        $res = $this->request('POST', "/admin/decaissements/$id/payer", $this->payFields($id), ['preuve' => $this->pdf('recu signé.pdf')]);
        $this->assertSame(302, $res->status());
        $this->assertSame('paye', $this->state($id));
        $proof = (int) $this->db->fetchValue('SELECT proof_file_id FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]);
        $this->assertSame($this->payer, (int) $this->db->fetchValue('SELECT uploaded_by FROM files WHERE id = ?', [$proof]));
        $this->assertSame('127.0.0.1', $this->db->fetchValue("SELECT ip FROM audit_logs WHERE action = 'disbursement.paid' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$id]));

        $dl = $this->request('GET', "/admin/decaissements/$id/pieces/$proof");
        $this->assertSame(200, $dl->status());
        $this->assertStringStartsWith('%PDF-', $dl->body());
        $this->assertStringStartsWith('attachment;', $dl->headers()['Content-Disposition']);
        $this->assertStringContainsString('no-store', $dl->headers()['Cache-Control']);
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE user_id = ? AND subject_type = 'disbursement_request' AND subject_id = ? AND field_group = 'preuve_paiement'", [$this->payer, $id]));

        // Fichier d'une autre demande, ou fichier quelconque : 404, rien de journalisé.
        $otherProof = (int) $this->db->fetchValue('SELECT proof_file_id FROM disbursement_payments WHERE disbursement_request_id = ?', [$other]);
        $this->assertSame(404, $this->request('GET', "/admin/decaissements/$id/pieces/$otherProof")->status());
        $this->assertSame(404, $this->request('GET', "/admin/decaissements/$id/pieces/" . $this->file())->status());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
        // Justificatif (initiateur) ; initiateur d'une autre demande sans droit de lecture général : 404.
        $att = (int) $this->db->fetchValue("SELECT file_id FROM financial_attachments WHERE attachable_type = 'disbursement_request' AND attachable_id = ?", [$id]);
        $this->as($this->initiator);
        $this->assertSame(404, $this->request('GET', "/admin/decaissements/$id/pieces/$att")->status(), 'fichier factice de la fixture absent du disque : 404 propre');
        $this->as($this->user('init2', ['dec_init']));
        $this->assertSame(404, $this->request('GET', "/admin/decaissements/$id/pieces/$proof")->status());
        $this->as($this->user('sansrole', []));
        $this->assertSame(403, $this->request('GET', "/admin/decaissements/$id/pieces/$proof")->status());
    }

    public function test_double_submit_of_payment_form_pays_once(): void
    {
        $id = $this->approved();
        $this->as($this->payer);
        $fields = $this->payFields($id);
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/payer", $fields, ['preuve' => $this->pdf()])->status());
        $files = (int) $this->db->fetchValue('SELECT COUNT(*) FROM files');
        $again = $this->request('POST', "/admin/decaissements/$id/payer", $fields, ['preuve' => $this->pdf()]);
        $this->assertSame(302, $again->status());
        $this->assertSame("/admin/decaissements/$id", $again->headers()['Location']);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]));
        $this->assertSame($files, (int) $this->db->fetchValue('SELECT COUNT(*) FROM files'), 'le second envoi ne stocke pas de fichier');
        // Nouveau formulaire (autre clé) sur une demande payée : refus du service, message clair.
        $res = $this->request('POST', "/admin/decaissements/$id/payer", ['key' => bin2hex(random_bytes(16))] + $fields, ['preuve' => $this->pdf()]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('elle est déjà payée', $res->body());
    }

    public function test_stale_revision_is_refused_and_current_state_is_shown(): void
    {
        $id = $this->submitted();
        $this->as($this->approverA);
        $html = $this->request('GET', "/admin/decaissements/$id")->body();
        preg_match('/name="seen_revision" value="(\d+)"/', $html, $m);
        $this->assertSame('1', $m[1]);
        $this->svc()->revise($id, ['amount' => 500_000_000], 'Montant revu', $this->initiator); // 50 000 -> 5 000 000 XOF
        foreach (['approuver' => ['seen_revision' => $m[1]], 'rejeter' => ['seen_revision' => $m[1], 'reason' => 'Non']] as $action => $post) {
            $res = $this->request('POST', "/admin/decaissements/$id/$action", $post);
            $this->assertSame(422, $res->status(), $action);
            $this->assertStringContainsString(e(DisbursementService::STALE), $res->body());
            $this->assertStringContainsString("5\u{202F}000\u{202F}000 XOF", $res->body(), 'état courant ré-affiché');
            $this->assertStringContainsString('name="seen_revision" value="2"', $res->body());
        }
        $this->assertSame('soumis', $this->state($id));
        // Paiement : montant vu périmé.
        $this->approveNow($id, $this->approverA);
        $this->approveNow($id, $this->approverB);
        $this->as($this->payer);
        $fields = $this->payFields($id);
        $res = $this->request('POST', "/admin/decaissements/$id/payer", ['seen_amount' => '5000000'] + $fields, ['preuve' => $this->pdf()]); // 50 000 XOF vus avant la révision
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString(e(DisbursementService::STALE), $res->body());
        $this->assertSame('approuve_2', $this->state($id));
    }

    public function test_domain_exceptions_become_messages_never_500(): void
    {
        $id = $this->submitted();
        $paid = $this->approved();
        $this->payNow($paid);
        $this->as($this->approverA);
        // Transition invalide (approbation d'une demande payée), forgée : 422 et message.
        $res = $this->request('POST', "/admin/decaissements/$paid/approuver", ['seen_revision' => '1']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('approuver cette demande : elle est déjà payée.', $res->body());
        // Rejet sans motif : règle du service.
        $res = $this->request('POST', "/admin/decaissements/$id/rejeter", ['seen_revision' => '1', 'reason' => '  ']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Le motif du rejet est obligatoire.', $res->body());
        // Verrou tenu par une autre connexion : opération simultanée, 409, rien d'écrit.
        $other = Database::connect(self::config(), test: true);
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->pdo()->beginTransaction();
        $other->fetch('SELECT id FROM disbursement_requests WHERE id = ? FOR UPDATE', [$id]);
        try {
            $res = $this->request('POST', "/admin/decaissements/$id/approuver", ['seen_revision' => '1']);
        } finally {
            $other->pdo()->rollBack();
        }
        $this->assertSame(409, $res->status());
        $this->assertStringContainsString(DisbursementBusy::MESSAGE, $res->body());
        $this->assertSame('soumis', $this->state($id));
        // CSRF : tout POST sans jeton valide est refusé.
        $this->assertSame(419, $this->request('POST', "/admin/decaissements/$id/approuver", ['seen_revision' => '1', '_csrf' => 'faux'])->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_approvals WHERE disbursement_request_id = ?', [$id]));
        // Identifiant inconnu : 404.
        $this->assertSame(404, $this->request('GET', '/admin/decaissements/999999999')->status());
    }

    public function test_draft_submit_revise_and_cancel_through_the_screens(): void
    {
        $this->as($this->initiator);
        foreach (['/admin/decaissements', '/admin/decaissements/nouveau', '/admin/decaissements?vue=a_approuver&etat=soumis'] as $url) {
            $this->assertSame(200, $this->request('GET', $url)->status(), $url);
        }
        $post = ['amount' => '75 000', 'beneficiary_name' => 'Famille Koné', 'account_id' => (string) $this->account, 'category_id' => (string) $this->category, 'purpose' => 'Aide', 'internal_note' => '', 'label' => ''];
        $this->assertSame(422, $this->request('POST', '/admin/decaissements/nouveau', ['amount' => 'abc'] + $post)->status());
        $this->assertSame(302, $this->request('POST', '/admin/decaissements/nouveau', $post)->status());
        $id = (int) $this->db->fetchValue('SELECT id FROM disbursement_requests ORDER BY id DESC LIMIT 1');
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$id/soumettre")->status(), 'sans justificatif');
        $this->assertSame(200, $this->request('GET', "/admin/decaissements/$id/modifier")->status());
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/modifier", $post + ['reason' => ''], ['justificatif' => $this->pdf()])->status());
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/soumettre")->status());
        $this->assertSame('soumis', $this->state($id));
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$id/modifier", ['amount' => '80 000', 'reason' => ''] + $post)->status(), 'motif obligatoire');
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/modifier", ['amount' => '80 000', 'reason' => 'Devis actualisé'] + $post)->status());
        $this->assertSame(2, $this->revision($id));
        $this->assertSame(200, $this->request('GET', "/admin/decaissements/$id/annuler")->status());
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$id/annuler")->status());
        $this->assertSame('annule', $this->state($id));
        $this->assertSame('127.0.0.1', $this->db->fetchValue("SELECT ip FROM audit_logs WHERE action = 'disbursement.cancelled' AND auditable_id = ? ORDER BY id DESC LIMIT 1", [$id]));
        // Page d'action non autorisée : retour à la demande, sans formulaire.
        $this->assertSame(302, $this->request('GET', "/admin/decaissements/$id/annuler")->status());
        // Approbateur : liste « à approuver par moi ».
        $todo = $this->submitted();
        $this->as($this->approverA);
        $body = $this->request('GET', '/admin/decaissements?vue=a_approuver')->body();
        $this->assertStringContainsString('/admin/decaissements/' . $todo . '"', $body);
        $this->assertStringNotContainsString('/admin/decaissements/' . $id . '"', $body);
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$todo/approuver", ['seen_revision' => '1'])->status());
        $this->assertSame('approuve_1', $this->state($todo));
        $this->assertStringContainsString('Décaissements', $this->request('GET', '/admin')->body(), 'lien du menu');
    }

    /** Un refus (action interdite, révision ou montant périmé) ne laisse aucun fichier téléversé. */
    public function test_refused_pay_and_update_leave_no_orphan_file(): void
    {
        $submitted = $this->submitted();
        $approved = $this->approved();
        $files = fn (): int => (int) $this->db->fetchValue('SELECT COUNT(*) FROM files');
        $before = $files();
        $this->as($this->payer);
        $f = ['method' => 'virement', 'paid_on' => date('Y-m-d'), 'key' => bin2hex(random_bytes(16)), 'seen_revision' => '1', 'seen_amount' => '5000000'];
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$submitted/payer", $f, ['preuve' => $this->pdf()])->status(), 'pas encore approuvée');
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$approved/payer", ['seen_revision' => '9'] + $f, ['preuve' => $this->pdf()])->status(), 'révision périmée');
        $this->assertSame(422, $this->request('POST', "/admin/decaissements/$approved/payer", ['seen_amount' => '1'] + $f, ['preuve' => $this->pdf()])->status(), 'montant périmé');
        $this->assertSame($before, $files());
        $paid = $this->approved();
        $this->payNow($paid);
        $before = $files();
        $this->as($this->initiator);
        $res = $this->request('POST', "/admin/decaissements/$paid/modifier", ['amount' => '50 000', 'beneficiary_name' => 'X', 'account_id' => (string) $this->account,
            'category_id' => (string) $this->category, 'purpose' => 'x', 'reason' => 'x', 'label' => ''], ['justificatif' => $this->pdf()]);
        $this->assertSame(422, $res->status(), 'demande payée : modification refusée');
        $this->assertSame($before, $files());
        // Le cas passant stocke bien le fichier (le contrôle ne bloque pas un envoi légitime).
        $this->as($this->payer);
        $this->assertSame(302, $this->request('POST', "/admin/decaissements/$approved/payer", $this->payFields($approved), ['preuve' => $this->pdf()])->status());
        $this->assertSame('paye', $this->state($approved));
        $this->assertGreaterThan($before, $files());
    }

    public function test_payment_proof_is_marked_required_in_the_form(): void
    {
        $id = $this->approved();
        $this->as($this->payer);
        $html = $this->request('GET', "/admin/decaissements/$id/payer")->body();
        $this->assertMatchesRegularExpression('/<input type="file"[^>]*name="preuve"[^>]*\brequired\b/', $html);
        $this->assertStringContainsString('Pièce justificative du paiement <span class="req"', $html);
    }
}
