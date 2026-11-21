<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Core\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Scheduler;
use App\Core\Settings;
use App\Core\Uploads;
use App\Modules\Site\ApplyFlow;
use Tests\Support\AdminTestCase;

/** Adhésion en ligne (L4, tâche 4) : parcours public en 5 étapes, traité en adversaire. */
final class OnlineApplicationTest extends AdminTestCase
{
    private const BASE = '/adhesion-en-ligne';
    private const ROUTES = [self::BASE, self::BASE . '/etape/1', self::BASE . '/etape/2', self::BASE . '/etape/3', self::BASE . '/etape/4', self::BASE . '/etape/5', self::BASE . '/envoye'];
    private const CONSENT = "Vos données sont traitées par la Mutuelle.\n<b>Texte</b> fourni par la Mutuelle.";
    private string $root;
    private int $now = 1_800_000_000;
    private int $admin;
    private int $zone;
    private int $profession;
    private int $category;
    private Uploads $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-apply-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->uploads = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        $this->overrides['uploads'] = $this->uploads;
        $this->overrides['clock'] = fn (): int => $this->now;
        foreach (['apply_draft_files', 'sensitive_access_logs', 'member_documents', 'membership_application_files', 'membership_applications', 'member_status_history', 'members', 'users', 'zones'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('DELETE FROM login_attempts WHERE succeeded IN (7, 8)');
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_menu_items');
        $this->db->query('DELETE FROM cms_page_redirects');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->admin = $this->createUser('pca@x.test', ['pca', 'gest_adherents']);
        $this->zone = $this->db->insert('zones', ['name' => 'Abidjan', 'type' => 'port']);
        $this->db->insert('zones', ['name' => 'Zone fermée', 'type' => 'port', 'is_active' => 0]);
        $this->profession = $this->db->insert('maritime_professions', ['name' => 'Matelot ' . bin2hex(random_bytes(3))]);
        $this->category = (int) $this->db->fetchValue('SELECT id FROM member_categories WHERE is_active = 1 ORDER BY id LIMIT 1');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function open(mixed $flag = true, mixed $consent = self::CONSENT): void
    {
        $s = new Settings($this->db);
        $s->validate('site', 'adhesion_open', $flag, $this->admin, 'PV test');
        $s->validate('site', 'consent_text', $consent, $this->admin, 'PV test');
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    private function req(string $method, string $path, array $post = [], array $files = [], string $ip = '127.0.0.1'): Response
    {
        $app = $this->app();
        if ($method !== 'GET') {
            $post += ['_csrf' => $this->services['csrf']->token()];
        }
        return $app->handle(new Request($method, $path, [], $post, $files, ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'PHPUnit']));
    }

    /** @param array<string,mixed> $post */
    private function step(int $n, array $post = [], array $files = []): Response
    {
        return $this->req('POST', self::BASE . "/etape/$n", $post + ['op' => 'next'], $files);
    }

    /** @return array<string,string> */
    private function identity(array $over = []): array
    {
        return $over + ['last_name' => 'Kouassi', 'first_names' => 'Yao Serge', 'sex' => 'Masculin', 'birth_date' => '1980-05-01', 'birth_place' => 'Sassandra', 'nationality' => 'Ivoirienne'];
    }

    /** @return array<string,string> */
    private function contact(array $over = []): array
    {
        return $over + ['phone' => '07 01 02 03 04', 'email' => 'yao@exemple.ci', 'zone_id' => (string) $this->zone, 'profession_id' => (string) $this->profession];
    }

    /** Étapes 1 à 4 remplies (via POST simples) ; renvoie la page de l'étape 5. */
    private function fill(array $identity = [], array $contact = [], array $files = []): Response
    {
        $this->assertSame(200, $this->req('GET', self::BASE . '/etape/1')->status());
        $this->assertSame(303, $this->step(1, ['category_id' => (string) $this->category, 'livret' => '1'])->status());
        $this->assertSame(303, $this->step(2, $this->identity($identity))->status());
        $this->assertSame(303, $this->step(3, $this->contact($contact))->status());
        foreach ($files as [$type, $file]) {
            $this->assertSame(303, $this->step(4, ['op' => 'upload', 'type' => $type], ['piece' => $file])->status());
        }
        $this->assertSame(303, $this->step(4)->status());
        $res = $this->req('GET', self::BASE . '/etape/5');
        $this->assertSame(200, $res->status());
        return $res;
    }

    /** Envoi de l'étape 5 avec les champs cachés lus dans la page (draft, t, piège). */
    private function send(Response $step5, array $over = [], string $ip = '127.0.0.1', int $wait = 10): Response
    {
        preg_match('/name="draft" value="([a-f0-9]{64})"/', $step5->body(), $d);
        preg_match('/name="t" value="([0-9.a-f]+)"/', $step5->body(), $t);
        $this->assertNotEmpty($d);
        $this->now += $wait;
        return $this->req('POST', self::BASE . '/etape/5', $over + ['op' => 'submit', 'consent' => '1', 'draft' => $d[1], 't' => $t[1], 'website' => ''], [], $ip);
    }

    private function rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM $table WHERE $where");
    }

    private function pdf(int $bytes = 0): array
    {
        return $this->tmp('cni-kouassi.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n" . str_repeat('a', $bytes) . "\ntrailer<<>>\n%%EOF");
    }

    private function png(string $suffix = ''): array
    {
        $im = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($im);
        return $this->tmp('selfie-kouassi.png', (string) ob_get_clean() . $suffix);
    }

    private function tmp(string $name, string $content): array
    {
        $tmp = $this->root . '/' . bin2hex(random_bytes(6)) . '.tmp';
        file_put_contents($tmp, $content);
        return ['name' => $name, 'tmp_name' => $tmp, 'size' => strlen($content), 'error' => UPLOAD_ERR_OK, 'type' => 'application/octet-stream'];
    }

    private function newVisitor(): void
    {
        $this->forgetApp(); // nouvelle application = nouvelle session vide
    }

    /** @return array<string,int> */
    private function snapshot(): array
    {
        return ['apps' => $this->rows('membership_applications'), 'files' => $this->rows('files'), 'drafts' => $this->rows('apply_draft_files'),
            'throttle' => $this->rows('login_attempts', 'succeeded IN (7, 8)'), 'members' => $this->rows('members'), 'audit' => $this->rows('audit_logs')];
    }

    private function assertClosed(Response $res, string $label): void
    {
        $this->assertSame(200, $res->status(), $label);
        $this->assertStringContainsString('Les adhésions en ligne ouvriront prochainement.', $res->body(), $label);
        $this->assertStringNotContainsString('<form', $res->body(), $label);
        foreach (['adhesion_open', 'consent_text', 'réglage', 'paramètre', 'instances'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $res->body(), "$label : aucun réglage interne cité");
        }
        $this->assertSame('no-store', $res->headers()['Cache-Control'] ?? null);
        $this->assertStringContainsString('noindex', $res->headers()['X-Robots-Tag'] ?? '');
    }

    public function test_closed_until_both_settings_are_validated_for_get_and_post(): void
    {
        $s = new Settings($this->db);
        $cases = [
            'rien de validé' => static function (): void {},
            'ouverture seule' => fn () => $s->validate('site', 'adhesion_open', true, $this->admin, 'PV'),
            'consentement seul' => fn () => $s->validate('site', 'consent_text', self::CONSENT, $this->admin, 'PV'),
            'ouverture = 1 (pas true strict)' => fn () => $this->open(1),
            'ouverture = "true"' => fn () => $this->open('true'),
            'ouverture = false' => fn () => $this->open(false),
            'consentement vide' => fn () => $this->open(true, '   '),
            'consentement non textuel' => fn () => $this->open(true, ['x']),
            'consentement saisi mais non validé' => function (): void {
                $this->open();
                $this->db->query("UPDATE settings SET status = 'non_configure' WHERE `group` = 'site' AND `key` = 'consent_text'");
            },
        ];
        foreach ($cases as $label => $setup) {
            $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure' WHERE `group` = 'site'");
            $setup();
            $this->newVisitor();
            $before = $this->snapshot();
            foreach (self::ROUTES as $path) {
                $this->assertClosed($this->req('GET', $path), "$label GET $path");
                $this->assertClosed($this->req('POST', $path, ['op' => 'submit', 'consent' => '1', 'draft' => str_repeat('a', 64)] + $this->identity() + $this->contact(),
                    ['piece' => $this->pdf()]), "$label POST $path");
            }
            $this->assertSame($before, $this->snapshot(), "$label : rien n'est écrit");
            $this->assertNull($this->services['session']->get(ApplyFlow::SESSION), "$label : aucun brouillon");
        }
        // Les deux réglages validés (true strict, texte non vide) : ouvert.
        $this->open();
        $this->newVisitor();
        $this->assertSame(303, $this->req('GET', self::BASE)->status());
        $res = $this->req('GET', self::BASE . '/etape/1');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Étape 1 sur 5', $res->body());
        // Fermé de nouveau en cours de route : la garde est relue à chaque requête (aucun drapeau de session).
        $this->step(1);
        $this->db->query("UPDATE settings SET status = 'non_configure' WHERE `group` = 'site' AND `key` = 'adhesion_open'");
        $this->assertClosed($this->req('GET', self::BASE . '/etape/2'), 'fermé en cours');
        $this->assertClosed($this->step(2, $this->identity()), 'POST fermé en cours');
    }

    public function test_closed_page_lists_pieces_only_from_the_published_adhesion_page(): void
    {
        $this->assertStringNotContainsString('pièce d’identité valide', $this->req('GET', self::BASE)->body());
        $this->app();
        $pages = new \App\Modules\Cms\PageService($this->db, $this->services['audit'](), $this->services['gate']());
        $author = $this->createUser('redac@x.test', ['redacteur']);
        $publisher = $this->createUser('editeur@x.test', ['editeur']);
        $pid = $pages->create(['title' => 'Adhésion', 'slug' => 'adhesion', 'blocks' => [['type' => 'dossier', 'title' => 'Dossier', 'intro' => '', 'situations' => [],
            'pieces' => [['label' => 'Une copie d’une pièce d’identité valide', 'situations' => '']]]]], $author);
        $this->assertStringNotContainsString('pièce d’identité valide', $this->req('GET', self::BASE)->body(), 'brouillon : jamais affiché');
        $pages->publish($pid, $publisher, null, (int) $pages->get($pid)['content_version']);
        $body = $this->req('GET', self::BASE)->body();
        $this->assertStringContainsString('Une copie d’une pièce d’identité valide', $body);
        (new Settings($this->db))->validate('site', 'contact_phone', '+225 01 02 03 04 05', $this->admin, 'PV');
        $this->assertStringContainsString('tel:+2250102030405', $this->req('GET', self::BASE)->body());
    }

    public function test_five_steps_keep_state_and_allow_going_back(): void
    {
        $this->open();
        $this->fill();
        // Retour arrière depuis l'étape 3 : rien n'est perdu, y compris ce qui vient d'être saisi sur l'étape quittée.
        $this->assertSame(303, $this->req('POST', self::BASE . '/etape/3', ['op' => 'back'] + $this->contact(['phone' => '0505050505']))->status());
        $two = $this->req('GET', self::BASE . '/etape/2')->body();
        $this->assertStringContainsString('value="Kouassi"', $two);
        $this->assertStringContainsString('value="Masculin" checked', $two);
        $this->assertStringContainsString('Étape 2 sur 5', $two);
        $this->assertStringContainsString('aria-current="step"', $two);
        $this->assertStringContainsString('value="0505050505"', $this->req('GET', self::BASE . '/etape/3')->body());
        $one = $this->req('GET', self::BASE . '/etape/1')->body();
        $this->assertStringContainsString('value="' . $this->category . '" checked', $one);
        $this->assertStringContainsString('name="livret" value="1" checked', $one);
        $recap = $this->req('GET', self::BASE . '/etape/5')->body();
        foreach (['Kouassi', 'Yao Serge', 'Sassandra', 'yao@exemple.ci', 'Abidjan', 'Livret maritime disponible'] as $v) {
            $this->assertStringContainsString($v, $recap);
        }
        // Sauter une étape par l'adresse : renvoyé à la dernière étape atteinte.
        $this->newVisitor();
        $this->req('GET', self::BASE . '/etape/1');
        $res = $this->req('GET', self::BASE . '/etape/4');
        $this->assertSame(303, $res->status());
        $this->assertSame(self::BASE . '/etape/1', $res->headers()['Location']);
        $this->assertSame(303, $this->req('GET', self::BASE . '/etape/9')->status());
    }

    public function test_per_step_validation_errors_are_linked_to_fields_and_keep_values(): void
    {
        $this->open();
        $this->req('GET', self::BASE . '/etape/1');
        $this->step(1);
        $res = $this->step(2, $this->identity(['last_name' => '', 'birth_date' => '31/02/2000', 'sex' => 'X', 'first_names' => 'Awa']));
        $this->assertSame(422, $res->status());
        $body = $res->body();
        $this->assertStringContainsString('href="#f-last_name"', $body);
        $this->assertStringContainsString('aria-describedby="f-last_name-err" aria-invalid="true"', $body);
        $this->assertStringContainsString('id="f-birth_date"', $body);
        $this->assertMatchesRegularExpression('/id="f-birth_date"[^>]*aria-invalid="true"/', $body);
        $this->assertStringContainsString('Sexe : choisissez', $body);
        $this->assertStringContainsString('value="Awa"', $body, 'valeurs gardées');
        $this->assertStringContainsString('Étape 2 sur 5', $body);
        $this->assertSame(303, $this->step(2, $this->identity())->status());
        // Étape 3 : référentiels actifs seulement, téléphone selon MemberService.
        $closed = (int) $this->db->fetchValue("SELECT id FROM zones WHERE name = 'Zone fermée'");
        $res = $this->step(3, $this->contact(['zone_id' => (string) $closed, 'phone' => '12ab', 'email' => 'pas-un-email']));
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Zone : choisissez une valeur dans la liste proposée.', $res->body());
        $this->assertStringContainsString('Téléphone invalide', $res->body());
        $this->assertStringContainsString('Adresse e-mail invalide', $res->body());
        $this->assertStringNotContainsString('Zone fermée', $res->body(), 'zone inactive non proposée');
        $this->assertStringNotContainsString('zone_id »', $res->body(), 'jamais le nom technique du champ');
    }

    public function test_submission_creates_an_application_never_a_member(): void
    {
        $this->open();
        $members = $this->rows('members');
        $res = $this->send($this->fill());
        $this->assertSame(303, $res->status());
        $this->assertSame(self::BASE . '/envoye', $res->headers()['Location']);
        $app = $this->db->fetch('SELECT * FROM membership_applications');
        $this->assertSame(1, $this->rows('membership_applications'));
        $this->assertSame('en_ligne', $app['source']);
        $this->assertSame('recue', $app['status']);
        $this->assertNull($app['member_id']);
        $this->assertSame('127.0.0.1', $app['ip']);
        $this->assertMatchesRegularExpression('/^AD-\d{4}-\d{4}$/', $app['reference']);
        $payload = json_decode((string) $app['payload'], true);
        $this->assertSame('Kouassi', $payload['last_name']);
        $this->assertSame((string) $this->zone, $payload['zone_id']);
        $this->assertArrayNotHasKey('livret', $payload, 'indications de situation : brouillon seulement');
        $this->assertSame($members, $this->rows('members'));
        $done = $this->req('GET', self::BASE . '/envoye');
        $this->assertSame(200, $done->status());
        $this->assertStringContainsString((string) $app['reference'], $done->body());
        $this->assertStringContainsString('Votre demande a été reçue. Elle sera examinée par la Mutuelle. Conservez cette référence.', $done->body());
        $this->assertDoesNotMatchRegularExpression('/vous êtes (désormais )?(membre|adhérent)|bienvenue/i', $done->body());
        $this->assertNull($this->services['session']->get(ApplyFlow::SESSION), 'brouillon effacé');
    }

    public function test_double_submit_creates_one_application(): void
    {
        $this->open();
        $step5 = $this->fill();
        $this->assertSame(303, $this->send($step5)->status());
        $replay = $this->send($step5); // double clic, ou retour arrière puis renvoi
        $this->assertSame(200, $replay->status());
        $ref = (string) $this->db->fetchValue('SELECT reference FROM membership_applications');
        $this->assertStringContainsString($ref, $replay->body());
        $this->assertSame(1, $this->rows('membership_applications'));
        // Jeton inconnu (autre session) : refus neutre, rien n'est créé.
        $this->newVisitor();
        $res = $this->send($step5);
        $this->assertSame(303, $res->status());
        $this->assertSame(1, $this->rows('membership_applications'));
    }

    public function test_files_are_private_and_bound_to_the_application(): void
    {
        $this->open();
        $step5 = $this->fill([], [], [['identite', $this->pdf()], ['photo', $this->png()], ['autre', $this->pdf()]]);
        $this->assertSame(3, $this->rows('apply_draft_files'));
        $this->assertSame(3, $this->rows('files', "disk = 'private' AND uploaded_by IS NULL"));
        $this->assertSame(0, $this->rows('files', "original_name LIKE '%kouassi%'"), 'nom du visiteur jamais conservé');
        $this->assertSame(['identite.pdf', 'photo.png', 'autre.pdf'], array_column($this->db->fetchAll('SELECT f.original_name FROM apply_draft_files d JOIN files f ON f.id = d.file_id ORDER BY d.id'), 'original_name'));
        // Retirer une pièce : ligne, fichier en base et fichier disque supprimés.
        $autre = (int) $this->db->fetchValue("SELECT file_id FROM apply_draft_files WHERE type = 'autre'");
        $path = $this->uploads->path($autre);
        $this->assertSame(303, $this->step(4, ['op' => "remove-$autre"])->status());
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, $this->rows('files', "id = $autre"));
        // Une pièce d'un autre brouillon ne peut pas être retirée.
        $this->assertSame(303, $this->send($step5)->status());
        $id = (int) $this->db->fetchValue('SELECT id FROM membership_applications');
        $rows = $this->db->fetchAll('SELECT type, file_id FROM membership_application_files WHERE application_id = ? ORDER BY id', [$id]);
        $this->assertSame(['identite', 'photo'], array_column($rows, 'type'));
        $this->assertSame(0, $this->rows('apply_draft_files'));
        $this->newVisitor();
        $this->req('GET', self::BASE . '/etape/1');
        $this->step(1);
        $this->step(2, $this->identity());
        $this->step(3, $this->contact());
        $this->step(4, ['op' => 'remove-' . $rows[0]['file_id']]);
        $this->assertSame(1, $this->rows('files', 'id = ' . (int) $rows[0]['file_id']), 'pièce rattachée intouchable');
        // Côté administration : pièces visibles et téléchargeables (journalisé).
        $this->newVisitor();
        $this->actingAs($this->admin);
        $show = $this->req('GET', "/admin/adherents/demandes/$id")->body();
        $this->assertStringContainsString('Pièce d&#039;identité', $show);
        $this->assertStringContainsString('Photographie', $show);
        $this->assertSame(200, $this->req('GET', "/admin/adherents/demandes/$id/pieces/" . $rows[0]['file_id'])->status());
    }

    public function test_hostile_files_are_refused(): void
    {
        $this->open();
        $this->fill();
        $files = $this->rows('files');
        $hostile = [
            'php' => ['identite', $this->tmp('shell.php', '<?php system($_GET["c"]);')],
            'svg' => ['identite', $this->tmp('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>')],
            'html' => ['identite', $this->tmp('x.html', '<html><script>alert(1)</script></html>')],
            'double extension' => ['identite', $this->tmp('x.php.jpg', '<?php echo 1;')],
            'php déguisé en pdf' => ['identite', $this->tmp('x.pdf', '<?php echo 1;')],
            'énorme' => ['identite', $this->pdf(6 * 1048576)],
            'pdf en photo' => ['photo', $this->pdf()],
            'type inconnu' => ['../../etc', $this->pdf()],
        ];
        foreach ($hostile as $label => [$type, $file]) {
            $res = $this->step(4, ['op' => 'upload', 'type' => $type], ['piece' => $file]);
            $this->assertSame(422, $res->status(), $label);
            $this->assertStringContainsString('href="#f-piece"', $res->body(), $label);
        }
        $this->assertSame($files, $this->rows('files'));
        // Polyglotte PNG + PHP : accepté comme image mais ré-encodé, la charge disparaît.
        $this->assertSame(303, $this->step(4, ['op' => 'upload', 'type' => 'photo'], ['piece' => $this->png('<?php system("id"); ?>')])->status());
        $fid = (int) $this->db->fetchValue('SELECT file_id FROM apply_draft_files');
        $this->assertStringNotContainsString('<?php', (string) file_get_contents($this->uploads->path($fid)));
        $this->assertStringStartsWith($this->root . '/private/', $this->uploads->path($fid));
        // Budget total (15 Mo) puis nombre (6).
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(303, $this->step(4, ['op' => 'upload', 'type' => 'autre'], ['piece' => $this->pdf(5_000_000)])->status());
        }
        $res = $this->step(4, ['op' => 'upload', 'type' => 'autre'], ['piece' => $this->pdf(2_000_000)]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('15 Mo', $res->body());
        $this->assertSame(303, $this->step(4, ['op' => 'upload', 'type' => 'autre'], ['piece' => $this->pdf()])->status());
        $this->assertSame(303, $this->step(4, ['op' => 'upload', 'type' => 'autre'], ['piece' => $this->pdf()])->status());
        $res = $this->step(4, ['op' => 'upload', 'type' => 'autre'], ['piece' => $this->pdf()]);
        $this->assertSame(422, $res->status());
        $this->assertSame(ApplyFlow::MAX_FILES, $this->rows('apply_draft_files'));
    }

    public function test_consent_is_required_server_side_and_text_comes_from_settings(): void
    {
        $this->open();
        $step5 = $this->fill();
        $body = $step5->body();
        $this->assertStringContainsString('Vos données sont traitées par la Mutuelle.<br>', $body, 'texte du réglage, sauts de ligne');
        $this->assertStringContainsString('&lt;b&gt;Texte&lt;/b&gt;', $body, 'texte brut échappé');
        $this->assertStringContainsString('J\'ai lu et j\'accepte', $body);
        $res = $this->send($step5, ['consent' => '']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('href="#f-consent"', $res->body());
        $this->assertSame(0, $this->rows('membership_applications'));
        $this->assertSame(422, $this->send($step5, ['consent' => ['1']])->status());
        $this->assertSame(303, $this->send($step5)->status());
        $this->assertSame(1, $this->rows('membership_applications'));
    }

    public function test_honeypot_and_minimum_fill_time_reject_bots(): void
    {
        $this->open();
        $step5 = $this->fill();
        $trap = $this->send($step5, ['website' => 'http://spam.test']);
        $fast = $this->send($step5, [], '127.0.0.1', -5); // envoyé trop tôt (moins de 8 s après l'étape 1)
        $forged = $this->send($step5, ['t' => ($this->now - 3600) . '.' . str_repeat('0', 64)]);
        $missing = $this->send($step5, ['t' => '']);
        foreach (['piège' => $trap, 'délai' => $fast, 'jeton falsifié' => $forged, 'jeton absent' => $missing] as $label => $res) {
            $this->assertSame(422, $res->status(), $label);
            $this->assertStringContainsString(e(ApplyFlow::REFUSED), $res->body(), $label);
            $this->assertDoesNotMatchRegularExpression('/piège|trop rapide|délai|robot|website/i', strip_tags(explode('<form', $res->body())[0]), "$label : aucune indication du contrôle");
        }
        $this->assertSame(0, $this->rows('membership_applications'));
        $this->assertSame(303, $this->send($step5)->status(), 'un humain patient passe');
    }

    public function test_ip_throttle_returns_429(): void
    {
        $this->open();
        for ($i = 0; $i < ApplyFlow::MAX_PER_WINDOW; $i++) {
            $this->newVisitor();
            $this->assertSame(303, $this->send($this->fill(), [], $i % 2 ? '::ffff:10.9.8.7' : '10.9.8.7')->status());
        }
        $this->newVisitor();
        $res = $this->send($this->fill(), [], '::ffff:10.9.8.7'); // IPv4 mappée : même clé que 10.9.8.7
        $this->assertSame(429, $res->status());
        $this->assertSame(ApplyFlow::MAX_PER_WINDOW, $this->rows('membership_applications'));
        $this->assertSame(303, $this->send($this->fill(), [], '10.9.8.8')->status(), 'autre adresse');
        $this->newVisitor();
        $this->assertSame(303, $this->send($this->fill(), [], '2001:db8:1:2::1')->status());
        // Fenêtre de 30 minutes écoulée : la même adresse repasse.
        $this->db->query('UPDATE login_attempts SET created_at = created_at - INTERVAL 31 MINUTE WHERE succeeded = 7');
        $this->newVisitor();
        $this->assertSame(303, $this->send($this->fill(), [], '10.9.8.7')->status());
    }

    public function test_duplicate_applicant_gets_same_acknowledgement_no_oracle(): void
    {
        $this->open();
        $this->send($this->fill());
        $first = $this->req('GET', self::BASE . '/envoye')->body();
        // Homonyme : un adhérent existe déjà sous ce nom, et une demande identique vient d'être déposée.
        $this->db->insert('members', ['member_number' => 'M-TEST-1', 'last_name' => 'Kouassi', 'first_names' => 'Yao Serge', 'birth_date' => '1980-05-01', 'phone' => '0701020304', 'status' => 'actif']);
        $this->newVisitor();
        $res = $this->send($this->fill());
        $this->assertSame(303, $res->status());
        $second = $this->req('GET', self::BASE . '/envoye')->body();
        $refs = $this->db->fetchAll('SELECT reference FROM membership_applications ORDER BY id');
        $this->assertCount(2, $refs);
        $this->assertSame(str_replace($refs[0]['reference'], 'REF', $first), str_replace($refs[1]['reference'], 'REF', $second));
        $this->assertSame(1, $this->rows('members'), 'aucun membre créé');
    }

    public function test_confirmation_email_has_no_header_injection(): void
    {
        $this->open();
        $this->req('GET', self::BASE . '/etape/1');
        $this->step(1);
        $this->step(2, $this->identity());
        foreach (["yao@exemple.ci\r\nBcc: victime@exemple.ci", "yao@exemple.ci\n"] as $hostile) {
            $res = $this->step(3, $this->contact(['email' => $hostile]));
            $this->assertSame(422, $res->status(), json_encode($hostile));
            $this->assertStringContainsString('href="#f-email"', $res->body());
        }
        $this->assertSame(303, $this->step(3, $this->contact(['email' => 'Yao@Exemple.ci']))->status());
        $this->step(4);
        $this->assertSame(303, $this->send($this->req('GET', self::BASE . '/etape/5'))->status());
        $ref = (string) $this->db->fetchValue('SELECT reference FROM membership_applications');
        $this->assertCount(1, $this->mail->messages);
        $m = $this->mail->messages[0];
        $this->assertSame('yao@exemple.ci', $m['to']);
        $this->assertStringNotContainsString("\n", $m['subject']);
        $text = base64_decode(str_replace("\r\n", '', $m['body']));
        $this->assertStringContainsString($ref, $text);
        foreach (['Kouassi', 'Yao', '0701', 'Sassandra'] as $personal) {
            $this->assertStringNotContainsString($personal, $text . mb_decode_mimeheader($m['subject']));
        }
        // Sans e-mail : aucun envoi ; courrier non configuré : la demande passe quand même.
        $this->newVisitor();
        $this->send($this->fill([], ['email' => '']));
        $this->assertCount(0, $this->mail->messages);
        $this->newVisitor();
        $this->overrides['mailer'] = static fn () => throw new \InvalidArgumentException('MAIL_FROM');
        $this->forgetApp();
        $this->assertSame(303, $this->send($this->fill())->status());
        $this->assertSame(3, $this->rows('membership_applications'));
    }

    public function test_no_personal_data_in_audit_log(): void
    {
        $this->open();
        $max = (int) $this->db->fetchValue('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
        $this->send($this->fill([], [], [['identite', $this->pdf()]]), [], '10.1.2.3');
        $rows = $this->db->fetchAll('SELECT * FROM audit_logs WHERE id > ?', [$max]);
        $this->assertNotEmpty($rows);
        $all = json_encode($rows, JSON_UNESCAPED_UNICODE);
        foreach (['Kouassi', 'Yao', '0701020304', '07 01 02 03 04', 'yao@exemple.ci', '1980-05-01', 'Sassandra', '10.1.2.3'] as $personal) {
            $this->assertStringNotContainsString($personal, $all, $personal);
        }
        $this->assertSame(['application.submitted'], array_values(array_unique(array_column($rows, 'action'))));
    }

    public function test_hostile_input_is_escaped_in_recap(): void
    {
        $this->open();
        $evil = '<script>alert(1)</script>"\'><img src=x onerror=alert(2)>';
        $this->req('GET', self::BASE . '/etape/1');
        $this->step(1);
        $this->step(2, $this->identity(['birth_place' => $evil, 'nationality' => "Ivoir\u{202E}ienne\x07"]));
        $this->step(3, $this->contact());
        $this->step(4);
        $body = $this->req('GET', self::BASE . '/etape/5')->body();
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;&gt;', $body);
        $this->assertStringContainsString('<dd>Ivoirienne</dd>', $body, 'caractères de contrôle et bidi retirés');
        $this->assertStringNotContainsString("\u{202E}", $body);
    }

    public function test_works_without_javascript(): void
    {
        $this->open();
        $step5 = $this->fill([], [], [['identite', $this->pdf()]]); // toutes les étapes en POST simple, sans JavaScript
        foreach ([1, 2, 3, 4, 5] as $n) {
            $body = $this->req('GET', self::BASE . "/etape/$n")->body();
            $this->assertMatchesRegularExpression('~<form method="post" action="/adhesion-en-ligne/etape/' . $n . '"~', $body);
            $this->assertStringContainsString('<button type="submit" class="btn btn-primary" name="op" value="' . ($n === 5 ? 'submit' : 'next') . '">', $body);
            if ($n > 1) {
                $this->assertStringContainsString('name="op" value="back"', $body);
            }
            $this->assertStringNotContainsString('data-requires-js', $body);
        }
        $this->assertStringContainsString('enctype="multipart/form-data"', $this->req('GET', self::BASE . '/etape/4')->body());
        $this->assertStringContainsString('value="remove-', $this->req('GET', self::BASE . '/etape/4')->body());
        $this->assertSame(303, $this->send($step5)->status());
        $this->assertSame(1, $this->rows('membership_application_files'));
    }

    public function test_noindex_and_no_store_on_every_response(): void
    {
        $this->open();
        $responses = [$this->req('GET', self::BASE), $this->req('GET', self::BASE . '/etape/1'), $this->step(1), $this->step(2, ['last_name' => '']),
            $this->req('GET', self::BASE . '/envoye')];
        $step5 = $this->fill();
        $responses[] = $step5;
        $responses[] = $this->send($step5, ['consent' => '']);
        $responses[] = $this->send($step5);
        $responses[] = $this->req('GET', self::BASE . '/envoye');
        $responses[] = $this->send($step5);
        foreach ($responses as $i => $res) {
            $this->assertSame('no-store', $res->headers()['Cache-Control'] ?? null, "réponse $i");
            $this->assertSame('noindex, nofollow', $res->headers()['X-Robots-Tag'] ?? null, "réponse $i");
            $this->assertArrayHasKey('Content-Security-Policy', $res->headers());
            if ($res->status() === 200) {
                $this->assertStringContainsString('<meta name="robots" content="noindex">', $res->body());
            }
        }
    }

    public function test_draft_cleanup_cron_removes_abandoned_uploads(): void
    {
        $this->open();
        // Une demande envoyée (pièce rattachée) et un brouillon abandonné avec deux pièces.
        $this->send($this->fill([], [], [['identite', $this->pdf()]]));
        $attached = (int) $this->db->fetchValue('SELECT file_id FROM membership_application_files');
        $this->newVisitor();
        $this->fill([], [], [['identite', $this->pdf()], ['photo', $this->png()]]);
        $old = array_map('intval', array_column($this->db->fetchAll('SELECT file_id FROM apply_draft_files'), 'file_id'));
        $paths = array_map(fn (int $id): string => $this->uploads->path($id), $old);
        $this->assertSame(0, ApplyFlow::cleanup($this->db, $this->uploads), 'moins de 24 h : rien');
        $this->db->query('UPDATE apply_draft_files SET created_at = NOW() - INTERVAL 25 HOUR');
        // Une ligne de brouillon pointant vers une pièce déjà rattachée (incohérence) : la pièce reste.
        $this->db->insert('apply_draft_files', ['token' => str_repeat('b', 64), 'file_id' => $attached, 'type' => 'identite', 'created_at' => '2000-01-01 00:00:00']);
        $scheduler = new Scheduler($this->db);
        ApplyFlow::register($scheduler, $this->db, $this->uploads);
        $this->assertSame('ran', $scheduler->run(time())['site.cleanup_apply_drafts']);
        $this->assertSame(0, $this->rows('apply_draft_files'));
        $this->assertSame(0, $this->rows('files', 'id IN (' . implode(',', $old) . ')'));
        foreach ($paths as $p) {
            $this->assertFileDoesNotExist($p);
        }
        $this->assertFileExists($this->uploads->path($attached));
        $this->assertSame(0, ApplyFlow::cleanup($this->db, $this->uploads), 'idempotent');
        // Le visiteur revient après la purge : ses pièces ont disparu, l'envoi reste possible.
        $this->assertStringContainsString('Aucune pièce jointe.', $this->req('GET', self::BASE . '/etape/5')->body());
    }

    public function test_session_cookie_only_on_apply_routes(): void
    {
        // Cookies observables seulement via un vrai serveur (base de développement : adhésion fermée, aucune écriture).
        $port = random_int(20000, 40000);
        $env = ['APP_ENV' => 'testing'] + Env::load(dirname(__DIR__, 2) . '/.env');
        $this->assertNotSame('', (string) (new Config($env))->get('DB_NAME'));
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        try {
            $headers = static function (string $path) use ($port): string {
                for ($i = 0; $i < 50; $i++) {
                    $h = @get_headers("http://127.0.0.1:$port$path");
                    if ($h !== false) {
                        return implode("\n", $h);
                    }
                    usleep(100_000);
                }
                return '';
            };
            foreach ([self::BASE, self::BASE . '/etape/3', self::BASE . '/envoye'] as $p) {
                $h = $headers($p);
                $this->assertMatchesRegularExpression('/^Set-Cookie: mmmgci_sid=\w+;.*HttpOnly; SameSite=Lax/mi', $h, $p);
                $this->assertStringContainsString('X-Robots-Tag: noindex', $h);
            }
            foreach (['/', '/adhesion', '/adhesion-en-ligne-x', '/actualites'] as $p) {
                $this->assertStringNotContainsStringIgnoringCase('Set-Cookie', $headers($p), $p);
            }
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }
}
