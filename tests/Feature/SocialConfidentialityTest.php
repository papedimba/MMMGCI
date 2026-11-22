<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit;
use App\Core\Gate;
use App\Core\Mail\ArrayTransport;
use App\Core\Mail\Transport;
use App\Core\Mailer;
use App\Core\NotFound;
use App\Core\Sequence;
use App\Core\UploadError;
use App\Core\Uploads;
use App\Modules\Social\SocialAccess;
use App\Modules\Social\SocialAccessDenied;
use App\Modules\Social\SocialDocumentService;
use App\Modules\Social\SocialMedicalService;
use App\Modules\Social\SocialNoteService;
use App\Modules\Social\SocialNotifier;
use App\Modules\Social\SocialRequestService;
use App\Modules\Social\SocialRuleViolation;
use App\Modules\Social\SocialTransitionInvalid;
use Tests\DatabaseTestCase;
use Tests\Support\SocialFixtures;

final class SocialConfidentialityTest extends DatabaseTestCase
{
    use SocialFixtures;

    private const S = 'SENTINELLE-SANTE';
    private const APP_URL = 'https://mmmgci.test';
    private string $root;
    private int $medic;
    private int $finance;
    private ArrayTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSocial();
        foreach (['so_medic' => ['social.manage', 'social.view', 'social.medical.view', 'members.scope.all'], 'so_fin' => ['finance.view', 'members.scope.all'],
            'so_all_zone' => ['social.create', 'social.view', 'social.manage', 'social.decide', 'social.medical.view']] as $role => $perms) {
            $this->db->query('INSERT IGNORE INTO roles (name, label) VALUES (?, ?)', [$role, $role]);
            foreach ($perms as $p) {
                $this->db->query('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.name = ? AND p.name = ?', [$role, $p]);
            }
        }
        $this->medic = $this->socialUser('medic', ['so_medic']);
        $this->finance = $this->socialUser('finance', ['so_fin']);
        $this->root = sys_get_temp_dir() . '/mmmgci-so-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->mail = new ArrayTransport();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function audit(): Audit
    {
        return new Audit($this->db, 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    private function uploads(): Uploads
    {
        return new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1024 * 1024, true);
    }

    private function docs(): SocialDocumentService
    {
        return new SocialDocumentService($this->db, $this->audit(), new Gate($this->db), $this->uploads());
    }

    private function notes(): SocialNoteService
    {
        return new SocialNoteService($this->db, $this->audit(), new Gate($this->db));
    }

    private function medical(): SocialMedicalService
    {
        return new SocialMedicalService($this->db, $this->audit(), new Gate($this->db));
    }

    private function access(): SocialAccess
    {
        return new SocialAccess($this->db, new Gate($this->db), $this->audit());
    }

    private function notifyingSvc(?Transport $transport = null): SocialRequestService
    {
        $mailer = new Mailer($transport ?? $this->mail, 'no-reply@mmmgci.test');
        $notifier = new SocialNotifier($this->db, static fn (): Mailer => $mailer, static fn (callable $job) => $job(), self::APP_URL);
        return new SocialRequestService($this->db, $this->audit(), new Sequence($this->db), new Gate($this->db), $notifier);
    }

    /** @return array<string,mixed> */
    private function pdf(string $name = 'certificat.pdf'): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
    }

