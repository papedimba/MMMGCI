<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Sequence;
use App\Modules\Cms\FormInbox;
use App\Modules\Cms\FormInvalid;
use App\Modules\Cms\FormRejected;
use App\Modules\Cms\SiteSettings;
use Tests\Support\AdminTestCase;

final class FormInboxTest extends AdminTestCase
{
    private const EVIL = '<script>alert(1)</script>';
    private int $manager;
    private int $other;
    private int $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM cms_form_submissions');
        $this->db->query("DELETE FROM login_attempts WHERE succeeded = 5");
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->db->query('DELETE FROM users');
        $this->manager = $this->createUser('gest@x.test', ['gest_cms']);
        $this->other = $this->createUser('admin@x.test', ['admin_site']);
        $this->writer = $this->createUser('redac@x.test', ['redacteur']);
    }

    private function inbox(): FormInbox
    {
        $this->app();
        $gate = $this->services['gate']();
        $audit = $this->services['audit']();
        return new FormInbox($this->db, $audit, $gate, new Sequence($this->db), new SiteSettings($this->db, $audit, $gate), $this->services['mailer'], static fn (callable $job) => $job(), 'https://mmmgci.exemple.ci');
    }

    /** @return array<string,string> */
    private function contact(array $over = []): array
    {
        return $over + ['name' => 'Awa Koné', 'email' => 'awa@exemple.ci', 'phone' => '+225 07 00 00 00 00', 'subject' => 'Bonjour', 'message' => 'Je souhaite des informations.'];
    }

    private function sent(): array
    {
        return array_map(static fn (array $m): array => ['to' => $m['to'], 'subject' => $m['subject'], 'text' => base64_decode(str_replace("\r\n", '', $m['body']))], $this->mail->messages);
    }

    public function test_invisible_characters_are_stripped_and_history_is_readable(): void
    {
        $in = $this->inbox();
        $id = $in->submit('contact', $this->contact(['name' => "Awa\u{202E}Kon\u{200B}é \u{1F468}\u{200D}\u{1F469}"]), '10.7.0.1');
        $this->assertSame("AwaKoné \u{1F468}\u{200D}\u{1F469}", $in->get($id)['payload']['name']);
        $in->addNote($id, "no\u{2066}te", $this->manager);
        $this->assertSame('note', $in->get($id)['history'][1]['note']);
        $this->actingAs($this->manager);
        $res = $this->request('POST', "/admin/site/formulaires/$id/etat", ['to' => ['x'], 'note' => ['y']]);
        $this->assertSame(422, $res->status());
        $this->assertStringNotContainsString('Array', $res->body());
        $this->request('POST', "/admin/site/formulaires/$id/etat", ['to' => 'en_cours']);
        $body = $this->request('GET', "/admin/site/formulaires/$id")->body();
        $this->assertStringContainsString('Passé à « En cours »', $body);
        $this->assertStringContainsString('Utilisateur gest', $body);
        $this->assertStringNotContainsString('etat:en_cours', $body);
    }

    public function test_form_submission_is_throttled_by_ip_and_honeypot(): void
    {
        $in = $this->inbox();
        $id = $in->submit('contact', $this->contact(), '10.0.0.1');
        $row = $in->get($id);
        $this->assertMatchesRegularExpression('/^MSG-\d{4}-\d{5}$/', $row['reference']);
        $this->assertSame('nouveau', $row['status']);
        $this->assertSame('Awa Koné', $row['payload']['name']);
        // Champ piège rempli : refus générique, rien d'enregistré.
        try {
            $in->submit('contact', $this->contact(['website' => 'http://spam.test']), '10.0.0.2');
            $this->fail('piège');
        } catch (FormRejected) {
            $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_form_submissions'));
        }
        // Formulaire inconnu.
        $this->expectRejected(fn () => $in->submit('inconnu', $this->contact(), '10.0.0.3'));
        // Limite par IP : 5 par fenêtre (les refus comptent), les autres adresses ne sont pas touchées.
        for ($i = 0; $i < FormInbox::MAX_PER_WINDOW; $i++) {
            $in->submit('contact', $this->contact(), '10.0.0.9');
        }
        $this->expectRejected(fn () => $in->submit('contact', $this->contact(), '10.0.0.9'));
        $in->submit('contact', $this->contact(), '10.0.0.10');
        // Champs invalides : erreurs par champ, rien d'enregistré, champs inconnus ignorés.
        $this->db->query('DELETE FROM login_attempts WHERE succeeded = 5'); // chaque envoi refusé compte : on repart d'une fenêtre vide
        $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_form_submissions');
        foreach ([['email' => 'x'], ['message' => ''], ['name' => str_repeat('a', 121)], ['message' => self::EVIL], ['phone' => 'abc'], ['email' => "a@b.ci\nBcc: z@z.z"]] as $bad) {
            try {
                $in->submit('contact', $this->contact($bad), '10.0.' . crc32(json_encode($bad)) % 200 . '.20');
                $this->fail(json_encode($bad));
            } catch (FormInvalid $e) {
                $this->assertSame(array_key_first($bad), array_key_first($e->errors));
            }
        }
        $this->assertSame($before, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_form_submissions'));
        $this->db->query('DELETE FROM login_attempts WHERE succeeded = 5');
        $id = $in->submit('contact', $this->contact(['admin' => 'ignoré', 'message' => "a\x00b\x07c\nd"]), '10.0.0.30');
        $p = $in->get($id)['payload'];
        $this->assertArrayNotHasKey('admin', $p);
        $this->assertSame("abc\nd", $p['message'], 'caractères de contrôle retirés');
    }

    private function expectRejected(callable $fn): void
    {
        try {
            $fn();
            $this->fail('refus attendu');
        } catch (FormRejected $e) {
            $this->assertStringNotContainsString('Koné', $e->getMessage());
        }
    }

    public function test_notification_goes_to_validated_address_or_managers(): void
    {
        $in = $this->inbox();
        $in->submit('contact', $this->contact(['message' => 'Secret du visiteur 12345']), '10.1.0.1');
        $to = array_column($this->sent(), 'to');
        sort($to);
        $this->assertSame(['admin@x.test', 'gest@x.test'], $to, 'gestionnaires actifs seulement (pas le rédacteur)');
        $text = $this->sent()[0]['text'];
        $this->assertMatchesRegularExpression('/MSG-\d{4}-\d{5}/', $text);
        $this->assertStringContainsString('Contact', $text);
        $this->assertStringContainsString('https://mmmgci.exemple.ci/admin/site/formulaires/', $text);
        foreach (['Secret du visiteur', 'awa@exemple.ci', 'Awa', '07 00'] as $pii) {
            $this->assertStringNotContainsString($pii, $text . $this->sent()[0]['subject']);
        }
        // Adresse saisie mais non validée : ignorée. Validée : seule destinataire.
        $this->mail->messages = [];
        $set = new SiteSettings($this->db, $this->services['audit'](), $this->services['gate']());
        $set->save('contact_email', 'contact@exemple.ci', $this->other);
        $in->submit('contact', $this->contact(), '10.1.0.2');
        $this->assertCount(2, $this->mail->messages);
        $this->mail->messages = [];
        $validator = $this->createUser('pca@x.test', ['pca']);
        $seen = array_column($set->all(), 'seen', 'key')['contact_email'];
        $set->validate('contact_email', $validator, 'PV-1', $seen);
        $in->submit('contact', $this->contact(), '10.1.0.3');
        $this->assertSame(['contact@exemple.ci'], array_column($this->sent(), 'to'));
    }

    public function test_audit_has_no_personal_data_from_submissions(): void
    {
        $in = $this->inbox();
        $id = $in->submit('contact', $this->contact(['message' => 'Texte-unique-9876']), '10.2.0.1');
        $in->transition($id, 'en_cours', $this->manager, 'Note-privee-5555');
        $in->addNote($id, 'Autre-note-4444', $this->manager);
        $in->exportCsv([], $this->manager);
        $dump = json_encode($this->db->fetchAll("SELECT action, old_values, new_values, reference, ip FROM audit_logs WHERE action LIKE 'cms.form%'"), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('cms.form_received', $dump);
        $this->assertStringContainsString('MSG-', $dump);
        foreach (['Texte-unique', 'Note-privee', 'Autre-note', 'awa@exemple.ci', 'Koné', '07 00', '10.2.0.1'] as $pii) {
            $this->assertStringNotContainsString($pii, $dump);
        }
    }

    public function test_status_history_is_append_only(): void
    {
        $in = $this->inbox();
        $id = $in->submit('contact', $this->contact(), '10.3.0.1');
        $this->assertCount(1, $in->get($id)['history']);
        $first = $in->get($id)['history'][0];
        $in->transition($id, 'en_cours', $this->manager);
        $in->assign($id, $this->other, $this->manager);
        $in->addNote($id, 'Rappeler demain', $this->manager);
        $in->transition($id, 'traite', $this->manager, 'Réponse envoyée');
        $in->transition($id, 'en_cours', $this->manager); // retour permis depuis « traité »
        $in->transition($id, 'traite', $this->manager);
        $in->transition($id, 'archive', $this->manager);
        $h = $in->get($id)['history'];
        $this->assertSame($first, $h[0]);
        $this->assertSame(['recu', 'etat:en_cours', 'affectation', 'note', 'etat:traite', 'etat:en_cours', 'etat:traite', 'etat:archive'], array_column($h, 'action'));
        $this->assertSame('Rappeler demain', $h[3]['note']);
        $this->assertSame($this->manager, $h[3]['by']);
        $this->assertArrayHasKey('at', $h[3]);
        $this->assertSame('archive', $in->get($id)['status']);
        // Transitions interdites, affectation à un non-gestionnaire, droits.
        foreach ([[$id, 'en_cours'], [$id, 'nouveau']] as [$i, $to]) {
            try {
                $in->transition($i, $to, $this->manager);
                $this->fail($to);
            } catch (\DomainException) {
            }
        }
        $id2 = $in->submit('contact', $this->contact(), '10.3.0.2');
        foreach ([fn () => $in->transition($id2, 'traite', $this->manager), fn () => $in->assign($id2, $this->writer, $this->manager),
            fn () => $in->transition($id2, 'en_cours', $this->writer), fn () => $in->addNote($id2, '  ', $this->manager)] as $bad) {
            try {
                $bad();
                $this->fail('refus attendu');
            } catch (\DomainException) {
            }
        }
        $this->assertCount(1, $in->get($id2)['history']);
    }

    public function test_submission_payload_is_escaped_in_inbox(): void
    {
        $in = $this->inbox();
        $evil = self::EVIL . '"><img src=x onerror=alert(2)>';
        $id = $in->submit('partenariat', ['organisation' => 'Org', 'name' => 'N', 'email' => 'n@x.ci', 'message' => 'ok'], '10.4.0.1');
        // Le texte hostile ne peut pas entrer par submit (balises refusées) : on l'injecte en base pour vérifier l'échappement à l'affichage.
        $this->db->query('UPDATE cms_form_submissions SET payload = ?, history = ? WHERE id = ?', [json_encode(['name' => $evil, 'message' => $evil, 'organisation' => $evil]),
            json_encode([['at' => '2026-01-01 00:00:00', 'by' => null, 'action' => 'note', 'note' => $evil]]), $id]);
        $this->actingAs($this->manager);
        foreach (['/admin/site/formulaires', "/admin/site/formulaires/$id"] as $url) {
            $res = $this->request('GET', $url);
            $this->assertSame(200, $res->status());
            $this->assertStringNotContainsString('<script>alert(1)', $res->body());
            $this->assertStringNotContainsString('<img src=x', $res->body());
        }
        $this->assertStringContainsString('&lt;script&gt;', $this->request('GET', "/admin/site/formulaires/$id")->body());
        // Note hostile saisie par l'écran : échappée.
        $res = $this->request('POST', "/admin/site/formulaires/$id/notes", ['note' => $evil]);
        $this->assertSame(302, $res->status());
        $this->assertStringNotContainsString('<img src=x', $this->request('GET', "/admin/site/formulaires/$id")->body());
    }

    public function test_export_is_neutralised_and_audited(): void
    {
        $in = $this->inbox();
        $in->submit('contact', $this->contact(['name' => '=HYPERLINK("http://x")', 'subject' => '@cmd', 'message' => '+1 foo']), '10.5.0.1');
        $in->submit('information', ['name' => 'Bob', 'email' => 'bob@x.ci', 'message' => 'Question'], '10.5.0.2');
        $this->actingAs($this->manager);
        $res = $this->request('GET', '/admin/site/formulaires/export.csv?form=contact');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('text/csv', $res->headers()['Content-Type'] ?? '');
        $body = $res->body();
        $this->assertStringContainsString("'=HYPERLINK", $body);
        $this->assertStringContainsString("'@cmd", $body);
        $this->assertStringContainsString("'+1 foo", $body);
        $this->assertStringNotContainsString('Bob', $body, 'filtre appliqué');
        $log = $this->db->fetch("SELECT new_values, user_id FROM audit_logs WHERE action = 'cms.forms_exported' ORDER BY id DESC LIMIT 1");
        $this->assertSame(['count' => 1], json_decode($log['new_values'], true));
        $this->assertSame($this->manager, (int) $log['user_id']);
        $this->actingAs($this->writer);
        $this->assertSame(403, $this->request('GET', '/admin/site/formulaires/export.csv')->status());
    }

    public function test_inbox_screens_permissions_filters_csrf(): void
    {
        $in = $this->inbox();
        $a = $in->submit('contact', $this->contact(), '10.6.0.1');
        $b = $in->submit('information', ['name' => 'Bob', 'email' => 'bob@x.ci', 'message' => 'Question'], '10.6.0.2');
        $in->transition($b, 'en_cours', $this->manager);
        $this->actingAs($this->writer);
        foreach (['/admin/site/formulaires', "/admin/site/formulaires/$a"] as $u) {
            $this->assertSame(403, $this->request('GET', $u)->status());
        }
        $this->assertSame(403, $this->request('POST', "/admin/site/formulaires/$a/etat", ['to' => 'en_cours'])->status());
        $this->actingAs($this->manager);
        $list = $this->request('GET', '/admin/site/formulaires?status=nouveau');
        $this->assertSame(200, $list->status());
        $this->assertStringContainsString($in->get($a)['reference'], $list->body());
        $this->assertStringNotContainsString($in->get($b)['reference'], $list->body());
        $this->assertStringNotContainsString($in->get($a)['reference'], $this->request('GET', '/admin/site/formulaires?form=information')->body());
        $this->assertSame(200, $this->request('GET', "/admin/site/formulaires/$a")->status());
        $this->assertSame(404, $this->request('GET', '/admin/site/formulaires/999999')->status());
        $res = $this->request('POST', "/admin/site/formulaires/$a/etat", ['to' => 'en_cours']);
        $this->assertSame(302, $res->status());
        $this->assertSame('en_cours', $in->get($a)['status']);
        $this->assertSame(422, $this->request('POST', "/admin/site/formulaires/$a/etat", ['to' => 'nouveau'])->status());
        $this->assertSame(302, $this->request('POST', "/admin/site/formulaires/$a/affectation", ['assigned_to' => (string) $this->other])->status());
        $this->assertSame($this->other, (int) $in->get($a)['assigned_to']);
        $res = $this->app()->handle(new \App\Core\Http\Request('POST', "/admin/site/formulaires/$a/etat", [], ['to' => 'traite', '_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertSame(419, $res->status());
        $this->assertSame('en_cours', $in->get($a)['status']);
    }
}
