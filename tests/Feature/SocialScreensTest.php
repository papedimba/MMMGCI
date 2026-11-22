<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Http\PermissionGuard;
use App\Core\Sequence;
use App\Core\Uploads;
use App\Modules\Disbursements\DisbursementService;
use App\Modules\Finance\Ledger;
use App\Core\Settings;
use App\Modules\Social\SocialDisbursementLink;
use App\Modules\Social\SocialDocumentService;
use App\Modules\Social\SocialMedicalService;
use App\Modules\Social\SocialNoteService;
use Tests\Support\AdminTestCase;
use Tests\Support\SocialFixtures;

/** Écrans de gestion d'une demande sociale : aucune règle dans l'écran, visibilité par SocialAccess, 404 hors périmètre. */
final class SocialScreensTest extends AdminTestCase
{
    use SocialFixtures;

    private string $root;
    private int $medic;
    private int $zoneManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-ss-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->overrides['uploads'] = $this->uploads();
        $this->db->query('DELETE FROM sensitive_access_logs');
        $this->setUpSocial();
        foreach (['so_medic' => ['social.manage', 'social.view', 'social.medical.view', 'social.create', 'members.scope.all'], 'so_zone_manage' => ['social.manage', 'social.view', 'social.decide']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $this->medic = $this->socialUser('medic', ['so_medic']);
        $this->zoneManager = $this->socialUser('zonemanager', ['so_zone_manage'], $this->zoneB);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function uploads(): Uploads
    {
        return new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
    }

    private function audit(): Audit
    {
        return new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    /** @return array<string,mixed> entrée de $_FILES (PDF valide) */
    private function pdf(string $name = 'piece.pdf'): array
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

    private function page(int $id, string $query = ''): string
    {
        $res = $this->request('GET', "/admin/demandes-sociales/$id" . $query);
        self::assertSame(200, $res->status());
        return $res->body();
    }

    /** @return list<string> */
    private function renderedActions(int $id): array
    {
        preg_match_all('/data-action="([A-Za-z]+)"/', $this->page($id), $m);
        $a = array_values(array_unique($m[1]));
        sort($a);
        return $a;
    }

    private function link(): SocialDisbursementLink
    {
        return new SocialDisbursementLink($this->db, $this->audit(), new Gate($this->db), new DisbursementService($this->db, $this->audit(),
            new Ledger($this->db, $this->audit(), new Sequence($this->db)), new Sequence($this->db), new Settings($this->db), new Gate($this->db)));
    }

    public function test_routes_require_the_right_permission_each(): void
    {
        $this->app();
        $id = '/admin/demandes-sociales/{id:\d+}';
        $expected = ['GET /admin/demandes-sociales/nouvelle' => 'social.create', 'POST /admin/demandes-sociales/nouvelle' => 'social.create',
            "GET $id" => 'social.view', "GET $id/pieces/{docId:\\d+}" => 'social.view', "POST $id/affecter" => 'social.manage', "POST $id/pieces-demandees" => 'social.manage',
            "POST $id/reprendre" => 'social.manage', "POST $id/avis" => 'social.manage', "POST $id/soumettre" => 'social.manage',
            "GET $id/decision" => 'social.decide', "POST $id/decision" => 'social.decide', "GET $id/decaissement" => 'social.manage', "POST $id/decaissement" => 'social.manage',
            "POST $id/decaissement/echec" => 'social.manage', "POST $id/cloturer" => 'social.manage', "POST $id/notes" => 'social.manage|social.decide',
            "POST $id/pieces" => 'social.manage|social.create', "POST $id/medical/afficher" => 'social.medical.view', "POST $id/medical" => 'social.manage'];
        $found = [];
        foreach ($this->services['router']->routes() as $r) {
            if (str_starts_with($r['pattern'], '/admin/demandes-sociales') && !preg_match('#^/admin/demandes-sociales/(types|parametres)#', $r['pattern'])) {
                $g = array_values(array_filter($r['middleware'], static fn ($m) => $m instanceof PermissionGuard))[0];
                $found["{$r['method']} {$r['pattern']}"] = implode('|', [$g->permission, ...$g->alternatives]);
            }
        }
        ksort($expected);
        ksort($found);
        self::assertSame($expected, $found);

        // Comportement : social.view seul n'écrit rien ; le décideur n'affecte pas ; le gestionnaire ne décide pas.
        $req = $this->requestIn('soumise_decision');
        $this->as($this->plain);
        foreach (['affecter', 'soumettre', 'cloturer', 'notes', 'decision', 'medical/afficher'] as $a) {
            self::assertSame(403, $this->request('POST', "/admin/demandes-sociales/$req/$a")->status(), $a);
        }
        self::assertSame(403, $this->request('GET', '/admin/demandes-sociales/nouvelle')->status());
        $this->as($this->manager);
        self::assertSame(403, $this->request('POST', "/admin/demandes-sociales/$req/decision", $this->grant())->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_decisions WHERE social_request_id = ?', [$req]));
    }

    public function test_admin_route_sweep_still_green(): void
    {
        $this->app();
        foreach ($this->services['router']->routes() as $r) {
            if (str_starts_with($r['pattern'], '/admin/demandes-sociales')) {
                self::assertNotEmpty(array_filter($r['middleware'], static fn ($m) => $m instanceof PermissionGuard), $r['pattern']);
            }
        }
        $id = $this->registerRequest();
        self::assertSame(302, $this->request('GET', "/admin/demandes-sociales/$id")->status()); // invité : vers la connexion
        $this->as($this->socialUser('sansdroit', []));
        self::assertSame(403, $this->request('GET', "/admin/demandes-sociales/$id")->status());
    }

    public function test_buttons_match_allowed_actions(): void
    {
        $svc = $this->socialSvc();
        $link = $this->link();
        foreach (['recue', 'en_instruction', 'en_attente_pieces', 'soumise_decision', 'accordee', 'refusee', 'cloturee'] as $state) {
            $id = $this->requestIn($state);
            foreach ([$this->manager, $this->decider, $this->plain, $this->medic] as $u) {
                $this->as($u);
                $expected = array_values(array_unique([...$svc->allowedActions($id, $u), ...$link->allowedActions($id, $u)]));
                sort($expected);
                self::assertSame($expected, $this->renderedActions($id), "$state / utilisateur $u");
            }
        }
        // Une action au moins apparaît bien (le test ne passe pas à vide).
        $this->as($this->manager);
        self::assertContains('assign', $this->renderedActions($this->requestIn('recue')));
        $this->as($this->decider);
        self::assertContains('decide', $this->renderedActions($this->requestIn('soumise_decision')));
    }

    public function test_member_search_in_create_form_respects_scope(): void
    {
        $this->as($this->zoneCreator);
        self::assertStringNotContainsString('membre=' . $this->memberB, $this->request('GET', '/admin/demandes-sociales/nouvelle?q=Konan')->body());
        $body = $this->request('GET', '/admin/demandes-sociales/nouvelle?q=Kouassi')->body();
        self::assertStringContainsString('membre=' . $this->memberA, $body);
        self::assertSame(404, $this->request('GET', '/admin/demandes-sociales/nouvelle?membre=' . $this->memberB)->status());
        self::assertSame(200, $this->request('GET', '/admin/demandes-sociales/nouvelle?membre=' . $this->memberA)->status());
        $post = ['type_id' => (string) $this->typeId, 'received_at' => Ledger::now()->format('Y-m-d'), 'channel' => 'guichet', 'priority' => 'normale', 'description' => 'Aide'];
        self::assertSame(404, $this->request('POST', '/admin/demandes-sociales/nouvelle', $post + ['member_id' => (string) $this->memberB])->status());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_requests WHERE member_id = ?', [$this->memberB]));
        // Dans le périmètre : enregistrée, avec sa pièce ; le champ médical n'est pas proposé à qui ne peut pas l'écrire.
        self::assertStringNotContainsString('name="medical_details"', $this->request('GET', '/admin/demandes-sociales/nouvelle?membre=' . $this->memberA)->body());
        $res = $this->request('POST', '/admin/demandes-sociales/nouvelle', $post + ['member_id' => (string) $this->memberA, 'label' => 'Facture'], ['piece' => $this->pdf()]);
        self::assertSame(302, $res->status());
        $id = (int) $this->db->fetchValue('SELECT id FROM social_requests WHERE member_id = ? ORDER BY id DESC LIMIT 1', [$this->memberA]);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents WHERE social_request_id = ?', [$id]));
        $this->as($this->medic);
        self::assertStringContainsString('name="medical_details"', $this->request('GET', '/admin/demandes-sociales/nouvelle?membre=' . $this->memberA)->body());
    }

    public function test_show_outside_scope_is_404(): void
    {
        $a = $this->registerRequest();
        $b = $this->registerRequest(['member_id' => $this->memberB]);
        $this->as($this->zoneCreator); // zone A
        self::assertSame(200, $this->request('GET', "/admin/demandes-sociales/$a")->status());
        self::assertSame(404, $this->request('GET', "/admin/demandes-sociales/$b")->status());
        self::assertSame(404, $this->request('GET', '/admin/demandes-sociales/999999999')->status());
        // Le contexte d'examen (context(), sans contrôle de périmètre) n'est jamais atteint hors zone : 404 avant.
        $this->as($this->zoneManager); // zone B, instruction
        self::assertSame(404, $this->request('GET', "/admin/demandes-sociales/$a?onglet=examen")->status());
        self::assertSame(404, $this->request('POST', "/admin/demandes-sociales/$a/notes", ['body' => 'x'])->status());
        self::assertStringContainsString('Contexte d&#039;examen', $this->page($b, '?onglet=examen'));
        // Adhérent supprimé logiquement, compte désactivé : introuvable.
        $this->db->query('UPDATE members SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$this->memberB]);
        self::assertSame(404, $this->request('GET', "/admin/demandes-sociales/$b?onglet=examen")->status());
        $this->db->query('UPDATE members SET deleted_at = NULL WHERE id = ?', [$this->memberB]);
        $this->db->query('UPDATE users SET is_active = 0 WHERE id = ?', [$this->zoneManager]);
        self::assertNotSame(200, $this->request('GET', "/admin/demandes-sociales/$b?onglet=examen")->status());
    }

    public function test_social_view_only_page_hides_instruction_and_medical(): void
    {
        $id = $this->registerRequest(['description' => 'DESCRIPTION-X']);
        $s = $this->socialSvc();
        $s->assign($id, $this->manager, $this->manager);
        $s->requestDocuments($id, 'PIECES-X', $this->manager);
        $s->resumeInstruction($id, $this->manager, 'COMMENT-X');
        $s->addReview($id, 'favorable', null, 'AVIS-X', $this->manager);
        (new SocialNoteService($this->db, $this->audit(), new Gate($this->db)))->add($id, 'NOTE-X', $this->manager);
        (new SocialMedicalService($this->db, $this->audit(), new Gate($this->db)))->write($id, 'SANTE-X', $this->medic);
        (new SocialDocumentService($this->db, $this->audit(), new Gate($this->db), $this->uploads()))->add($id, $this->pdf(), 'piece', 'LIBELLE-X', true, $this->medic);
        $this->as($this->plain);
        foreach (['', '?onglet=examen', '?onglet=pieces', '?onglet=sante'] as $q) {
            $html = $this->page($id, $q);
            foreach (['DESCRIPTION-X', 'COMMENT-X', 'AVIS-X', 'NOTE-X', 'SANTE-X', 'LIBELLE-X', 'onglet=examen', 'onglet=pieces', 'onglet=sante', 'Afficher les données médicales'] as $hidden) {
                self::assertStringNotContainsString($hidden, $html, "$hidden visible pour social.view seul ($q)");
            }
            self::assertStringContainsString('Chronologie', $html);
        }
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sensitive_access_logs'));
        // Le gestionnaire voit l'instruction, jamais les données de santé ni le libellé médical.
        $this->as($this->manager);
        self::assertStringContainsString('DESCRIPTION-X', $this->page($id));
        self::assertStringContainsString('COMMENT-X', $this->page($id));
        $exam = $this->page($id, '?onglet=examen');
        self::assertStringContainsString('AVIS-X', $exam);
        self::assertStringContainsString('NOTE-X', $exam);
        $pieces = $this->page($id, '?onglet=pieces');
        self::assertStringNotContainsString('LIBELLE-X', $pieces);
        self::assertStringContainsString(SocialDocumentService::RESTRICTED_LABEL, $pieces);
        self::assertStringNotContainsString('SANTE-X', $this->page($id, '?onglet=sante'));
    }

    public function test_medical_section_needs_explicit_click_and_logs(): void
    {
        $id = $this->requestIn('en_instruction');
        (new SocialMedicalService($this->db, $this->audit(), new Gate($this->db)))->write($id, 'SANTE-X', $this->medic);
        (new SocialDocumentService($this->db, $this->audit(), new Gate($this->db), $this->uploads()))->add($id, $this->pdf(), 'piece', 'LIBELLE-X', true, $this->medic);
        $logs = fn (): int => (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE user_id = ? AND field_group = 'donnees_medicales'", [$this->medic]);
        $this->as($this->medic);
        $html = $this->page($id);
        $html .= $this->page($id, '?onglet=sante');
        $html .= $this->page($id, '?onglet=examen');
        self::assertStringNotContainsString('SANTE-X', $html);
        self::assertStringNotContainsString('LIBELLE-X', $html); // pièces lues seulement sur leur onglet
        self::assertStringContainsString('Afficher les données médicales', $html);
        self::assertSame(0, $logs());
        $res = $this->request('POST', "/admin/demandes-sociales/$id/medical/afficher");
        self::assertSame(200, $res->status());
        self::assertStringContainsString('SANTE-X', $res->body());
        self::assertSame('private, no-store', $res->headers()['Cache-Control'] ?? null);
        self::assertSame(1, $logs());
        self::assertStringContainsString('LIBELLE-X', $this->page($id, '?onglet=pieces'));
        self::assertSame(2, $logs());
        // Sans social.medical.view : la route est refusée (403), rien n'est lu.
        $this->as($this->manager);
        self::assertSame(403, $this->request('POST', "/admin/demandes-sociales/$id/medical/afficher")->status());
    }

    public function test_member_facing_status_shown_next_to_internal_status(): void
    {
        $id = $this->requestIn('en_attente_pieces');
        $this->as($this->plain);
        $html = $this->page($id);
        self::assertStringContainsString('En attente de pièces', $html);
        self::assertMatchesRegularExpression('/Ce que voit l\'adhérent.{0,40}Pièces demandées/su', html_entity_decode($html, ENT_QUOTES));
        $html = html_entity_decode($this->page($this->requestIn('soumise_decision')), ENT_QUOTES);
        self::assertStringContainsString('Soumise à décision', $html);
        self::assertStringContainsString('En cours d\'examen', $html);
    }

    public function test_forged_post_skipping_steps_shows_clear_error(): void
    {
        $id = $this->requestIn('recue');
        $this->as($this->decider);
        $res = $this->request('POST', "/admin/demandes-sociales/$id/decision", $this->grant(['amount' => '50 000']));
        self::assertSame(422, $res->status());
        self::assertStringContainsString('Impossible de décider cette demande : elle est reçue.', html_entity_decode($res->body(), ENT_QUOTES));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_decisions WHERE social_request_id = ?', [$id]));
        self::assertSame(302, $this->request('GET', "/admin/demandes-sociales/$id/decision")->status()); // pas de formulaire hors étape
        $this->as($this->manager);
        $res = $this->request('POST', "/admin/demandes-sociales/$id/cloturer");
        self::assertSame(422, $res->status());
        self::assertStringContainsString('Impossible de clôturer cette demande', html_entity_decode($res->body(), ENT_QUOTES));
        $res = $this->request('POST', "/admin/demandes-sociales/$id/soumettre");
        self::assertSame(422, $res->status());
        self::assertSame('recue', $this->state($id));
        // Soumission sans avis : refusée avec le message du service.
        $id2 = $this->requestIn('en_instruction');
        self::assertStringContainsString('Ajoutez au moins un avis', html_entity_decode($this->request('POST', "/admin/demandes-sociales/$id2/soumettre")->body(), ENT_QUOTES));
    }

    public function test_hostile_description_notes_and_names_are_escaped(): void
    {
        $x = '<script>alert(1)</script>';
        $this->db->update('members', ['last_name' => '<img src=x onerror=alert(1)>'], ['id' => $this->memberA]);
        $this->db->update('users', ['name' => '<b>Gestionnaire</b>'], ['id' => $this->manager]);
        $id = $this->registerRequest(['description' => "Desc $x"]);
        $this->socialSvc()->assign($id, $this->manager, $this->manager);
        (new SocialNoteService($this->db, $this->audit(), new Gate($this->db)))->add($id, "Note $x", $this->manager);
        $this->as($this->manager);
        $html = $this->page($id) . $this->page($id, '?onglet=examen');
        foreach ([$x, '<img src=x', '<b>Gestionnaire</b>'] as $raw) {
            self::assertStringNotContainsString($raw, $html);
        }
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;b&gt;Gestionnaire&lt;/b&gt;', $html);
    }

    public function test_double_submit_of_decision_form_records_one_decision(): void
    {
        $id = $this->requestIn('soumise_decision');
        $this->as($this->decider);
        $form = $this->request('GET', "/admin/demandes-sociales/$id/decision")->body();
        self::assertMatchesRegularExpression('/name="amount" class="control"[^>]* value=""/', $form); // aucun montant pré-rempli
        self::assertStringContainsString('Aucun plafond validé', $form);
        self::assertStringContainsString('aucune donnée de santé', $form);
        $post = $this->grant(['amount' => '50 000']);
        self::assertSame(302, $this->request('POST', "/admin/demandes-sociales/$id/decision", $post)->status());
        $second = $this->request('POST', "/admin/demandes-sociales/$id/decision", $post);
        self::assertSame(302, $second->status());
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_decisions WHERE social_request_id = ?', [$id]));
        self::assertSame('50000.00', (string) $this->db->fetchValue('SELECT amount FROM social_decisions WHERE social_request_id = ?', [$id]));
        self::assertStringContainsString('Une décision est déjà enregistrée', html_entity_decode($this->page($id), ENT_QUOTES));
        // Le motif, le message et l'organe saisis ne vont jamais au journal.
        $audit = (string) $this->db->fetchValue("SELECT GROUP_CONCAT(new_values) FROM audit_logs WHERE auditable_type = 'social_request' AND auditable_id = ?", [$id]);
        foreach (['Dossier complet', 'Votre demande est accordée.', 'Conseil d\'administration'] as $free) {
            self::assertStringNotContainsString($free, $audit);
        }
    }

    public function test_decision_form_shows_ceiling_only_when_validated(): void
    {
        $ceiling = "100\u{202F}000"; // Money::format sépare les milliers par une espace fine insécable
        $form = function (): string {
            $id = $this->requestIn('soumise_decision');
            $this->as($this->decider);
            return html_entity_decode($this->request('GET', "/admin/demandes-sociales/$id/decision")->body(), ENT_QUOTES);
        };
        // Plafond en base mais non validé : jamais affiché ni appliqué.
        $this->db->query('UPDATE social_request_types SET ceiling_amount = 100000.00, params_decision_ref = NULL WHERE id = ?', [$this->typeId]);
        $html = $form();
        self::assertStringNotContainsString($ceiling, $html);
        self::assertStringContainsString('Aucun plafond validé', $html);
        // Validé : affiché comme tel.
        $this->db->query("UPDATE social_request_types SET params_decision_ref = 'PV-1' WHERE id = ?", [$this->typeId]);
        $html = $form();
        self::assertStringContainsString("Plafond validé pour ce type : $ceiling", $html);
        self::assertStringNotContainsString('Aucun plafond validé', $html);
        self::assertMatchesRegularExpression('/name="amount" class="control"[^>]* value=""/', $html); // le plafond n'est jamais recopié dans le montant
    }

    /** Défense en profondeur : sans social.view, toute action du circuit ou du lien répond « introuvable », même avec les autres droits et tout le périmètre. */
    public function test_actions_without_social_view_are_not_found(): void
    {
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('so_noview', 'so_noview')");
        foreach (['social.manage', 'social.decide', 'disbursement.initiate', 'finance.view', 'members.scope.all'] as $p) {
            $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_noview' AND p.name = ?", [$p]);
        }
        $u = $this->socialUser('noview', ['so_noview']);
        $s = $this->socialSvc();
        $recue = $this->requestIn('recue');
        $submitted = $this->requestIn('soumise_decision');
        $granted = $this->requestIn('accordee');
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_events');
        foreach ([fn () => $s->assign($recue, $this->manager2, $u), fn () => $s->decide($submitted, $this->grant(), $u), fn () => $s->close($granted, null, $u),
            fn () => $this->link()->prepare($granted, ['account_id' => 1, 'category_id' => 1, 'beneficiary_name' => 'X'], $u),
            fn () => $this->link()->paymentFailed($granted, $u)] as $call) {
            try {
                $call();
                self::fail('NotFound attendue');
            } catch (\App\Core\NotFound $e) {
                self::assertStringContainsString('introuvable', $e->getMessage());
            }
        }
        self::assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_events'));
        self::assertSame('recue', $this->state($recue));
    }

    public function test_assignment_from_screen_sends_email(): void
    {
        $id = $this->registerRequest(['description' => 'DESCRIPTION-X']);
        $this->as($this->manager);
        self::assertSame(302, $this->request('POST', "/admin/demandes-sociales/$id/affecter", ['assignee' => (string) $this->manager2])->status());
        self::assertSame($this->manager2, (int) $this->db->fetchValue('SELECT assigned_to FROM social_requests WHERE id = ?', [$id]));
        $sent = $this->mail->messages;
        self::assertCount(1, $sent);
        $ref = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]);
        self::assertStringContainsString($ref, mb_decode_mimeheader($sent[0]['subject']));
        self::assertStringNotContainsString('DESCRIPTION-X', json_encode($sent) ?: '');
    }

    public function test_prepare_disbursement_and_close_screens(): void
    {
        $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (\'so_manage_init\', \'so_manage_init\')');
        foreach (['social.manage', 'social.view', 'members.scope.all', 'disbursement.initiate'] as $p) {
            $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = \'so_manage_init\' AND p.name = ?', [$p]);
        }
        $agent = $this->socialUser('agent', ['so_manage_init']);
        $account = $this->db->insert('financial_accounts', ['name' => 'Caisse SS ' . bin2hex(random_bytes(3)), 'opening_balance' => '0.00']);
        $cat = $this->db->insert('financial_categories', ['name' => 'Aides SS ' . bin2hex(random_bytes(3)), 'direction' => 'depense', 'is_active' => 1]);
        $id = $this->requestIn('accordee');
        $this->as($agent);
        $form = $this->request('GET', "/admin/demandes-sociales/$id/decaissement");
        self::assertSame(200, $form->status());
        self::assertStringNotContainsString(' selected>Caisse', $form->body()); // aucun compte présélectionné
        $res = $this->request('POST', "/admin/demandes-sociales/$id/decaissement", ['account_id' => (string) $account, 'category_id' => (string) $cat, 'beneficiary_name' => 'Kouassi Ama']);
        self::assertSame(302, $res->status());
        self::assertSame('en_paiement', $this->state($id));
        $d = $this->db->fetch('SELECT dr.status, dr.amount FROM disbursement_requests dr JOIN social_decisions sd ON sd.id = dr.social_decision_id WHERE sd.social_request_id = ?', [$id]);
        self::assertSame(['status' => 'brouillon', 'amount' => '50000.00'], $d);
        self::assertStringContainsString('/admin/decaissements/', $this->page($id));
        // Clôture d'une aide non financière : formulaire relié à la boîte de confirmation.
        $id2 = $this->requestIn('accordee', 'orientation');
        $html = $this->page($id2);
        self::assertStringContainsString('data-confirm-open="confirm-close"', $html);
        self::assertStringContainsString('form="close-form"', $html);
        self::assertSame(302, $this->request('POST', "/admin/demandes-sociales/$id2/cloturer", ['closure_note' => 'Orienté'])->status());
        self::assertSame('cloturee', $this->state($id2));
    }
}