    private function sensitiveCount(int $id): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM sensitive_access_logs WHERE subject_type = 'social_request' AND subject_id = ? AND field_group = 'donnees_medicales'", [$id]);
    }

    private function deniedCount(int $id): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'access.denied' AND auditable_type = 'social_request' AND auditable_id = ?", [$id]);
    }

    /** @param class-string<\Throwable> $class */
    private function refuses(callable $fn, string $class): void
    {
        try {
            $fn();
            self::fail("$class attendue");
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, $e->getMessage());
        }
    }

    public function test_medical_details_require_permission_and_each_read_is_logged(): void
    {
        $id = $this->requestIn('en_instruction');
        $this->refuses(fn () => $this->medical()->write($id, 'Diagnostic', $this->manager), SocialRuleViolation::class); // social.manage sans social.medical.view
        self::assertNull($this->db->fetchValue('SELECT details FROM social_request_medical WHERE social_request_id = ?', [$id]));
        $this->medical()->write($id, 'Diagnostic confidentiel', $this->medic);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT contains_medical_data FROM social_requests WHERE id = ?', [$id]));
        $this->medical()->write($id, 'Diagnostic révisé', $this->medic); // mise à jour, une seule ligne
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_medical WHERE social_request_id = ?', [$id]));
        self::assertSame(0, $this->sensitiveCount($id), 'écrire n\'est pas consulter');

        foreach ([$this->manager, $this->decider, $this->plain] as $user) {
            $denied = $this->deniedCount($id);
            $this->refuses(fn () => $this->medical()->read($id, $user, '10.0.0.1'), SocialAccessDenied::class);
            self::assertSame($denied + 1, $this->deniedCount($id), 'refus journalisé');
        }
        self::assertSame(0, $this->sensitiveCount($id));
        self::assertSame('Diagnostic révisé', $this->medical()->read($id, $this->medic, '10.0.0.2'));
        self::assertSame('Diagnostic révisé', $this->medical()->read($id, $this->medic, '10.0.0.2'));
        self::assertSame(2, $this->sensitiveCount($id), 'chaque lecture est journalisée');
        $log = $this->db->fetch("SELECT user_id, ip FROM sensitive_access_logs WHERE subject_type = 'social_request' AND subject_id = ? ORDER BY id DESC LIMIT 1", [$id]);
        self::assertSame([$this->medic, '10.0.0.2'], [(int) $log['user_id'], $log['ip']]);
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'social.medical_updated' AND auditable_id = ? AND JSON_EXTRACT(new_values, '$.created') = true", [$id]));
    }

    public function test_medical_document_download_logged_every_time(): void
    {
        $id = $this->requestIn('en_instruction');
        $doc = $this->docs()->add($id, $this->pdf(), 'piece', 'Compte rendu', true, $this->manager);
        $before = $this->sensitiveCount($id);
        $denied = $this->deniedCount($id);
        $this->refuses(fn () => $this->docs()->stream($id, $doc, $this->manager, '10.0.0.3'), SocialAccessDenied::class);
        self::assertSame($denied + 1, $this->deniedCount($id));
        self::assertSame($before, $this->sensitiveCount($id), 'un refus n\'est pas une consultation');
        for ($i = 1; $i <= 3; $i++) {
            $path = $this->docs()->stream($id, $doc, $this->medic, '10.0.0.4');
            self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
            self::assertSame($before + $i, $this->sensitiveCount($id));
        }
        // Une pièce ordinaire n'est pas une donnée de santé : pas de journal médical.
        $plainDoc = $this->docs()->add($id, $this->pdf(), 'piece', 'Facture', false, $this->manager);
        $this->docs()->stream($id, $plainDoc, $this->manager, null);
        self::assertSame($before + 3, $this->sensitiveCount($id));
    }

    public function test_medical_document_label_hidden_without_permission(): void
    {
        $id = $this->requestIn('en_instruction');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT contains_medical_data FROM social_requests WHERE id = ?', [$id]));
        $this->docs()->add($id, $this->pdf('radio-poumon.pdf'), 'piece', 'Radiographie pulmonaire', true, $this->manager);
        $this->docs()->add($id, $this->pdf('facture.pdf'), 'piece', null, false, $this->manager);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT contains_medical_data FROM social_requests WHERE id = ?', [$id]));

        $seen = $this->docs()->list($id, $this->manager, null);
        self::assertCount(2, $seen);
        self::assertSame([SocialDocumentService::RESTRICTED_LABEL, 'facture.pdf'], array_column($seen, 'label'));
        self::assertSame('Pièce médicale (accès restreint)', SocialDocumentService::RESTRICTED_LABEL);
        self::assertSame([false, true], array_column($seen, 'downloadable'));
        $dump = json_encode($seen, JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Radiographie', $dump);
        self::assertStringNotContainsString('radio-poumon', $dump);
        self::assertSame(0, $this->sensitiveCount($id));

        $medic = $this->docs()->list($id, $this->medic, null);
        self::assertSame(['Radiographie pulmonaire', 'facture.pdf'], array_column($medic, 'label'));
        self::assertSame(1, $this->sensitiveCount($id), 'libellé médical montré : consultation journalisée');
    }

    public function test_social_view_only_sees_no_reviews_notes_or_documents(): void
    {
        $id = $this->requestIn('en_instruction');
        $this->socialSvc()->addReview($id, 'favorable', null, 'Avis détaillé', $this->manager);
        $this->notes()->add($id, 'Note interne', $this->manager);
        $doc = $this->docs()->add($id, $this->pdf(), 'piece', 'Facture', false, $this->manager);
        $this->docs()->add($id, $this->pdf(), 'piece', 'Certificat', true, $this->manager);
        self::assertTrue($this->access()->canSeeRequest($this->plain, $id));
        self::assertSame([], $this->notes()->list($id, $this->plain));
        self::assertSame([], $this->notes()->reviews($id, $this->plain));
        self::assertSame([], $this->docs()->list($id, $this->plain, null));
        $this->refuses(fn () => $this->docs()->stream($id, $doc, $this->plain, null), SocialAccessDenied::class);
        $this->refuses(fn () => $this->medical()->read($id, $this->plain, null), SocialAccessDenied::class);
        $this->refuses(fn () => $this->notes()->add($id, 'Essai', $this->plain), SocialRuleViolation::class);
        // Le gestionnaire et le décideur voient l'instruction.
        foreach ([$this->manager, $this->decider] as $user) {
            self::assertSame(['Note interne'], array_column($this->notes()->list($id, $user), 'body'));
            self::assertSame(['Avis détaillé'], array_column($this->notes()->reviews($id, $user), 'comment'));
            self::assertCount(2, $this->docs()->list($id, $user, null));
        }
        self::assertSame('Note interne, jamais montrée à l\'adhérent. Ne saisissez pas de donnée de santé ici.', SocialNoteService::WARNING);
        self::assertSame(['add', 'list', 'reviews'], array_values(array_filter(get_class_methods(SocialNoteService::class), fn (string $m): bool => $m !== '__construct')),
            'notes en ajout seul : ni modification ni suppression');
    }

    public function test_finance_view_sees_nothing_of_social_requests(): void
    {
        $id = $this->requestIn('en_instruction');
        $doc = $this->docs()->add($id, $this->pdf(), 'piece', 'Facture', false, $this->manager);
        $this->notes()->add($id, 'Note', $this->manager);
        self::assertFalse($this->access()->canSeeRequest($this->finance, $id));
        // Lecture : la demande n'existe pas pour lui (404).
        foreach ([fn () => $this->docs()->list($id, $this->finance, null), fn () => $this->docs()->stream($id, $doc, $this->finance, null),
            fn () => $this->notes()->list($id, $this->finance), fn () => $this->notes()->reviews($id, $this->finance),
            fn () => $this->medical()->read($id, $this->finance, null)] as $read) {
            $this->refuses($read, NotFound::class);
        }
        // Écriture : introuvable aussi sans social.view (aucun oracle d'existence), rien n'est écrit.
        foreach ([fn () => $this->notes()->add($id, 'x', $this->finance), fn () => $this->docs()->add($id, $this->pdf(), 'piece', null, false, $this->finance),
            fn () => $this->medical()->write($id, 'x', $this->finance)] as $write) {
            $this->refuses($write, NotFound::class);
        }
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_notes WHERE social_request_id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents WHERE social_request_id = ?', [$id]));
    }

    public function test_document_outside_scope_is_not_found(): void
    {
        $id = $this->requestIn('en_instruction');
        $doc = $this->docs()->add($id, $this->pdf(), 'piece', 'Facture', false, $this->manager);
        $med = $this->docs()->add($id, $this->pdf(), 'piece', 'Certificat', true, $this->manager);
        $outsiders = ['zone B' => $this->socialUser('zb', ['so_all_zone'], $this->zoneB), 'inactif zone A' => $this->socialUser('za', ['so_all_zone'], $this->zoneA, false),
            'sans zone' => $this->socialUser('nz', ['so_all_zone'])];
        $docs = (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents');
        $notes = (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_notes');
        foreach ($outsiders as $who => $u) {
            foreach (['stream' => fn () => $this->docs()->stream($id, $doc, $u, null), 'stream médical' => fn () => $this->docs()->stream($id, $med, $u, null),
                'liste' => fn () => $this->docs()->list($id, $u, null), 'dépôt' => fn () => $this->docs()->add($id, $this->pdf(), 'piece', null, false, $u),
                'note' => fn () => $this->notes()->add($id, 'x', $u), 'notes' => fn () => $this->notes()->list($id, $u),
                'médical' => fn () => $this->medical()->read($id, $u, null), 'médical écrit' => fn () => $this->medical()->write($id, 'x', $u)] as $what => $call) {
                try {
                    $call();
                    self::fail("$who / $what : 404 attendu");
                } catch (NotFound) {
                    self::assertTrue(true);
                }
            }
        }
        self::assertSame($docs, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents'));
        self::assertSame($notes, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_notes'));
        // Pièce d'une autre demande : 404 même pour un gestionnaire habilité.
        $other = $this->requestIn('en_instruction');
        $this->refuses(fn () => $this->docs()->stream($other, $doc, $this->manager, null), NotFound::class);
        $this->refuses(fn () => $this->docs()->stream($id, 999_999_999, $this->manager, null), NotFound::class);
    }

    public function test_closed_request_refuses_new_documents_and_notes(): void
    {
        $id = $this->requestIn('cloturee');
        $files = (int) $this->db->fetchValue('SELECT COUNT(*) FROM files');
        $this->refuses(fn () => $this->docs()->add($id, $this->pdf(), 'piece', null, false, $this->manager), SocialTransitionInvalid::class);
        $this->refuses(fn () => $this->notes()->add($id, 'Trop tard', $this->manager), SocialTransitionInvalid::class);
        $this->refuses(fn () => $this->medical()->write($id, 'Trop tard', $this->medic), SocialTransitionInvalid::class);
        self::assertSame($files, (int) $this->db->fetchValue('SELECT COUNT(*) FROM files'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents WHERE social_request_id = ?', [$id]));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_notes WHERE social_request_id = ?', [$id]));
        self::assertSame([], glob($this->root . '/private/*/*/*') ?: [], 'aucun fichier laissé sur le disque');
        // L'enregistreur (social.create) ne dépose qu'à l'enregistrement (demande reçue).
        $received = $this->registerRequest();
        $this->docs()->add($received, $this->pdf(), 'piece', null, false, $this->creator);
        $this->socialSvc()->assign($received, $this->manager, $this->manager);
        $this->refuses(fn () => $this->docs()->add($received, $this->pdf(), 'piece', null, false, $this->creator), SocialRuleViolation::class);
        $this->refuses(fn () => $this->notes()->add($received, str_repeat('a', 5001), $this->manager), SocialRuleViolation::class);
        $this->refuses(fn () => $this->notes()->add($received, '   ', $this->manager), SocialRuleViolation::class);
        $this->refuses(fn () => $this->docs()->add($received, $this->pdf(), 'autre', null, false, $this->manager), SocialRuleViolation::class);
        $this->refuses(fn () => $this->docs()->add($received, $this->pdf(), 'piece', str_repeat('a', 161), false, $this->manager), SocialRuleViolation::class);
    }

    public function test_no_free_text_or_medical_data_in_audit_sensitive_log_or_mail(): void
    {
        $s = $this->notifyingSvc();
        $id = $this->registerRequest(['description' => 'Description ' . self::S, 'contains_medical_data' => true]);
        $this->docs()->add($id, $this->pdf(self::S . '.pdf'), 'piece', 'Libellé ' . self::S, true, $this->creator, '10.0.0.5');
        $s->assign($id, $this->medic, $this->manager);
        $this->medical()->write($id, 'Détails ' . self::S, $this->medic);
        $this->medical()->read($id, $this->medic, null);
        $this->docs()->list($id, $this->medic, null);
        $this->notes()->add($id, 'Note ' . self::S, $this->medic);
        $s->requestDocuments($id, 'Pièces ' . self::S, $this->medic);
        $s->resumeInstruction($id, $this->medic, 'Reprise ' . self::S);
        $s->addReview($id, 'favorable', null, 'Avis ' . self::S, $this->medic);
        $s->submitForDecision($id, $this->medic);
        $s->decide($id, $this->grant(['aid_kind' => 'orientation', 'amount' => null, 'reason' => 'Motif ' . self::S, 'member_message' => 'Message ' . self::S]), $this->decider);
        $s->close($id, 'Clôture ' . self::S, $this->medic);
        $doc = (int) $this->db->fetchValue('SELECT id FROM social_request_documents WHERE social_request_id = ?', [$id]);
        $this->docs()->stream($id, $doc, $this->medic, null);
        $this->refuses(fn () => $this->docs()->stream($id, $doc, $this->manager, null), SocialAccessDenied::class);
        $this->refuses(fn () => $this->medical()->read($id, $this->manager, null), SocialAccessDenied::class);

        self::assertStringContainsString(self::S, (string) $this->db->fetchValue('SELECT details FROM social_request_medical WHERE social_request_id = ?', [$id]), 'témoin');
        foreach (['audit_logs', 'sensitive_access_logs'] as $table) {
            $dump = json_encode($this->db->fetchAll("SELECT * FROM $table"), JSON_UNESCAPED_UNICODE);
            self::assertStringNotContainsString(self::S, (string) $dump, $table);
        }
        self::assertNotEmpty($this->mail->messages);
        foreach ($this->mail->messages as $m) {
            self::assertStringNotContainsString(self::S, $m['subject'] . base64_decode(str_replace("\r\n", '', $m['body'])) . json_encode($m['headers']));
        }
    }

    public function test_assignment_mail_contains_only_reference_and_link(): void
    {
        $id = $this->registerRequest(['description' => 'Hospitalisation urgente']);
        $ref = (string) $this->db->fetchValue('SELECT reference FROM social_requests WHERE id = ?', [$id]);
        $this->notifyingSvc()->assign($id, $this->manager, $this->manager2);
        self::assertCount(1, $this->mail->messages);
        $m = $this->mail->messages[0];
        self::assertSame((string) $this->db->fetchValue('SELECT email FROM users WHERE id = ?', [$this->manager]), $m['to']);
        $body = base64_decode(str_replace("\r\n", '', $m['body']));
        $subject = mb_decode_mimeheader($m['subject']);
        self::assertStringContainsString($ref, $body);
        self::assertStringContainsString(self::APP_URL . '/admin/demandes-sociales/' . $id, $body);
        foreach (['Kouassi', 'Ama', 'Maladie', 'maladie', 'Hospitalisation', 'manager', (string) $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$this->memberA])] as $leak) {
            self::assertStringNotContainsString($leak, $subject . $body, $leak);
        }
        // Réaffectation : le nouvel affecté est prévenu ; un affecté sans adresse ne reçoit rien et rien n'échoue.
        $this->notifyingSvc()->assign($id, $this->manager2, $this->manager);
        self::assertCount(2, $this->mail->messages);
        self::assertSame((string) $this->db->fetchValue('SELECT email FROM users WHERE id = ?', [$this->manager2]), $this->mail->messages[1]['to']);
    }

    public function test_mail_failure_does_not_roll_back_assignment(): void
    {
        $failing = new class implements Transport {
            public function send(string $to, string $subject, string $body, array $headers): void
            {
                throw new \RuntimeException('SMTP indisponible');
            }
        };
        $id = $this->registerRequest();
        $log = ini_get('error_log');
        ini_set('error_log', $this->root . '/php.log');
        try {
            $this->notifyingSvc($failing)->assign($id, $this->manager, $this->manager);
        } finally {
            ini_set('error_log', (string) $log);
        }
        self::assertSame('en_instruction', $this->state($id));
        self::assertSame($this->manager, (int) $this->db->fetchValue('SELECT assigned_to FROM social_requests WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'social.assigned' AND auditable_id = ?", [$id]));
        $written = (string) @file_get_contents($this->root . '/php.log');
        self::assertStringContainsString('Notification d\'affectation non envoyée', $written, 'échec journalisé');
        self::assertStringNotContainsString('@so.test', $written, 'sans adresse');
    }

    public function test_uploads_are_private_and_type_checked(): void
    {
        $id = $this->requestIn('en_instruction');
        $docs = (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents');
        $tmp = $this->root . '/bad.tmp';
        file_put_contents($tmp, "MZ\x90\x00 binaire");
        foreach (['virus.exe', 'faux.pdf'] as $name) {
            $this->refuses(fn () => $this->docs()->add($id, ['name' => $name, 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK], 'piece', null, false, $this->manager),
                UploadError::class);
        }
        self::assertSame($docs, (int) $this->db->fetchValue('SELECT COUNT(*) FROM social_request_documents'));
        $doc = $this->docs()->add($id, $this->pdf(), 'piece', null, true, $this->manager);
        $file = $this->db->fetch('SELECT f.disk, f.path FROM social_request_documents d JOIN files f ON f.id = d.file_id WHERE d.id = ?', [$doc]);
        self::assertSame('private', $file['disk']);
        $path = $this->docs()->stream($id, $doc, $this->medic, null);
        self::assertStringStartsWith((string) realpath($this->root . '/private'), $path);
        self::assertSame(0640, fileperms($path) & 0777);
        self::assertSame([], glob($this->root . '/public/*') ?: []);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM financial_attachments WHERE file_id = (SELECT file_id FROM social_request_documents WHERE id = ?)', [$doc]),
            'pas dans AttachmentService');
    }
}
