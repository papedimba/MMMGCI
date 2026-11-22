<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Gate;
use App\Core\Money;
use App\Core\NotFound;
use App\Modules\Disbursements\DisbursementRuleViolation;
use App\Modules\Disbursements\DisbursementTransitionInvalid;
use App\Modules\Social\SocialDisbursementLink;
use App\Modules\Social\SocialRuleViolation;
use App\Modules\Social\SocialTransitionInvalid;
use Tests\Support\AdminTestCase;
use Tests\Support\DisbursementFixtures;
use Tests\Support\SocialFixtures;

/** Lien décision sociale -> circuit de décaissement L6 (T7.4) : un brouillon seulement, montant figé, un seul décaissement vivant, objet neutre. */
final class SocialDisbursementTest extends AdminTestCase
{
    use DisbursementFixtures, SocialFixtures {
        DisbursementFixtures::state insteadof SocialFixtures;
        SocialFixtures::state as socialState;
    }

    private int $prep;

    protected function setUp(): void
    {
        parent::setUp();
        // Ordre des clés étrangères : décaissements -> décisions -> demandes, avant que setUpCircuit ne vide les utilisateurs.
        foreach (['disbursement_payments', 'disbursement_approvals', 'disbursement_revisions'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query("DELETE FROM financial_attachments WHERE attachable_type = 'disbursement_request'");
        $this->db->query('DELETE FROM disbursement_requests');
        foreach (['social_request_events', 'social_request_notes', 'social_request_reviews', 'social_request_documents', 'social_request_medical', 'social_decisions', 'social_requests'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->setUpCircuit();
        $this->setUpSocial();
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('so_prep', 'so_prep')");
        foreach (['social.manage', 'social.view', 'members.scope.all', 'disbursement.initiate'] as $p) {
            $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_prep' AND p.name = ?", [$p]);
        }
        $this->prep = $this->socialUser('prep', ['so_prep']);
    }

    private function link(?Database $db = null): SocialDisbursementLink
    {
        $db ??= $this->db;
        return new SocialDisbursementLink($db, $this->audit($db), new Gate($db), $this->svc($db));
    }

    /** @return array<string,mixed> */
    private function choice(): array
    {
        return ['account_id' => $this->account, 'category_id' => $this->category, 'beneficiary_name' => 'Kouassi Ama'];
    }

    private function decisionOf(int $requestId): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM social_decisions WHERE social_request_id = ?', [$requestId]);
    }

    private function prepared(?int &$requestId = null): int
    {
        $requestId = $this->requestIn('accordee');
        return $this->link()->prepare($requestId, $this->choice(), $this->prep);
    }

    /** Pièce (non médicale) jointe par l'initiateur, soumission, deux approbations. */
    private function toApproved(int $id): void
    {
        $this->svc()->revise($id, ['add_files' => [$this->file($this->prep) => 'Extrait de décision']], '', $this->prep);
        $this->svc()->submit($id, $this->prep);
        $this->approveNow($id, $this->approverA);
        $this->approveNow($id, $this->approverB);
    }

    private function countFor(string $table, int $id): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM $table WHERE disbursement_request_id = ?", [$id]);
    }

    public function test_grant_creates_only_a_draft_disbursement(): void
    {
        $id = $this->prepared($rid);
        $d = $this->db->fetch('SELECT * FROM disbursement_requests WHERE id = ?', [$id]);
        self::assertSame('brouillon', $d['status']);
        self::assertSame('50000.00', $d['amount'], 'montant décidé recopié');
        self::assertSame($this->memberA, (int) $d['beneficiary_member_id']);
        self::assertSame($this->decisionOf($rid), (int) $d['social_decision_id']);
        self::assertSame($this->prep, (int) $d['initiated_by']);
        self::assertSame((int) $this->account, (int) $d['account_id']);
        self::assertSame('en_paiement', $this->socialState($rid));
        self::assertSame(['prepareDisbursement'], array_column($this->db->fetchAll("SELECT action FROM social_request_events WHERE social_request_id = ? AND to_status = 'en_paiement'", [$rid]), 'action'));
        foreach (['disbursement_approvals', 'disbursement_payments', 'disbursement_revisions'] as $t) {
            self::assertSame(0, $this->countFor($t, $id), $t);
        }
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]));
        $last = $this->db->fetchAll('SELECT action, auditable_type, auditable_id FROM audit_logs ORDER BY id DESC LIMIT 2');
        self::assertSame(['social.disbursement_prepared', 'disbursement.created'], array_column($last, 'action'), 'audit social en dernier');
        self::assertSame($rid, (int) $last[0]['auditable_id']);
    }

    public function test_no_social_path_writes_ledger_approvals_or_payments(): void
    {
        foreach (glob(dirname(__DIR__, 2) . '/app/Modules/Social/*.php') as $f) {
            $src = (string) file_get_contents($f);
            foreach (['financial_transactions', 'disbursement_approvals', 'disbursement_payments', 'INTO financial_attachments', 'new Ledger', '->ledger', '->approve(', '->pay(',
                '->submit(', '->reject(', '->revise(', '->cancel('] as $needle) {
                self::assertStringNotContainsString($needle, $src, basename($f) . " : $needle");
            }
        }
        // Un cycle complet côté social (préparation, rejet par le circuit L6, retour, nouvelle préparation) : seuls les acteurs L6 ont écrit.
        $first = $this->prepared($rid);
        self::assertSame(0, $this->countFor('disbursement_approvals', $first));
        $this->svc()->revise($first, ['add_files' => [$this->file($this->prep) => 'Extrait']], '', $this->prep);
        $this->svc()->submit($first, $this->prep);
        $this->approveNow($first, $this->approverA);
        $this->rejectNow($first, $this->approverB, 'Incomplet');
        $this->link()->paymentFailed($rid, $this->prep);
        $second = $this->link()->prepare($rid, $this->choice(), $this->prep);
        self::assertSame([$this->approverA, $this->approverB], array_map('intval', array_column($this->db->fetchAll('SELECT approver_id FROM disbursement_approvals ORDER BY id'), 'approver_id')));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_payments'));
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id IN (?, ?)", [$first, $second]));
        self::assertSame('brouillon', $this->state($second));
    }

    public function test_linked_disbursement_still_needs_two_distinct_approvers_and_a_third_party_payer(): void
    {
        $id = $this->prepared($rid);
        $this->svc()->revise($id, ['add_files' => [$this->file($this->prep) => 'Extrait de décision']], '', $this->prep);
        $this->svc()->submit($id, $this->prep);
        $this->refuses(fn () => $this->approveNow($id, $this->prep), DisbursementRuleViolation::class, 'Action refusée');
        $this->approveNow($id, $this->approverA);
        $this->refuses(fn () => $this->approveNow($id, $this->approverA), DisbursementRuleViolation::class, 'déjà prononcé');
        $this->refuses(fn () => $this->payNow($id), DisbursementTransitionInvalid::class);
        $this->approveNow($id, $this->approverB);
        $this->refuses(fn () => $this->payNow($id, $this->prep), DisbursementRuleViolation::class, 'Action refusée');
        $this->payNow($id);
        self::assertSame('paye', $this->state($id));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_transactions WHERE source_type = 'disbursement' AND source_id = ?", [$id]));
        self::assertSame([$this->approverA, $this->approverB], array_map('intval', array_column($this->db->fetchAll('SELECT approver_id FROM disbursement_approvals WHERE disbursement_request_id = ? ORDER BY id', [$id]), 'approver_id')));
        self::assertSame($this->payer, (int) $this->db->fetchValue('SELECT paid_by FROM disbursement_payments WHERE disbursement_request_id = ?', [$id]));
        self::assertSame('en_paiement', $this->socialState($rid), 'le paiement ne clôture rien tout seul');
    }

    public function test_decider_without_disbursement_permissions_cannot_prepare_approve_or_pay(): void
    {
        $rid = $this->requestIn('accordee');
        $this->refuses(fn () => $this->link()->prepare($rid, $this->choice(), $this->decider), SocialRuleViolation::class, 'Action refusée');
        $this->refuses(fn () => $this->link()->prepare($rid, $this->choice(), $this->manager), SocialRuleViolation::class, 'Action refusée'); // social.manage sans disbursement.initiate
        self::assertSame('accordee', $this->socialState($rid));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_requests'));
        self::assertSame([], $this->link()->allowedActions($rid, $this->decider));
        self::assertSame(['prepare'], $this->link()->allowedActions($rid, $this->prep));
        $id = $this->link()->prepare($rid, $this->choice(), $this->prep);
        $this->svc()->revise($id, ['add_files' => [$this->file($this->prep) => 'Extrait']], '', $this->prep);
        $this->svc()->submit($id, $this->prep);
        $this->refuses(fn () => $this->approveNow($id, $this->decider), DisbursementRuleViolation::class, 'Action refusée');
        $this->approveNow($id, $this->approverA);
        $this->approveNow($id, $this->approverB);
        $this->refuses(fn () => $this->payNow($id, $this->decider), DisbursementRuleViolation::class, 'Action refusée');
        self::assertSame('approuve_2', $this->state($id));
        // Hors périmètre ou compte désactivé : introuvable.
        $outside = $this->socialUser('zoneB', ['so_prep'], $this->zoneB);
        $this->db->query("DELETE ur FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = 'so_prep'", [$outside]);
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('so_prep_zone', 'so_prep_zone')");
        foreach (['social.manage', 'social.view', 'disbursement.initiate'] as $p) {
            $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'so_prep_zone' AND p.name = ?", [$p]);
        }
        $this->db->query("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE name = 'so_prep_zone'", [$outside]);
        $other = $this->requestIn('accordee');
        foreach ([$outside, $this->prep] as $i => $who) {
            if ($i === 1) {
                $this->db->update('users', ['is_active' => 0], ['id' => $this->prep]);
            }
            try {
                $this->link()->prepare($other, $this->choice(), $who);
                self::fail('introuvable attendu');
            } catch (NotFound $e) {
                self::assertStringContainsString('introuvable', $e->getMessage());
            }
            self::assertSame([], $this->link()->allowedActions($other, $who));
        }
        self::assertSame('accordee', $this->socialState($other));
    }

    public function test_forged_social_decision_id_in_disbursement_form_is_ignored(): void
    {
        $rid = $this->requestIn('accordee');
        $this->as($this->initiator);
        $res = $this->request('POST', '/admin/decaissements/nouveau', ['amount' => '50 000', 'beneficiary_name' => 'Kouassi Ama', 'account_id' => (string) $this->account,
            'category_id' => (string) $this->category, 'purpose' => 'Aide', 'internal_note' => '', 'reason' => '', 'label' => '', 'social_decision_id' => (string) $this->decisionOf($rid),
            'beneficiary_member_id' => (string) $this->memberA]);
        self::assertSame(302, $res->status());
        $d = $this->db->fetch('SELECT social_decision_id, beneficiary_member_id FROM disbursement_requests ORDER BY id DESC LIMIT 1');
        self::assertNotNull($d);
        self::assertNull($d['social_decision_id'], 'jamais lié depuis le formulaire');
        self::assertNull($d['beneficiary_member_id']);
        self::assertSame('accordee', $this->socialState($rid));
        self::assertSame(['prepare'], $this->link()->allowedActions($rid, $this->prep), 'la vraie préparation reste possible');
    }

    public function test_create_refuses_amount_different_from_decision(): void
    {
        $rid = $this->requestIn('accordee');
        $did = $this->decisionOf($rid);
        $base = ['beneficiary_name' => 'Kouassi Ama', 'account_id' => $this->account, 'category_id' => $this->category, 'purpose' => 'Aide sociale'];
        $create = fn (array $over) => $this->svc()->create($over + $base + ['amount' => 5_000_000, 'beneficiary_member_id' => $this->memberA, 'social_decision_id' => $did], $this->prep);
        $this->refuses(fn () => $create(['amount' => 5_000_001]), DisbursementRuleViolation::class, 'Le montant est fixé par la décision sociale.');
        $this->refuses(fn () => $create(['beneficiary_member_id' => $this->memberB]), DisbursementRuleViolation::class, 'adhérent de la demande sociale');
        $this->refuses(fn () => $create(['beneficiary_member_id' => null]), DisbursementRuleViolation::class, 'adhérent de la demande sociale');
        $this->refuses(fn () => $create(['social_decision_id' => 999_999_999]), DisbursementRuleViolation::class, 'Décision sociale introuvable');
        $this->refuses(fn () => $create(['social_decision_id' => (string) $did]), DisbursementRuleViolation::class, 'Décision sociale introuvable');
        $orientation = $this->requestIn('accordee', 'orientation');
        $this->refuses(fn () => $create(['social_decision_id' => $this->decisionOf($orientation)]), DisbursementRuleViolation::class, 'aide financière accordée');
        $refused = $this->requestIn('refusee');
        $this->refuses(fn () => $create(['social_decision_id' => $this->decisionOf($refused)]), DisbursementRuleViolation::class, 'aide financière accordée');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_requests'));
        $this->refuses(fn () => $this->svc()->create(['amount' => 5_000_000, 'beneficiary_member_id' => $this->memberA, 'social_decision_id' => $did] + $base, $this->decider),
            DisbursementRuleViolation::class, 'Action refusée');
    }

    public function test_revise_cannot_change_amount_of_linked_disbursement(): void
    {
        $id = $this->prepared();
        $this->refuses(fn () => $this->svc()->revise($id, ['amount' => 4_000_000], 'moins', $this->prep), DisbursementRuleViolation::class, 'Le montant est fixé par la décision sociale.');
        $this->refuses(fn () => $this->svc()->revise($id, ['beneficiary_member_id' => $this->memberB], '', $this->prep), DisbursementRuleViolation::class, 'fixé par la décision sociale');
        $this->refuses(fn () => $this->svc()->revise($id, ['beneficiary_member_id' => null], '', $this->prep), DisbursementRuleViolation::class, 'fixé par la décision sociale');
        $this->svc()->revise($id, ['amount' => 5_000_000, 'beneficiary_name' => 'Kouassi Ama (ayant droit)'], '', $this->prep); // montant inchangé : permis
        $this->toApproved($id);
        $this->refuses(fn () => $this->svc()->revise($id, ['amount' => 6_000_000], 'plus', $this->prep), DisbursementRuleViolation::class, 'Le montant est fixé par la décision sociale.');
        self::assertSame('50000.00', $this->db->fetchValue('SELECT amount FROM disbursement_requests WHERE id = ?', [$id]));
        self::assertSame('approuve_2', $this->state($id), 'le refus ne touche pas aux approbations');
        // Le reste du circuit A5.3 est inchangé : une modification substantielle autorisée renvoie à « soumis ».
        $this->svc()->revise($id, ['beneficiary_name' => 'Kouassi Ama'], 'correction', $this->prep);
        self::assertSame('soumis', $this->state($id));
    }

    public function test_second_live_disbursement_for_same_decision_refused(): void
    {
        $id = $this->prepared($rid);
        $did = $this->decisionOf($rid);
        $this->refuses(fn () => $this->svc()->create(['amount' => 5_000_000, 'beneficiary_member_id' => $this->memberA, 'social_decision_id' => $did] + $this->choice()
            + ['purpose' => 'Aide sociale'], $this->prep), DisbursementRuleViolation::class, 'déjà en cours');
        $this->refuses(fn () => $this->link()->prepare($rid, $this->choice(), $this->prep), SocialTransitionInvalid::class);
        $this->db->query("UPDATE social_requests SET status = 'accordee' WHERE id = ?", [$rid]); // état forgé : le décaissement vivant reste le filet
        $this->refuses(fn () => $this->link()->prepare($rid, $this->choice(), $this->prep), SocialRuleViolation::class, 'déjà en cours');
        self::assertSame([$id], array_map('intval', array_column($this->db->fetchAll('SELECT id FROM disbursement_requests WHERE social_decision_id = ?', [$did]), 'id')));
    }

    public function test_rejected_disbursement_allows_payment_failed_then_new_preparation(): void
    {
        $first = $this->prepared($rid);
        $this->refuses(fn () => $this->link()->paymentFailed($rid, $this->prep), SocialRuleViolation::class, 'ni rejeté ni annulé');
        self::assertSame([], $this->link()->allowedActions($rid, $this->prep));
        $this->svc()->revise($first, ['add_files' => [$this->file($this->prep) => 'Extrait']], '', $this->prep);
        $this->svc()->submit($first, $this->prep);
        $this->approveNow($first, $this->approverA);
        $this->rejectNow($first, $this->approverB, 'Pièce illisible');
        $this->refuses(fn () => $this->link()->paymentFailed($rid, $this->decider), SocialRuleViolation::class, 'Action refusée');
        self::assertSame(['paymentFailed'], $this->link()->allowedActions($rid, $this->prep));
        $this->link()->paymentFailed($rid, $this->prep);
        self::assertSame('accordee', $this->socialState($rid));
        self::assertSame('social.disbursement_failed', $this->db->fetchValue('SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1'));
        $second = $this->link()->prepare($rid, $this->choice(), $this->prep);
        self::assertNotSame($first, $second);
        self::assertSame('brouillon', $this->state($second));
        self::assertSame(0, $this->countFor('disbursement_approvals', $second), 'circuit complet : nouvelles approbations');
        self::assertSame('rejete', $this->state($first));
        // Annulation (avant toute approbation) : même retour.
        $this->svc()->revise($second, ['add_files' => [$this->file($this->prep) => 'Extrait']], '', $this->prep);
        $this->svc()->submit($second, $this->prep);
        $this->svc()->cancel($second, $this->prep);
        $this->link()->paymentFailed($rid, $this->prep);
        self::assertSame('accordee', $this->socialState($rid));
        $this->refuses(fn () => $this->link()->paymentFailed($rid, $this->prep), SocialTransitionInvalid::class);
    }

    public function test_close_after_payment_only(): void
    {
        $id = $this->prepared($rid);
        $this->refuses(fn () => $this->socialSvc()->close($rid, null, $this->prep), SocialRuleViolation::class, 'pas encore payé');
        $this->toApproved($id);
        $this->refuses(fn () => $this->socialSvc()->close($rid, null, $this->prep), SocialRuleViolation::class, 'pas encore payé');
        $this->payNow($id);
        $this->socialSvc()->close($rid, null, $this->prep);
        self::assertSame('cloturee', $this->socialState($rid));
        $this->refuses(fn () => $this->link()->paymentFailed($rid, $this->prep), SocialTransitionInvalid::class);
    }

    public function test_purpose_never_contains_type_or_medical_data(): void
    {
        $rid = $this->requestIn('accordee');
        $this->db->query("UPDATE social_requests SET description = 'DIAGNOSTIC-SENTINELLE', contains_medical_data = 1 WHERE id = ?", [$rid]);
        $this->db->insert('social_request_medical', ['social_request_id' => $rid, 'details' => 'PATHOLOGIE-SENTINELLE', 'updated_by' => $this->manager]);
        $before = (int) $this->db->fetchValue('SELECT MAX(id) FROM audit_logs');
        $id = $this->link()->prepare($rid, $this->choice(), $this->prep);
        $ref = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$rid]);
        $purpose = (string) $this->db->fetchValue('SELECT purpose FROM disbursement_requests WHERE id = ?', [$id]);
        self::assertSame("Aide sociale $ref — décision PV-CA-12", $purpose);
        $type = $this->db->fetch('SELECT code, name FROM social_request_types WHERE id = ?', [$this->typeId]);
        $logs = json_encode($this->db->fetchAll('SELECT action, old_values, new_values FROM audit_logs WHERE id > ?', [$before]), JSON_UNESCAPED_UNICODE);
        $internal = (string) $this->db->fetchValue('SELECT CONCAT_WS(\'|\', beneficiary_name, purpose, internal_note) FROM disbursement_requests WHERE id = ?', [$id]);
        foreach (['DIAGNOSTIC-SENTINELLE', 'PATHOLOGIE-SENTINELLE', $type['code'], $type['name'], 'Dossier complet', 'Votre demande est accordée.'] as $secret) {
            self::assertStringNotContainsStringIgnoringCase($secret, $internal, "objet du décaissement : $secret");
            self::assertStringNotContainsStringIgnoringCase($secret, (string) $logs, "audit : $secret");
        }
    }

    public function test_social_documents_never_copied_to_disbursement(): void
    {
        $rid = $this->requestIn('accordee');
        $doc = $this->file($this->prep);
        $this->db->insert('social_request_documents', ['social_request_id' => $rid, 'file_id' => $doc, 'kind' => 'piece', 'label' => 'Certificat médical', 'is_medical' => 1, 'uploaded_by' => $this->prep]);
        $id = $this->link()->prepare($rid, $this->choice(), $this->prep);
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_attachments WHERE attachable_type = 'disbursement_request' AND attachable_id = ?", [$id]));
        // Même téléversée par l'initiateur, une pièce sociale ne peut pas devenir un justificatif du décaissement.
        $this->refuses(fn () => $this->svc()->revise($id, ['add_files' => [$doc => 'Justificatif']], '', $this->prep), DisbursementRuleViolation::class, 'fichier introuvable');
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM financial_attachments WHERE file_id = ?", [$doc]));
    }

    public function test_solidarity_dashboard_shows_reference_only(): void
    {
        $this->db->query("UPDATE financial_accounts SET is_active = 0 WHERE type = 'solidarity_fund'");
        $this->db->update('financial_accounts', ['type' => 'solidarity_fund'], ['id' => $this->account]);
        $id = $this->prepared($rid);
        $this->toApproved($id);
        $this->payNow($id);
        $ref = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$rid]);
        $this->db->query("INSERT IGNORE INTO roles (name, label) VALUES ('sol_view', 'sol_view')");
        $this->db->query("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = 'sol_view' AND p.name = 'solidarity.view'");
        $viewer = $this->createUser('sol' . bin2hex(random_bytes(3)) . '@so.test', ['sol_view']);
        $this->as($viewer);
        $body = $this->request('GET', '/admin/solidarite')->body();
        self::assertStringContainsString('Aide sociale <code>' . $ref . '</code>', $body);
        self::assertStringNotContainsString('/admin/demandes-sociales/', $body, 'pas de lien sans social.view');
        $type = (string) $this->db->fetchValue('SELECT name FROM social_request_types WHERE id = ?', [$this->typeId]);
        foreach (['Kouassi', 'Ama', $type, 'PV-CA-12'] as $secret) {
            self::assertStringNotContainsString($secret, $body, $secret);
        }
        // Page du décaissement : mention de l'aide, lien seulement avec social.view.
        $this->as($this->prep);
        $page = $this->request('GET', "/admin/decaissements/$id")->body();
        self::assertStringContainsString('href="/admin/demandes-sociales/' . $rid . '"', $page);
        self::assertStringContainsString($ref, $page);
        $this->as($this->approverA);
        $page = $this->request('GET', "/admin/decaissements/$id")->body();
        self::assertStringContainsString('Aide sociale ' . $ref, $page);
        self::assertStringNotContainsString('/admin/demandes-sociales/', $page);
    }

    /** Course réelle : $hold tient ses verrous dans une transaction non validée pendant que le fils (seconde connexion) attend $waitingOn ; on valide, puis on lit la sortie du fils. @param list<string> $args */
    private function race(callable $hold, array $args, string $waitingOn): string
    {
        [$proc, $pipes] = $this->db->transaction(function () use ($hold, $args, $waitingOn): array {
            $hold();
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/social_worker.php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $observer = Database::connect(self::config(), test: true);
            for ($i = 0; $i < 400 && (int) $observer->fetchValue('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE ?', [$waitingOn . '%']) === 0; $i++) {
                usleep(25_000);
            }
            self::assertLessThan(400, $i, 'Le processus fils aurait dû attendre le verrou.');
            return [$proc, $pipes];
        });
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        proc_close($proc);
        return $out;
    }

    private function liveFor(int $decisionId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM disbursement_requests WHERE social_decision_id = ? AND status NOT IN ('rejete', 'annule')", [$decisionId]);
    }

    /** @return list<string> arguments du fils : création directe liée à la décision */
    private function createArgs(int $decisionId): array
    {
        return ['create', (string) $decisionId, (string) $this->prep, (string) $this->account, (string) $this->category, (string) $this->memberA];
    }

    private function directCreate(int $decisionId): int
    {
        return $this->svc()->create(['amount' => 5_000_000, 'beneficiary_member_id' => $this->memberA, 'social_decision_id' => $decisionId, 'purpose' => 'Aide sociale'] + $this->choice(), $this->prep);
    }

    public function test_simultaneous_preparations_create_one_disbursement(): void
    {
        $rid = $this->requestIn('accordee');
        $out = $this->race(fn () => $this->link()->prepare($rid, $this->choice(), $this->prep),
            ['prepare', (string) $rid, (string) $this->prep, (string) $this->account, (string) $this->category], 'SELECT * FROM social_requests WHERE id = ? FOR UPDATE');
        self::assertStringStartsWith('SocialTransitionInvalid: ', $out);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM disbursement_requests WHERE social_decision_id = ?', [$this->decisionOf($rid)]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'social.disbursement_prepared' AND auditable_id = ?", [$rid]));
    }

    /** Le filet de la base (index unique sur live_social_decision_id, 0027) : la lecture simple de create() voit un instantané pris avant l'attente du verrou. */
    public function test_simultaneous_direct_creates_leave_one_live_disbursement(): void
    {
        $did = $this->decisionOf($this->requestIn('accordee'));
        $out = $this->race(fn () => $this->directCreate($did), $this->createArgs($did), 'SELECT id, social_request_id, decision, aid_kind, amount FROM social_decisions WHERE id = ? FOR UPDATE');
        self::assertSame('DisbursementRuleViolation: Un décaissement est déjà en cours pour cette décision sociale.', $out);
        self::assertSame(1, $this->liveFor($did));
    }

    public function test_preparation_against_direct_create_leaves_one_live_disbursement(): void
    {
        $rid = $this->requestIn('accordee');
        $did = $this->decisionOf($rid);
        $out = $this->race(fn () => $this->link()->prepare($rid, $this->choice(), $this->prep), $this->createArgs($did),
            'SELECT id, social_request_id, decision, aid_kind, amount FROM social_decisions WHERE id = ? FOR UPDATE');
        self::assertSame('DisbursementRuleViolation: Un décaissement est déjà en cours pour cette décision sociale.', $out);
        self::assertSame(1, $this->liveFor($did));
        self::assertSame('en_paiement', $this->socialState($rid));
    }

    public function test_dead_disbursements_do_not_block_the_unique_safety_net(): void
    {
        $first = $this->prepared($rid);
        $this->svc()->revise($first, ['add_files' => [$this->file($this->prep) => 'Extrait']], '', $this->prep);
        $this->svc()->submit($first, $this->prep);
        $this->rejectNow($first, $this->approverA, 'Incomplet');
        $this->link()->paymentFailed($rid, $this->prep);
        $second = $this->link()->prepare($rid, $this->choice(), $this->prep);
        self::assertNull($this->db->fetchValue('SELECT live_social_decision_id FROM disbursement_requests WHERE id = ?', [$first]));
        self::assertSame($this->decisionOf($rid), (int) $this->db->fetchValue('SELECT live_social_decision_id FROM disbursement_requests WHERE id = ?', [$second]));
        try { // filet brut : un second vivant forgé en base est refusé
            $this->db->query("UPDATE disbursement_requests SET status = 'brouillon' WHERE id = ?", [$first]);
            self::fail('UNIQUE attendu');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
        self::assertNull($this->db->fetchValue('SELECT live_social_decision_id FROM disbursement_requests WHERE social_decision_id IS NULL LIMIT 1'));
    }

    public function test_edit_form_of_linked_disbursement_shows_amount_and_member_read_only(): void
    {
        $id = $this->prepared();
        $this->as($this->prep);
        $html = $this->request('GET', "/admin/decaissements/$id/modifier")->body();
        self::assertMatchesRegularExpression('/<input type="text"[^>]*name="amount"[^>]*readonly[^>]*value="50000"/', $html);
        self::assertStringContainsString('Montant fixé par la décision sociale', $html);
        self::assertStringContainsString('<dt>Adhérent bénéficiaire</dt><dd>SA', $html);
        $res = $this->request('POST', "/admin/decaissements/$id/modifier", ['amount' => '60 000', 'beneficiary_name' => 'Kouassi Ama', 'account_id' => (string) $this->account,
            'category_id' => (string) $this->category, 'purpose' => 'x', 'internal_note' => '', 'reason' => '', 'label' => '']);
        self::assertSame(422, $res->status(), 'le serveur refuse toujours');
        self::assertSame('50000.00', $this->db->fetchValue('SELECT amount FROM disbursement_requests WHERE id = ?', [$id]));
        $plain = $this->draft();
        $this->as($this->initiator);
        self::assertDoesNotMatchRegularExpression('/name="amount"[^>]*readonly/', $this->request('GET', "/admin/decaissements/$plain/modifier")->body());
    }

    public function test_link_actions_lock_first_and_audit_last(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Modules/Social/SocialDisbursementLink.php');
        foreach (['prepare', 'paymentFailed'] as $action) {
            $start = strpos($src, "public function $action(");
            $body = substr($src, $start, strpos($src, "\n    }\n", $start) - $start);
            $closure = substr($body, strpos($body, '$this->run(function'));
            self::assertMatchesRegularExpression('/^\$this->run\(function [^{]*\{\s*\$r = \$this->lock\(\$requestId, \$actor\);\s*\$d = \$this->decision\(\$requestId\);/', $closure, "$action : demande puis décision verrouillées d'abord");
            $audit = strrpos($closure, '$this->auditTransition(');
            self::assertNotFalse($audit);
            self::assertMatchesRegularExpression('/^\$this->auditTransition\([^;]*;\s*(return \$\w+;\s*)?\}\);\s*$/', substr($closure, $audit), "$action : rien après l'audit");
            self::assertLessThan($audit, strrpos($closure, '$this->event('), "$action : l'événement avant l'audit");
        }
        $prepare = substr($src, strpos($src, 'public function prepare('));
        self::assertLessThan(strpos($prepare, '$this->disbursements->create('), strpos($prepare, '$this->event('), 'statut et événement avant l\'appel au circuit L6');
        self::assertStringContainsString('SELECT * FROM social_requests WHERE id = ? FOR UPDATE', $src);
        self::assertStringContainsString('FROM social_decisions WHERE social_request_id = ? FOR UPDATE', $src);
    }

    private function as(int $user): void
    {
        $this->actingAs($user);
        $this->services['gate']()->forget($user);
    }
}
