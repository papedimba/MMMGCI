<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Settings;
use App\Modules\Cms\ArticleService;
use App\Modules\Cms\DocumentService;
use App\Modules\Cms\EventService;
use App\Modules\Cms\PageService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

/** L4 tâche 5 : formulaires publics (contact, information, partenariat), plan du site, robots.txt, balises SEO, versions d'assets, .htaccess. */
final class ContactSeoTest extends AdminTestCase
{
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_form_submissions', 'cms_gallery_items', 'cms_galleries', 'cms_menu_items', 'cms_page_redirects', 'cms_documents', 'cms_events', 'cms_article_tag', 'cms_articles'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query('DELETE FROM login_attempts WHERE succeeded IN (5, 6)');
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->db->query('DELETE FROM users');
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
        $this->env = ['APP_URL' => 'https://mmmgci.test'];
    }

    private function pages(): PageService
    {
        $this->app();
        return new PageService($this->db, $this->services['audit'](), $this->services['gate']());
    }

    /** @param array<string,mixed> $extra @return string slug */
    private function page(string $title, string $state = 'publie', array $extra = []): string
    {
        $p = $this->pages();
        $id = $p->create(['title' => $title, 'blocks' => [['type' => 'titre', 'text' => 'Section', 'level' => '2']]] + $extra, $this->author);
        if ($state === 'publie') {
            $p->publish($id, $this->publisher, null, (int) $p->get($id)['content_version']);
        }
        return (string) $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$id]);
    }

    private function get(string $path, array $server = []): Response
    {
        $app = $this->app();
        [$p, $qs] = explode('?', $path, 2) + [1 => ''];
        parse_str($qs, $query);
        return $app->handle(new Request('GET', $p, $query, [], [], $server + ['REMOTE_ADDR' => '127.0.0.1', 'QUERY_STRING' => $qs]));
    }

    /** @param array<string,mixed> $post */
    private function post(string $path, array $post, string $ip = '127.0.0.1', bool $token = true): Response
    {
        $app = $this->app();
        if ($token) {
            $post += ['_csrf' => $this->services['csrf']->token()];
        }
        return $app->handle(new Request('POST', $path, [], $post, [], ['REMOTE_ADDR' => $ip]));
    }

    private function validate(string $key, mixed $value): void
    {
        (new Settings($this->db))->validate('site', $key, $value, $this->publisher, 'PV test');
    }

    private function future(string $m): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($m);
    }

    public function test_sitemap_contains_only_published_content_with_absolute_urls(): void
    {
        $live = $this->page('Notre mutuelle');
        $draft = $this->page('Brouillon secret', 'brouillon');
        $this->page('Accueil du site', 'publie', ['slug' => 'accueil', 'template' => 'accueil']);
        $this->app();
        [$audit, $gate] = [$this->services['audit'](), $this->services['gate']()];
        $art = new ArticleService($this->db, $audit, $gate);
        $a = $art->create(['title' => 'Article en ligne', 'body' => '<p>x</p>'], $this->author);
        $art->transition($a, 'publie', $this->publisher, null, (int) $art->get($a)['content_version']);
        $art->create(['title' => 'Article brouillon', 'body' => '<p>x</p>'], $this->author);
        $ev = new EventService($this->db, $audit, $gate);
        $e = $ev->create(['title' => 'Évènement en ligne', 'starts_at' => $this->future('+3 days')->format('Y-m-d\TH:i')], $this->author);
        $ev->transition($e, 'publie', $this->publisher, null, (int) $ev->get($e)['content_version']);
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/' . bin2hex(random_bytes(4)) . '.pdf', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $media = $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Doc', 'alt' => null]);
        $docs = new DocumentService($this->db, $audit, $gate);
        $d = $docs->create(['title' => 'Statuts', 'category' => 'Statuts', 'document_date' => '2026-03-03', 'media_id' => (string) $media], $this->author);
        $docs->transition($d, 'publie', $this->publisher, (int) $docs->get($d)['content_version']);

        $res = $this->get('/sitemap.xml');
        $this->assertSame(200, $res->status());
        $this->assertStringStartsWith('application/xml', $res->headers()['Content-Type']);
        $this->assertArrayNotHasKey('Set-Cookie', $res->headers());
        $xml = simplexml_load_string($res->body());
        $this->assertNotFalse($xml, 'XML bien formé');
        $locs = [];
        foreach ($xml->url as $u) {
            $locs[] = (string) $u->loc;
            $this->assertMatchesRegularExpression('~^\d{4}-\d{2}-\d{2}$~', (string) $u->lastmod, (string) $u->loc);
        }
        sort($locs);
        $this->assertSame(['https://mmmgci.test/', 'https://mmmgci.test/actualites/article-en-ligne', 'https://mmmgci.test/documents',
            'https://mmmgci.test/evenements/evenement-en-ligne', "https://mmmgci.test/$live"], $locs);
        $this->assertStringNotContainsString($draft, $res->body());
        $this->assertStringNotContainsString('/accueil<', $res->body(), 'accueil = /');
        $this->assertStringNotContainsString('/media/', $res->body());

        // Sans APP_URL : chemins seuls (jamais un hôte inventé), toujours bien formé.
        $this->env = ['APP_URL' => ''];
        $this->forgetApp();
        $body = $this->get('/sitemap.xml')->body();
        $this->assertNotFalse(simplexml_load_string($body));
        $this->assertStringContainsString("<loc>/$live</loc>", $body);
        $this->assertStringNotContainsString('http', str_replace('http://www.sitemaps.org', '', $body));
    }

    public function test_robots_blocks_admin_verifier_and_apply(): void
    {
        $res = $this->get('/robots.txt');
        $this->assertSame(200, $res->status());
        $this->assertStringStartsWith('text/plain', $res->headers()['Content-Type']);
        $this->assertArrayNotHasKey('Set-Cookie', $res->headers());
        foreach (['Disallow: /admin', 'Disallow: /verifier/', 'Disallow: /adhesion-en-ligne'] as $line) {
            $this->assertStringContainsString($line . "\n", $res->body());
        }
        $this->assertStringContainsString("Sitemap: https://mmmgci.test/sitemap.xml\n", $res->body());
        $this->env = ['APP_URL' => ''];
        $this->forgetApp();
        $this->assertStringNotContainsString('Sitemap:', $this->get('/robots.txt')->body());
        // Les routes fixes ne sont jamais avalées par l'attrape-tout ; une variante reste 404.
        $this->assertSame(404, $this->get('/robots.txt/x')->status());
    }

    public function test_seo_tags_use_page_fields_and_canonical(): void
    {
        $slug = $this->page('Notre mutuelle', 'publie', ['seo_title' => 'Titre SEO "choisi"', 'seo_description' => 'Description <SEO> choisie', 'excerpt' => 'Résumé']);
        $html = $this->get("/$slug")->body();
        $this->assertStringContainsString('<title>Titre SEO &quot;choisi&quot;</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Description &lt;SEO&gt; choisie">', $html);
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"https://mmmgci.test/$slug\">", $html);
        $this->assertStringContainsString('<meta property="og:title" content="Titre SEO &quot;choisi&quot;">', $html);
        $this->assertStringContainsString('<meta property="og:description" content="Description &lt;SEO&gt; choisie">', $html);
        $this->assertStringContainsString("<meta property=\"og:url\" content=\"https://mmmgci.test/$slug\">", $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary">', $html);
        $this->assertStringContainsString('<meta property="og:locale" content="fr_FR">', $html);
        $this->assertStringNotContainsString('name="robots"', $html);

        // Variante avec paramètre : même canonique propre, même ETag (même contenu).
        $a = $this->get("/$slug");
        $b = $this->get("/$slug?x=1");
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"https://mmmgci.test/$slug\">", $b->body());
        $this->assertSame($a->headers()['ETag'], $b->headers()['ETag']);
        // ETag faible et « * » : 304.
        $this->assertSame(304, $this->get("/$slug", ['HTTP_IF_NONE_MATCH' => 'W/' . $a->headers()['ETag']])->status());
        $this->assertSame(304, $this->get("/$slug", ['HTTP_IF_NONE_MATCH' => '*'])->status());
        // mod_deflate renvoie l'ETag suffixé « -gzip » : reconnu aussi ; un autre suffixe, non.
        $this->assertSame(304, $this->get("/$slug", ['HTTP_IF_NONE_MATCH' => substr($a->headers()['ETag'], 0, -1) . '-gzip"'])->status());
        $this->assertSame(200, $this->get("/$slug", ['HTTP_IF_NONE_MATCH' => substr($a->headers()['ETag'], 0, -1) . '-br"'])->status());

        // Champ canonique de la page (adresse absolue sûre) prioritaire ; une adresse douteuse est ignorée.
        $id = (int) $this->db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$slug]);
        $this->db->query('UPDATE cms_pages SET canonical_url = ? WHERE id = ?', ['https://ailleurs.test/page', $id]);
        $this->assertStringContainsString('<link rel="canonical" href="https://ailleurs.test/page">', $this->get("/$slug")->body());
        $this->db->query('UPDATE cms_pages SET canonical_url = ? WHERE id = ?', ['javascript:alert(1)', $id]);
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"https://mmmgci.test/$slug\">", $this->get("/$slug")->body());

        // Sans APP_URL : canonique relative, pas d'og:url.
        $this->env = ['APP_URL' => ''];
        $this->forgetApp();
        $this->db->query('UPDATE cms_pages SET canonical_url = NULL WHERE id = ?', [$id]);
        $html = $this->get("/$slug")->body();
        $this->assertStringContainsString("<link rel=\"canonical\" href=\"/$slug\">", $html);
        $this->assertStringNotContainsString('og:url', $html);
    }

    public function test_noindex_on_preview_404_and_closed_apply(): void
    {
        $lost = $this->get('/page-inexistante');
        $this->assertSame(404, $lost->status());
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $lost->body());
        $this->assertStringNotContainsString('rel="canonical"', $lost->body());
        $closed = $this->get('/adhesion-en-ligne');
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $closed->body());
        $this->assertStringContainsString('noindex', $closed->headers()['X-Robots-Tag']);
        $this->assertStringNotContainsString('rel="canonical"', $closed->body());
        $this->actingAs($this->publisher);
        $id = (int) $this->db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$this->page('Brouillon', 'brouillon')]);
        $preview = $this->request('GET', "/admin/site/pages/$id/apercu");
        $this->assertSame(200, $preview->status());
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $preview->body());
        $this->assertStringContainsString('noindex', $preview->headers()['X-Robots-Tag']);
    }

    public function test_contact_form_throttle_honeypot_and_csrf(): void
    {
        $this->page('Contact', 'publie', ['slug' => 'contact']);
        $this->services['session']->forget('_last_activity');
        $form = $this->get('/contact');
        $this->assertSame(200, $form->status());
        $this->assertSame('no-store', $form->headers()['Cache-Control']);
        $this->assertNotNull($this->services['session']->get('_last_activity'), 'session ouverte : jeton CSRF');
        $this->assertMatchesRegularExpression('~<input type="text" id="f-website" name="website" value="" tabindex="-1" autocomplete="off">~', $form->body());
        $this->assertStringContainsString('name="_csrf"', $form->body());
        $this->assertStringContainsString('<h1>Contact</h1>', $form->body());

        $ok = ['name' => 'Awa', 'email' => 'awa@x.test', 'message' => 'Bonjour'];
        // Sans jeton : 419, rien d'enregistré.
        $this->assertSame(419, $this->post('/contact', $ok, '127.0.0.1', false)->status());
        // Champ piège rempli : refusé, rien d'enregistré.
        $trap = $this->post('/contact', $ok + ['website' => 'http://spam.test']);
        $this->assertSame(422, $trap->status());
        $this->assertStringContainsString('Envoi impossible', $trap->body());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_form_submissions'));
        // Champs invalides : erreurs claires, valeurs reprises.
        $bad = $this->post('/contact', ['name' => 'Awa', 'email' => 'pas-un-email', 'message' => '']);
        $this->assertSame(422, $bad->status());
        $this->assertStringContainsString('Adresse e-mail invalide.', $bad->body());
        $this->assertStringContainsString('Message : champ obligatoire.', $bad->body());
        $this->assertStringContainsString('value="pas-un-email"', $bad->body());
        // Limite par adresse IP (5 tentatives par 10 minutes, comptées par FormInbox).
        $this->db->query('DELETE FROM login_attempts WHERE succeeded = 5');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(303, $this->post('/contact', $ok, '10.0.0.9')->status(), "envoi $i");
        }
        $limited = $this->post('/contact', $ok, '10.0.0.9');
        $this->assertSame(422, $limited->status());
        $this->assertStringContainsString('Trop d&#039;envois', $limited->body());
        $this->assertSame(5, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_form_submissions'));

        // Page hôte non publiée : 404 sans session ; POST sur une adresse publique sans route POST : 405.
        $this->db->query("UPDATE cms_pages SET status = 'brouillon', published_blocks = NULL WHERE slug = 'contact'");
        $this->forgetApp();
        $this->app();
        $this->services['session']->forget('_last_activity');
        $this->assertSame(404, $this->get('/contact')->status());
        $this->assertSame(404, $this->get('/partenariat')->status());
        $this->assertNull($this->services['session']->get('_last_activity'), 'aucune session sur une 404');
        $this->assertSame(405, $this->post('/une-page', [], '127.0.0.1', false)->status());
    }

    public function test_contact_submission_lands_in_inbox_and_notifies(): void
    {
        $this->page('Contact', 'publie', ['slug' => 'contact']);
        $this->page('Partenariats', 'publie', ['slug' => 'partenaires']);
        $this->validate('contact_email', 'secretariat@mutuelle.test');

        $sent = $this->post('/contact', ['name' => 'Awa Koné', 'email' => 'awa@x.test', 'subject' => 'Cotisation', 'message' => 'Question précise']);
        $this->assertSame(303, $sent->status(), 'PRG : un rechargement ne renvoie pas le formulaire');
        $this->assertSame('/contact?envoye=1', $sent->headers()['Location']);
        $this->assertSame('', $sent->body());
        $done = $this->get('/contact?envoye=1');
        $this->assertSame(200, $done->status());
        $this->assertSame('no-store', $done->headers()['Cache-Control']);
        $this->assertStringContainsString('<h1>Message envoyé</h1>', $done->body());
        $this->assertStringNotContainsString('<form', $done->body());
        $again = $this->get('/contact?envoye=1'); // rechargement de l'accusé : aucun nouvel envoi, et l'accusé ne se rejoue pas
        $this->assertStringNotContainsString('<h1>Message envoyé</h1>', $again->body(), 'accusé une seule fois, juste après un vrai envoi');
        $this->assertStringContainsString('<form', $again->body());
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_form_submissions'));
        foreach (['Awa Koné', 'awa@x.test', 'Question précise'] as $pii) {
            $this->assertStringNotContainsString($pii, $done->body(), 'accusé sans donnée personnelle');
        }
        $info = $this->get('/contact?demande=information');
        $this->assertStringContainsString('name="demande" value="information"', $info->body());
        $this->assertStringNotContainsString('name="subject"', $info->body());
        $sent = $this->post('/contact', ['demande' => 'information', 'name' => 'Ali', 'email' => 'ali@x.test', 'message' => 'Horaires ?']);
        $this->assertSame([303, '/contact?envoye=1'], [$sent->status(), $sent->headers()['Location']]);
        $this->assertStringContainsString('<h1>Message envoyé</h1>', $this->get('/contact?envoye=1')->body());
        $part = $this->get('/partenariat');
        $this->assertSame(200, $part->status());
        $this->assertStringContainsString('name="organisation"', $part->body());
        $sent = $this->post('/partenariat', ['organisation' => 'Armement X', 'name' => 'Ali', 'email' => 'ali@x.test', 'message' => 'Convention']);
        $this->assertSame([303, '/partenariat?envoye=1'], [$sent->status(), $sent->headers()['Location']]);
        $ack = $this->get('/partenariat?envoye=1')->body();
        $this->assertStringContainsString('<h1>Message envoyé</h1>', $ack);
        foreach (['Armement X', 'ali@x.test'] as $pii) {
            $this->assertStringNotContainsString($pii, $ack);
        }

        $rows = $this->db->fetchAll('SELECT f.form_key, s.payload FROM cms_form_submissions s JOIN cms_forms f ON f.id = s.form_id ORDER BY s.id');
        $this->assertSame(['contact', 'information', 'partenariat'], array_column($rows, 'form_key'));
        $this->assertSame('Question précise', json_decode($rows[0]['payload'], true)['message']);
        $this->assertCount(3, $this->mail->messages);
        $this->assertSame('secretariat@mutuelle.test', $this->mail->messages[0]['to']);
        $raw = implode("\n", array_map(static fn (array $m): string => $m['subject'] . $m['body'] . (string) base64_decode(preg_replace('~\s+~', '', $m['body']) ?? '', true), $this->mail->messages));
        foreach (['Awa', 'awa@x.test', 'Question'] as $pii) {
            $this->assertStringNotContainsString($pii, $raw, 'e-mail sans donnée du visiteur');
        }
        $audit = json_encode($this->db->fetchAll("SELECT * FROM audit_logs WHERE action = 'cms.form_received'"));
        $this->assertStringNotContainsString('awa@x.test', (string) $audit);
    }

    public function test_unvalidated_contact_details_are_not_shown(): void
    {
        $this->page('Contact', 'publie', ['slug' => 'contact']);
        $this->db->query("UPDATE settings SET value = ? WHERE `group` = 'site' AND `key` = 'contact_phone'", [json_encode('+225 01 02 03 04')]); // saisi, non validé
        $html = $this->get('/contact')->body();
        $this->assertStringNotContainsString('01 02 03 04', $html);
        $this->assertStringNotContainsString('Nos coordonnées', $html);
        $this->validate('contact_phone', '+225 07 08 09 10');
        $html = $this->get('/contact')->body();
        $this->assertStringContainsString('Nos coordonnées', $html);
        $this->assertStringContainsString('href="tel:+22507080910"', $html);
    }

    public function test_form_session_only_on_exact_form_routes(): void
    {
        $this->page('Contact', 'publie', ['slug' => 'contact']);
        $this->page('Partenariats', 'publie', ['slug' => 'partenaires']);
        foreach (['/contact/foo' => 404, '/partenariat/x' => 404, '/contact/' => 301, '/partenariat/' => 301] as $path => $status) {
            $this->forgetApp();
            $this->app();
            $res = $this->get($path);
            $this->assertSame($status, $res->status(), $path);
            $this->assertNull($this->services['session']->get('_last_activity'), "$path : aucune session");
        }
        foreach (['/contact', '/partenariat'] as $path) {
            $this->forgetApp();
            $this->app();
            $res = $this->get($path);
            $this->assertSame(200, $res->status(), $path);
            $this->assertNotNull($this->services['session']->get('_last_activity'), "$path : session");
            $this->assertStringContainsString('name="_csrf" value="' . $this->services['csrf']->token() . '"', $res->body(), $path);
        }
    }

    public function test_media_resolvers_drop_unsafe_paths(): void
    {
        $file = fn (string $path, string $mime): int => $this->db->insert('files', ['disk' => 'public', 'path' => $path, 'original_name' => 'x', 'mime' => $mime, 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        foreach (['2026/11/bon.pdf' => 'Document sûr', '../../.env' => 'Document piégé', '2026/11/a.b.pdf' => 'Double point'] as $path => $title) {
            $this->db->insert('cms_documents', ['title' => $title, 'category' => 'Statuts', 'document_date' => '2026-03-03', 'file_id' => $file($path, 'application/pdf'), 'status' => 'publie', 'published_at' => '2026-03-03 10:00:00']);
        }
        $html = $this->get('/documents')->body();
        $this->assertStringContainsString('href="/media/2026/11/bon.pdf"', $html);
        $this->assertStringNotContainsString('Document piégé', $html);
        $this->assertStringNotContainsString('Double point', $html);
        $album = $this->db->insert('cms_galleries', ['title' => 'Album', 'slug' => 'album', 'status' => 'publie', 'published_at' => '2026-03-03 10:00:00']);
        foreach (['2026/11/quai.png', '../secret.png'] as $i => $path) {
            $media = $this->db->insert('cms_media', ['file_id' => $file($path, 'image/png'), 'title' => 'Image', 'alt' => "Image $i"]);
            $this->db->insert('cms_gallery_items', ['gallery_id' => $album, 'media_id' => $media, 'sort_order' => $i]);
        }
        $html = $this->get('/galeries/album')->body();
        $this->assertStringContainsString('src="/media/2026/11/quai.png"', $html);
        $this->assertStringNotContainsString('secret', $html);
    }

    public function test_asset_url_adds_content_hash(): void
    {
        $file = dirname(__DIR__, 2) . '/public/assets/css/public.css';
        $this->assertSame('/assets/css/public.css?v=' . substr(hash_file('sha256', $file), 0, 12), asset_url('/assets/css/public.css'));
        $this->assertSame('/assets/absent.css', asset_url('/assets/absent.css'), 'fichier absent : adresse inchangée');
        $this->assertSame('/assets/../.env', asset_url('/assets/../.env'), 'jamais hors de /assets');
        $html = $this->get('/page-inexistante')->body();
        $this->assertStringContainsString('<link rel="stylesheet" href="' . asset_url('/assets/css/public.css') . '">', $html);
        $this->assertStringContainsString('<script src="' . asset_url('/assets/js/site.js') . '" defer></script>', $html);
    }

    public function test_htaccess_enables_compression_and_asset_caching(): void
    {
        $h = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
        $this->assertMatchesRegularExpression('~<IfModule mod_deflate\.c>.*AddOutputFilterByType DEFLATE[^\n]*text/html[^\n]*text/css[^\n]*(application|text)/javascript[^\n]*image/svg\+xml~s', $h);
        $this->assertMatchesRegularExpression('~v=\[0-9a-f\]\+.*max-age=31536000, immutable~s', $h, 'cache long seulement pour les assets versionnés');
        $this->assertStringContainsString('no-cache', $h, 'assets non versionnés : revalidés');
        $this->assertStringContainsString('X-Content-Type-Options "nosniff"', $h);
        $this->assertStringNotContainsString('Content-Security-Policy', $h, 'la CSP reste posée par PHP (SecurityHeaders)');
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $h);
    }
}
