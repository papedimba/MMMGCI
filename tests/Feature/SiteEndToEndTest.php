<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Scheduler;
use App\Core\Settings;
use App\Core\Uploads;
use App\Modules\Cms\CmsScheduler;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Site\ContentSeed;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

/**
 * Parcours de bout en bout du site public (L4, tâche 6) : semis en brouillon → 404 public → publication → page, menu et plan du site →
 * adhésion fermée → deux paramètres validés → demande en cinq étapes avec pièce → demande « En ligne » dans l'administration →
 * message de contact dans la boîte → page programmée publiée par la tâche cron.
 */
final class SiteEndToEndTest extends AdminTestCase
{
    private string $root;
    private int $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/mmmgci-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/private', 0750, true);
        mkdir($this->root . '/public', 0750, true);
        $this->now = time();
        $this->overrides['uploads'] = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 5 * 1048576, true);
        $this->overrides['clock'] = fn (): int => $this->now;
        foreach (['apply_draft_files', 'membership_application_files', 'membership_applications', 'cms_form_submissions', 'cms_menu_items', 'cms_page_redirects',
            'cms_article_tag', 'cms_articles', 'cms_events', 'cms_documents'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('DELETE FROM login_attempts WHERE succeeded IN (5, 6, 7, 8, 9)');
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->db->query('DELETE FROM users');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string,mixed> $post @param array<string,mixed> $files */
    private function call(string $method, string $path, array $post = [], array $files = []): Response
    {
        $app = $this->app();
        [$p, $qs] = explode('?', $path, 2) + [1 => ''];
        parse_str($qs, $query);
        if ($method !== 'GET') {
            $post += ['_csrf' => $this->services['csrf']->token()];
        }
        return $app->handle(new Request($method, $p, $query, $post, $files, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'PHPUnit', 'QUERY_STRING' => $qs]));
    }

    public function test_full_public_site_journey(): void
    {
        $admin = $this->createUser('admin@x.test', ['admin_general']);
        $publisher = $this->createUser('com@x.test', ['resp_communication']);
        $validator = $this->createUser('pca@x.test', ['pca', 'gest_adherents']);
        $this->app();
        $audit = $this->services['audit']();
        $gate = $this->services['gate']();
        $pages = new PageService($this->db, $audit, $gate);

        // 1. Semis : tout en brouillon, rien de public (page, menu, plan du site).
        (new ContentSeed($this->db, $pages, new MenuService($this->db, $audit, $gate, $pages), $gate))->run($admin);
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM cms_pages WHERE status <> 'brouillon'"));
        $this->assertSame(404, $this->call('GET', '/missions')->status(), 'brouillon semé : jamais public');
        $this->assertStringContainsString('Le site de la Mutuelle est en cours de construction', $this->call('GET', '/')->body());
        $this->assertStringNotContainsString('/missions', $this->call('GET', '/sitemap.xml')->body());

        // 2. Le publicateur publie : page servie, présente au menu et au plan du site.
        $ids = array_column($this->db->fetchAll('SELECT id, slug FROM cms_pages'), 'id', 'slug');
        foreach (['accueil', 'la-mutuelle', 'missions', 'adhesion', 'contact'] as $slug) {
            $pages->publish((int) $ids[$slug], $publisher, null, (int) $pages->get((int) $ids[$slug])['content_version']);
        }
        $page = $this->call('GET', '/missions');
        $this->assertSame(200, $page->status());
        $this->assertStringContainsString('Renforcer la solidarité entre les marins marchands et gens de mer.', $page->body());
        $this->assertSame(1, preg_match('~<nav[^>]+aria-label="Menu principal".*?</nav>~s', $page->body(), $nav), 'menu principal présent');
        $this->assertStringContainsString('href="/missions"', $nav[0], 'lien DANS le menu principal');
        $this->assertStringNotContainsString('href="/emploi"', $nav[0], 'page restée en brouillon : absente du menu');
        $this->assertStringContainsString('/missions</loc>', $this->call('GET', '/sitemap.xml')->body());
        $this->assertStringNotContainsString('/emploi</loc>', $this->call('GET', '/sitemap.xml')->body());

        // 3. Adhésion en ligne fermée : page honnête, rien n'est écrit ; le bouton de l'accueil mène à la page d'information.
        $closed = $this->call('GET', '/adhesion-en-ligne/etape/1');
        $this->assertSame(200, $closed->status());
        $this->assertStringNotContainsString('<form', $closed->body());
        $this->assertStringContainsString('href="/adhesion"><span>Comment adhérer</span>', $this->call('GET', '/')->body());

        // 4. Les deux paramètres validés par les instances ouvrent l'adhésion.
        $settings = new Settings($this->db);
        $settings->validate('site', 'adhesion_open', true, $validator, 'PV test');
        $this->assertSame(200, $this->call('GET', '/adhesion-en-ligne/etape/1')->status());
        $this->assertStringNotContainsString('name="category_id"', $this->call('GET', '/adhesion-en-ligne/etape/1')->body(), 'ouverture seule : encore fermée');
        $settings->validate('site', 'consent_text', 'Texte de consentement fourni par la Mutuelle (test).', $validator, 'PV test');
        $this->assertStringContainsString('href="/adhesion-en-ligne"><span>Demander mon adhésion</span>', $this->call('GET', '/')->body());

        // 5. Demande en cinq étapes avec une pièce.
        $zone = $this->db->insert('zones', ['name' => 'Port E2E', 'type' => 'port']);
        $profession = $this->db->insert('maritime_professions', ['name' => 'Matelot E2E ' . bin2hex(random_bytes(3))]);
        $category = (int) $this->db->fetchValue('SELECT id FROM member_categories WHERE is_active = 1 ORDER BY id LIMIT 1');
        $this->assertStringContainsString('name="category_id"', $this->call('GET', '/adhesion-en-ligne/etape/1')->body());
        $this->assertSame(303, $this->call('POST', '/adhesion-en-ligne/etape/1', ['op' => 'next', 'category_id' => (string) $category])->status());
        $this->assertSame(303, $this->call('POST', '/adhesion-en-ligne/etape/2', ['op' => 'next', 'last_name' => 'Kouadio', 'first_names' => 'Ama', 'sex' => 'Féminin',
            'birth_date' => '1985-03-02', 'birth_place' => 'San-Pédro', 'nationality' => 'Ivoirienne'])->status());
        $this->assertSame(303, $this->call('POST', '/adhesion-en-ligne/etape/3', ['op' => 'next', 'phone' => '07 05 06 07 08', 'email' => 'ama@exemple.ci',
            'zone_id' => (string) $zone, 'profession_id' => (string) $profession])->status());
        $tmp = $this->root . '/piece.tmp';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $piece = ['name' => 'identite.pdf', 'tmp_name' => $tmp, 'size' => (int) filesize($tmp), 'error' => UPLOAD_ERR_OK, 'type' => 'application/pdf'];
        $this->assertSame(303, $this->call('POST', '/adhesion-en-ligne/etape/4', ['op' => 'upload', 'type' => 'identite'], ['piece' => $piece])->status());
        $this->assertSame(303, $this->call('POST', '/adhesion-en-ligne/etape/4', ['op' => 'next'])->status());
        $step5 = $this->call('GET', '/adhesion-en-ligne/etape/5');
        $this->assertSame(200, $step5->status());
        preg_match('/name="draft" value="([a-f0-9]{64})"/', $step5->body(), $d);
        preg_match('/name="t" value="([0-9.a-f]+)"/', $step5->body(), $t);
        $this->now += 30; // délai minimal de saisie
        $sent = $this->call('POST', '/adhesion-en-ligne/etape/5', ['op' => 'submit', 'consent' => '1', 'draft' => $d[1] ?? '', 't' => $t[1] ?? '', 'website' => '']);
        $this->assertSame([303, '/adhesion-en-ligne/envoye'], [$sent->status(), $sent->headers()['Location'] ?? null]);
        $application = $this->db->fetch('SELECT * FROM membership_applications');
        $this->assertSame('en_ligne', $application['source']);
        $this->assertNull($application['member_id'], 'une demande, jamais un membre');
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM membership_application_files WHERE application_id = ?', [$application['id']]));
        $this->assertStringContainsString((string) $application['reference'], $this->call('GET', '/adhesion-en-ligne/envoye')->body());

        // 6. La demande apparaît dans l'administration avec sa source « En ligne ».
        $this->forgetApp();
        $this->actingAs($validator);
        $list = $this->call('GET', '/admin/adherents/demandes');
        $this->assertSame(200, $list->status());
        $this->assertStringContainsString((string) $application['reference'], $list->body());
        $this->assertStringContainsString('En ligne', $list->body());

        // 7. Message de contact : reçu dans la boîte, accusé juste après l'envoi.
        $this->forgetApp(); // nouveau visiteur
        $this->assertStringContainsString('name="message"', $this->call('GET', '/contact')->body());
        $res = $this->call('POST', '/contact', ['name' => 'Visiteur E2E', 'email' => 'visiteur@exemple.ci', 'subject' => 'Question', 'message' => 'Bonjour', 'website' => '']);
        $this->assertSame([303, '/contact?envoye=1'], [$res->status(), $res->headers()['Location'] ?? null]);
        $this->assertStringContainsString('<h1>Message envoyé</h1>', $this->call('GET', '/contact?envoye=1')->body());
        $ref = (string) $this->db->fetchValue("SELECT s.reference FROM cms_form_submissions s JOIN cms_forms f ON f.id = s.form_id WHERE f.form_key = 'contact'");
        $this->assertNotSame('', $ref);
        $this->forgetApp();
        $this->actingAs($publisher);
        $inbox = $this->call('GET', '/admin/site/formulaires');
        $this->assertSame(200, $inbox->status());
        $this->assertStringContainsString($ref, $inbox->body(), 'message visible dans la boîte de réception');

        // 8. Page programmée : publiée par la tâche cron (même enregistrement que bin/cron.php, câblage vérifié par test_cron_wires_site_tasks) une fois l'échéance passée.
        $at = new DateTimeImmutable('+1 day', new DateTimeZone('Africa/Abidjan'));
        $pages->publish((int) $ids['emploi'], $publisher, $at, (int) $pages->get((int) $ids['emploi'])['content_version']);
        $this->assertSame(404, $this->call('GET', '/emploi')->status(), 'programmée : pas encore publique');
        $this->db->query("UPDATE cms_pages SET published_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?", [$ids['emploi']]); // l'échéance passe
        $scheduler = new Scheduler($this->db);
        CmsScheduler::register($scheduler, $this->db, $audit);
        $this->assertSame('ran', $scheduler->run(time())['cms.publish_scheduled']);
        $this->assertSame(200, $this->call('GET', '/emploi')->status());
        $this->assertStringContainsString('/emploi</loc>', $this->call('GET', '/sitemap.xml')->body());
    }

    /** Balayage des routes publiques : en-têtes de sécurité partout, session seulement sur les formulaires (contact en ligne, adhésion ouverte), 405 sinon. */
    public function test_public_route_sweep_headers_sessions_and_methods(): void
    {
        $admin = $this->createUser('admin@x.test', ['admin_general']);
        $publisher = $this->createUser('com@x.test', ['resp_communication']);
        $this->app();
        $pages = new PageService($this->db, $this->services['audit'](), $this->services['gate']());
        (new ContentSeed($this->db, $pages, new MenuService($this->db, $this->services['audit'](), $this->services['gate'](), $pages), $this->services['gate']()))->run($admin);
        foreach ($this->db->fetchAll('SELECT id FROM cms_pages') as $r) {
            $pages->publish((int) $r['id'], $publisher, null, (int) $pages->get((int) $r['id'])['content_version']);
        }
        $session = fn (): bool => $this->services['session']->get('_last_activity') !== null;
        $public = ['/', '/missions', '/adhesion', '/actualites', '/evenements', '/documents', '/galeries', '/actualites/inconnue', '/une-page-inconnue', '/Missions', '/missions/',
            '/sitemap.xml', '/robots.txt', '/sante', '/verifier/jeton-quelconque', '/adhesion-en-ligne', '/adhesion-en-ligne/etape/1', '/partenariat/', '/contact/'];
        foreach ($public as $path) {
            $this->forgetApp();
            $res = $this->call('GET', $path);
            $this->assertNotSame(500, $res->status(), $path);
            $this->assertArrayHasKey('Content-Security-Policy', $res->headers(), $path);
            $this->assertStringContainsString("script-src 'self'", $res->headers()['Content-Security-Policy'], $path);
            $this->assertSame('nosniff', $res->headers()['X-Content-Type-Options'] ?? null, $path);
            $this->assertArrayNotHasKey('Set-Cookie', $res->headers(), $path);
            $this->assertFalse($session(), "$path : aucune session (adhésion fermée)");
            // POST sur une adresse GET seule : VerifyCsrf laisse passer une route absente, le routeur répond 405 (jamais 419). Les routes de formulaire acceptent POST.
            $getOnly = !str_starts_with($path, '/adhesion-en-ligne') && !in_array($path, ['/contact/', '/partenariat/'], true);
            foreach ($getOnly ? ['POST', 'PUT', 'DELETE', 'PATCH'] : ['PUT', 'DELETE', 'PATCH'] as $m) {
                $res = $m === 'POST' ? $this->app()->handle(new Request('POST', (string) parse_url($path, PHP_URL_PATH), [], [], [], ['REMOTE_ADDR' => '127.0.0.1'])) : $this->call($m, $path); // POST sans jeton
                $this->assertSame(405, $res->status(), "$m $path");
                $this->assertStringContainsString('<body class="public">', $res->body(), "$m $path : 405 dans le gabarit public");
            }
        }
        // Formulaire de contact (page hôte en ligne) : session, donc jeton CSRF ; adhésion ouverte : idem.
        foreach (['/contact', '/partenariat'] as $path) {
            $this->forgetApp();
            $this->assertSame(200, $this->call('GET', $path)->status(), $path);
            $this->assertTrue($session(), $path);
        }
        $settings = new Settings($this->db);
        $settings->validate('site', 'adhesion_open', true, $admin, 'PV test');
        $settings->validate('site', 'consent_text', 'Consentement (test).', $admin, 'PV test');
        $this->forgetApp();
        $this->call('GET', '/adhesion-en-ligne/etape/1');
        $this->assertTrue($session(), 'adhésion ouverte : session');
    }

    /** Les tâches du site sont bien câblées dans l'unique entrée cron (une ligne retirée : plus de purge des pièces de brouillon, plafond de 200 Mo atteint en silence). */
    public function test_cron_wires_site_tasks(): void
    {
        $cron = (string) file_get_contents(dirname(__DIR__, 2) . '/bin/cron.php');
        foreach (['App\\Modules\\Site\\ApplyFlow::register(', 'App\\Modules\\Cms\\CmsScheduler::register('] as $call) {
            $this->assertMatchesRegularExpression('~^\s*' . preg_quote($call, '~') . '~m', $cron, "$call : ligne active (non commentée) de bin/cron.php");
        }
        // Et l'enregistrement crée bien la tâche attendue.
        $scheduler = new Scheduler($this->db);
        \App\Modules\Site\ApplyFlow::register($scheduler, $this->db, new Uploads($this->db, $this->root . '/private', $this->root . '/public', 1048576, true));
        $this->assertArrayHasKey('site.cleanup_apply_drafts', $scheduler->run(time()));
    }

    /** POST sans jeton sur une vraie route publique (formulaire en ligne) : 419 dans le gabarit public, retour au début du formulaire ; l'administration garde sa page. */
    public function test_public_419_uses_public_layout_with_a_way_back(): void
    {
        $admin = $this->createUser('admin@x.test', ['admin_general']);
        $publisher = $this->createUser('com@x.test', ['resp_communication']);
        $this->app();
        $pages = new PageService($this->db, $this->services['audit'](), $this->services['gate']());
        $id = $pages->create(['title' => 'Contact', 'slug' => 'contact', 'blocks' => [['type' => 'titre', 'text' => 'Écrire', 'level' => '2']]], $admin);
        $pages->publish($id, $publisher, null, (int) $pages->get($id)['content_version']);
        $settings = new Settings($this->db);
        $settings->validate('site', 'adhesion_open', true, $admin, 'PV test');
        $settings->validate('site', 'consent_text', 'Consentement (test).', $admin, 'PV test');
        foreach (['/contact' => '/contact', '/adhesion-en-ligne/etape/4' => '/adhesion-en-ligne', '/adhesion-en-ligne' => '/adhesion-en-ligne'] as $path => $back) {
            $this->forgetApp();
            $res = $this->app()->handle(new Request('POST', $path, [], ['_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
            $this->assertSame(419, $res->status(), $path);
            $this->assertStringContainsString('<body class="public">', $res->body(), $path);
            $this->assertStringNotContainsString('auth-page', $res->body(), $path);
            $this->assertStringContainsString('<h1>Session expirée</h1>', $res->body(), $path);
            $this->assertStringContainsString('href="' . $back . '"', $res->body(), $path);
            $this->assertStringNotContainsString('href="/admin"><', $res->body(), "$path : jamais de lien vers l'administration");
            $this->assertSame('no-store', $res->headers()['Cache-Control']);
        }
        // Avec un jeton valide, la même route passe le contrôle CSRF (le contrôleur répond, pas 419).
        $this->forgetApp();
        $this->call('GET', '/contact');
        $this->assertNotSame(419, $this->call('POST', '/contact', ['name' => 'X', 'email' => 'x@exemple.ci', 'message' => 'Bonjour', 'website' => ''])->status());
        $admin419 = $this->app()->handle(new Request('POST', '/admin/connexion', [], ['_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertSame(419, $admin419->status());
        $this->assertStringContainsString('auth-page', $admin419->body(), 'administration : page inchangée');
    }

    /** Les listes fixes (/actualites, /evenements, /documents, /galeries) masqueraient une page de même adresse : refus clair, jamais au plan du site. */
    public function test_list_route_slugs_are_reserved_for_pages(): void
    {
        $admin = $this->createUser('admin@x.test', ['admin_general']);
        $this->app();
        $pages = new PageService($this->db, $this->services['audit'](), $this->services['gate']());
        foreach (\App\Modules\Cms\Slugs::LIST_ROUTES as $slug) {
            try {
                $pages->create(['title' => 'Liste', 'slug' => $slug, 'blocks' => [['type' => 'titre', 'text' => 'T', 'level' => '2']]], $admin);
                $this->fail("$slug accepté");
            } catch (\DomainException $e) {
                $this->assertStringContainsString("c'est celle de la liste /$slug du site", $e->getMessage());
            }
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
        $auto = $pages->create(['title' => 'Actualités', 'blocks' => [['type' => 'titre', 'text' => 'T', 'level' => '2']]], $admin);
        $this->assertSame('actualites-2', $pages->get($auto)['slug'], 'adresse proposée depuis le titre : jamais la liste');
        $this->db->query('DELETE FROM cms_pages WHERE id = ?', [$auto]);

        // Écran d'administration : message lisible, rien d'enregistré.
        $this->actingAs($admin);
        $res = $this->call('POST', '/admin/site/pages/nouvelle', ['title' => 'Nos documents', 'slug' => 'documents', 'template' => 'standard', '_form_complete' => '1',
            'blocs' => [['type' => 'titre', 'text' => 'T', 'level' => '2']]]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('c&#039;est celle de la liste /documents du site', $res->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));

        // Page antérieure à la réservation (créée en base) et publiée : absente du plan du site.
        $id = $pages->create(['title' => 'Ancienne', 'slug' => 'ancienne', 'blocks' => [['type' => 'titre', 'text' => 'T', 'level' => '2']]], $admin);
        $pages->publish($id, $admin, null, (int) $pages->get($id)['content_version']);
        $this->db->query("UPDATE cms_pages SET slug = 'galeries' WHERE id = ?", [$id]);
        $this->forgetApp();
        $map = $this->call('GET', '/sitemap.xml')->body();
        $this->assertStringNotContainsString('/galeries</loc>', $map);
        $this->assertStringNotContainsString('/ancienne</loc>', $map);
    }

    /** Purge des pièces de brouillon : un fichier non supprimable n'arrête pas la tâche (avertissements convertis en exceptions comme en production). */
    public function test_draft_purge_survives_an_undeletable_file(): void
    {
        $uploads = new Uploads($this->db, $this->root . '/private', $this->root . '/public', 1048576, true);
        $ids = [];
        foreach (['bloque', 'libre'] as $i => $name) {
            file_put_contents($this->root . "/private/$name.pdf", '%PDF-1.4');
            $ids[$name] = $this->db->insert('files', ['disk' => 'private', 'path' => "$name.pdf", 'original_name' => 'identite.pdf', 'mime' => 'application/pdf', 'size' => 8, 'sha256' => str_repeat((string) $i, 64)]);
            $this->db->insert('apply_draft_files', ['token' => str_repeat('c', 64), 'file_id' => $ids[$name], 'type' => 'identite', 'created_at' => '2000-01-01 00:00:00']);
        }
        $stuck = $this->root . '/private/bloque.pdf';
        exec('chattr +i ' . escapeshellarg($stuck) . ' 2>/dev/null', $o, $code);
        if ($code !== 0) {
            $this->markTestSkipped('chattr indisponible : impossible de simuler un fichier non supprimable');
        }
        set_error_handler(static function (int $no, string $msg): bool { // comme App\Core\ErrorHandler : respecte « @ », sinon exception
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($msg, 0, $no);
        });
        $log = ini_set('error_log', $this->root . '/purge.log');
        try {
            $this->assertSame(2, \App\Modules\Site\ApplyFlow::cleanup($this->db, $uploads), 'les deux pièces sont traitées');
        } finally {
            restore_error_handler();
            ini_set('error_log', (string) $log);
            exec('chattr -i ' . escapeshellarg($stuck));
        }
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM apply_draft_files'));
        $this->assertFileDoesNotExist($this->root . '/private/libre.pdf', 'la pièce suivante est bien supprimée');
        $this->assertStringContainsString('fichier non supprimable (n° ' . $ids['bloque'] . ')', (string) file_get_contents($this->root . '/purge.log'));
    }
}
