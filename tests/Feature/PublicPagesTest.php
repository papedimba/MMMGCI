<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Cms\ArticleService;
use App\Modules\Cms\DocumentService;
use App\Modules\Cms\EventService;
use App\Modules\Cms\GalleryService;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Cms\SiteSettings;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminTestCase;

/** Site public (L4, tâche 1) : résolution d'adresse, normalisation, rendu des blocs, en-têtes, sans session. */
final class PublicPagesTest extends AdminTestCase
{
    private const X = '<script>alert(1)</script>"\'><img src=x onerror=alert(1)>';
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_gallery_items', 'cms_galleries', 'cms_documents', 'cms_events', 'cms_article_tag', 'cms_articles'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->db->query('DELETE FROM users');
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    private function pages(): PageService
    {
        $this->app();
        return new PageService($this->db, $this->services['audit'](), $this->services['gate']());
    }

    private function future(string $m): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($m);
    }

    /** @param list<array<string,mixed>> $blocks @return array{0:int,1:string} */
    private function page(string $title, string $state = 'publie', array $blocks = [['type' => 'titre', 'text' => 'Section', 'level' => '2']], string $slug = ''): array
    {
        $p = $this->pages();
        $id = $p->create(['title' => $title, 'slug' => $slug, 'blocks' => $blocks], $this->author);
        $v = fn (): int => (int) $p->get($id)['content_version'];
        match ($state) {
            'publie' => $p->publish($id, $this->publisher, null, $v()),
            'archive' => [$p->publish($id, $this->publisher, null, $v()), $p->archive($id, $this->publisher)],
            'futur' => $p->publish($id, $this->publisher, $this->future('+2 days'), $v()),
            default => null,
        };
        return [$id, (string) $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$id])];
    }

    /** Page publiée dont les blocs sont posés tels quels en base (contenu altéré : le rendu ne doit pas faire confiance). @param list<mixed> $blocks */
    private function rawPage(array $blocks): string
    {
        [$id, $slug] = $this->page('Page brute');
        $this->db->query('UPDATE cms_pages SET blocks = ? WHERE id = ?', [json_encode($blocks), $id]);
        return $slug;
    }

    private function get(string $path, array $server = []): Response
    {
        return $this->app()->handle(new Request('GET', $path, [], [], [], $server + ['REMOTE_ADDR' => '127.0.0.1']));
    }

    private function media(string $disk = 'public', string $alt = 'Quai du port'): int
    {
        $file = $this->db->insert('files', ['disk' => $disk, 'path' => '2026/11/' . bin2hex(random_bytes(4)) . '.png', 'original_name' => 'x', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        return $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Image', 'alt' => $alt]);
    }

    private function services(): array
    {
        $this->app();
        return [$this->services['audit'](), $this->services['gate']()];
    }

    public function test_published_page_is_served_with_cache_headers_and_no_cookie(): void
    {
        [, $slug] = $this->page('Notre mutuelle');
        $this->services['session']->forget('_last_activity');
        $res = $this->get("/$slug");
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('<h1>Notre mutuelle</h1>', $res->body());
        $this->assertSame('public, max-age=300', $res->headers()['Cache-Control']);
        $this->assertMatchesRegularExpression('~^"[0-9a-f]{32}"$~', $res->headers()['ETag']);
        $this->assertArrayNotHasKey('Set-Cookie', $res->headers());
        $this->assertArrayHasKey('Content-Security-Policy', $res->headers(), 'SecurityHeaders inchangé');
        $this->assertNull($this->services['session']->get('_last_activity'), 'aucune session ouverte');
        foreach (['/', '/actualites', '/evenements', '/documents', '/galeries', '/inconnue'] as $p) {
            $this->get($p);
            $this->assertNull($this->services['session']->get('_last_activity'), $p);
        }
        // L'administration a toujours sa session.
        $this->assertSame(200, $this->request('GET', '/admin/connexion')->status());
        $this->assertNotNull($this->services['session']->get('_last_activity'));

        // ETag : même contenu → 304 sans corps ; autre étiquette → 200.
        $etag = $res->headers()['ETag'];
        $again = $this->get("/$slug", ['HTTP_IF_NONE_MATCH' => $etag]);
        $this->assertSame(304, $again->status());
        $this->assertSame('', $again->body());
        $this->assertSame($etag, $again->headers()['ETag']);
        $this->assertSame(200, $this->get("/$slug", ['HTTP_IF_NONE_MATCH' => '"autre"'])->status());
        // Jamais de cache public pour une 404 ni une redirection.
        $miss = $this->get('/page-absente');
        $this->assertArrayNotHasKey('ETag', $miss->headers());
        $this->assertSame('no-store', $miss->headers()['Cache-Control']);
        $moved = $this->get("/$slug/");
        $this->assertSame(301, $moved->status());
        $this->assertArrayNotHasKey('ETag', $moved->headers());
        $this->assertStringNotContainsString('public', $moved->headers()['Cache-Control']);
    }

    public function test_real_server_sends_no_cookie_on_public_pages_and_serves_assets(): void
    {
        $port = random_int(20000, 40000);
        $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', dirname(__DIR__, 2) . '/public'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $fetch = static function (string $path) use ($port): array {
            $body = (string) @file_get_contents("http://127.0.0.1:$port$path", false, stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0]]));
            return [implode("\n", $http_response_header ?? []), $body];
        };
        try {
            for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
                usleep(100_000);
            }
            foreach (['/', '/actualites', '/une-page-inconnue', '/Majuscules'] as $p) {
                [$h] = $fetch($p);
                $this->assertStringNotContainsStringIgnoringCase('Set-Cookie', $h, $p);
            }
            [$h] = $fetch('/admin/connexion');
            $this->assertMatchesRegularExpression('/^Set-Cookie: mmmgci_sid=/mi', $h, 'l\'administration garde sa session');
            [$h, $css] = $fetch('/assets/css/public.css');
            $this->assertStringContainsString('200', explode("\n", $h)[0]);
            $this->assertStringContainsString('body.public', $css, 'les fichiers statiques passent avant l\'attrape-tout');
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    public function test_draft_archived_and_future_scheduled_pages_return_404(): void
    {
        foreach (['brouillon', 'archive', 'futur'] as $state) {
            [, $slug] = $this->page("Page $state", $state);
            $res = $this->get("/$slug");
            $this->assertSame(404, $res->status(), $state);
            $this->assertStringNotContainsString("Page $state", $res->body());
        }
    }

    /** @return array<string,array{0:string,1:int,2?:string}> */
    public static function paths(): array
    {
        return [
            'double barre' => ['/a//b', 404], 'barre inverse' => ['/a\\b', 404], 'point' => ['/a.b', 404], 'remontée' => ['/../etc/passwd', 404],
            'remontée encodée' => ['/%2e%2e/etc', 404], 'barre encodée' => ['/a%2fb', 404], 'barre inverse encodée' => ['/a%5cb', 404],
            'espace' => ['/a%20b', 404], 'souligné' => ['/a_b', 404], 'accent' => ['/caf%C3%A9', 404], 'six segments' => ['/a/b/c/d/e/f', 404],
            'barre finale' => ['/statuts/', 301, '/statuts'], 'majuscules' => ['/Statuts', 301, '/statuts'], 'les deux' => ['/A/B/', 301, '/a/b'],
            'liste avec barre finale' => ['/actualites/', 301, '/actualites'], 'liste en majuscules' => ['/Actualites', 301, '/actualites'],
            'hôte en tête' => ['//evil.example/', 404],
        ];
    }

    #[DataProvider('paths')]
    public function test_path_normalisation_rejects_traversal_and_double_slashes(string $path, int $status, ?string $to = null): void
    {
        $res = $this->get($path);
        $this->assertSame($status, $res->status(), $path);
        if ($to !== null) {
            $this->assertSame($to, $res->headers()['Location']);
        } else {
            $this->assertArrayNotHasKey('Location', $res->headers());
        }
    }

    public function test_query_string_is_ignored_and_nul_byte_is_refused(): void
    {
        [, $slug] = $this->page('Avec requête');
        $res = $this->app()->handle(new Request('GET', "/$slug", ['x' => '<b>'], [], [], ['QUERY_STRING' => 'x=%3Cb%3E']));
        $this->assertSame(200, $res->status());
        $this->assertStringNotContainsString('<b>', $res->body());
        $this->assertContains($this->get('/a%00b')->status(), [400, 404], 'refusé par le routeur avant la résolution');
    }

    public function test_404_page_does_not_echo_the_path(): void
    {
        foreach (['/sonde-' . bin2hex(random_bytes(4)), '/x%3Cscript%3Ey', '/a.php'] as $p) {
            $res = $this->get($p);
            $this->assertSame(404, $res->status());
            $this->assertStringContainsString('<h1>Page introuvable</h1>', $res->body());
            $this->assertStringContainsString('<meta name="robots" content="noindex">', $res->body());
            $this->assertSame('noindex', $res->headers()['X-Robots-Tag']);
            $this->assertStringNotContainsString(trim(rawurldecode($p), '/'), $res->body());
        }
    }

    public function test_old_slug_redirects_301_within_the_site_only(): void
    {
        [$id, $slug] = $this->page('Histoire');
        $this->pages()->update($id, ['title' => 'Histoire', 'slug' => 'notre-histoire', 'blocks' => [['type' => 'titre', 'text' => 'Histoire', 'level' => '2']]], $this->publisher);
        $res = $this->get("/$slug");
        $this->assertSame(301, $res->status());
        $this->assertSame('/notre-histoire', $res->headers()['Location']);
        $this->assertSame(200, $this->get('/notre-histoire')->status());
        // Chemin hiérarchique : vers l'adresse plate.
        [$parent, $pslug] = $this->page('Mutuelle');
        $this->db->query('UPDATE cms_pages SET parent_id = ? WHERE id = ?', [$parent, $id]);
        $this->assertSame('/notre-histoire', $this->get("/$pslug/notre-histoire")->headers()['Location']);
        // Une redirection altérée en base vers un autre hôte n'est jamais suivie.
        $this->db->query("INSERT INTO cms_page_redirects (page_id, old_slug) VALUES (?, 'piege')", [$id]);
        $this->db->query("UPDATE cms_pages SET slug = 'evil.example' WHERE id = ?", [$id]);
        $res = $this->get('/piege');
        $this->assertNotSame(301, $res->status());
        $this->assertArrayNotHasKey('Location', $res->headers());
    }

    public function test_home_serves_the_accueil_page_or_the_waiting_page(): void
    {
        $res = $this->get('/');
        $this->assertSame(200, $res->status());
        $this->assertStringContainsString('Le site de la Mutuelle est en cours de construction', $res->body());
        [, $slug] = $this->page('Bienvenue', 'publie', [['type' => 'intro', 'title' => 'Mot du président', 'note' => '']], 'accueil');
        $this->assertSame('accueil', $slug);
        $res = $this->get('/');
        $this->assertStringContainsString('<h1>Bienvenue</h1>', $res->body());
        $this->assertStringNotContainsString('en cours de construction', $res->body());
        $moved = $this->get('/accueil');
        $this->assertSame(301, $moved->status());
        $this->assertSame('/', $moved->headers()['Location']);
    }

    /** @return array<string,array{0:array<string,mixed>}> un cas par type de bloc */
    public static function hostileBlocks(): array
    {
        $x = self::X;
        return [
            'texte' => [['type' => 'texte', 'html' => '<p>Bonjour</p><script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">lien</a><p onclick="x()">clic</p>']],
            'titre' => [['type' => 'titre', 'text' => $x, 'level' => 2]],
            'intro' => [['type' => 'intro', 'title' => $x, 'note' => $x . "\nligne"]],
            'faits' => [['type' => 'faits', 'items' => [['label' => $x, 'value' => $x]]]],
            'domaines' => [['type' => 'domaines', 'items' => [['name' => $x, 'nature' => $x, 'description' => $x]]]],
            'liste' => [['type' => 'liste', 'items' => [['text' => $x]]]],
            'appel' => [['type' => 'appel', 'title' => $x, 'text' => $x, 'button_label' => $x, 'page_id' => null, 'url' => 'https://x.example/"onmouseover=alert(1)']],
            'image' => [['type' => 'image', 'media_id' => 'MEDIA', 'caption' => $x]],
            'documents' => [['type' => 'documents', 'category' => $x]],
            'articles' => [['type' => 'articles', 'count' => 3]],
            'evenements' => [['type' => 'evenements', 'count' => 3]],
            'formulaire' => [['type' => 'formulaire', 'form_key' => $x]],
        ];
    }

    /** Attributs on* et éléments script réellement présents dans le DOM (le texte échappé n'en crée pas). @return list<string> */
    private static function handlers(string $html): array
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        $bad = [];
        foreach ((new \DOMXPath($doc))->query('//*') as $el) {
            if ($el->nodeName === 'script') {
                $bad[] = 'script';
            }
            foreach ($el->attributes as $a) {
                if (str_starts_with(strtolower($a->nodeName), 'on')) {
                    $bad[] = $el->nodeName . '@' . $a->nodeName;
                }
            }
        }
        return $bad;
    }

    #[DataProvider('hostileBlocks')]
    public function test_hostile_block_text_is_escaped_and_html_is_sanitised(array $block): void
    {
        [$audit, $gate] = $this->services();
        if ($block['type'] === 'image') {
            $block['media_id'] = $this->media('public', self::X);
        }
        if ($block['type'] === 'documents') {
            $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'd/a.pdf', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 2048, 'sha256' => str_repeat('0', 64)]);
            $this->db->insert('cms_documents', ['title' => self::X, 'category' => self::X, 'file_id' => $file, 'status' => 'publie', 'document_date' => '2026-03-01', 'created_by' => $this->author]);
        }
        if ($block['type'] === 'articles') {
            $art = new ArticleService($this->db, $audit, $gate);
            $a = $art->create(['title' => self::X, 'excerpt' => self::X, 'body' => '<p>x</p>'], $this->author);
            $art->transition($a, 'publie', $this->publisher, null, (int) $art->get($a)['content_version']);
        }
        if ($block['type'] === 'evenements') {
            $ev = new EventService($this->db, $audit, $gate);
            $e = $ev->create(['title' => self::X, 'location' => self::X, 'starts_at' => $this->future('+3 days')->format('Y-m-d\TH:i')], $this->author);
            $ev->transition($e, 'publie', $this->publisher, null, (int) $ev->get($e)['content_version']);
        }
        $html = $this->get('/' . $this->rawPage([$block]))->body();
        $main = (string) preg_replace('~^.*<main[^>]*>|</main>.*$~s', '', $html);
        $this->assertStringNotContainsString('<script>alert', $main);
        $this->assertSame([], self::handlers($main), 'aucun gestionnaire d\'évènement ni élément script');
        $this->assertStringNotContainsString('javascript:', $main);
        $this->assertStringNotContainsString('<img src=x', $main);
        if ($block['type'] === 'texte') {
            $this->assertStringContainsString('<p>Bonjour</p>', $main);
        } elseif ($block['type'] !== 'formulaire') {
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;&gt;', $main, 'texte échappé et affiché');
        }
        if ($block['type'] === 'appel') {
            $this->assertStringContainsString('href="https://x.example/&quot;onmouseover=alert(1)"', $main);
            $this->assertStringContainsString('rel="noopener noreferrer"', $main);
        }
        if ($block['type'] === 'intro') {
            $this->assertStringContainsString('<br>', $main);
        }
        if ($block['type'] === 'formulaire') {
            $this->assertStringNotContainsString('<form', $main, 'clé inconnue : rien');
            $this->assertStringNotContainsString('btn', $main);
        }
    }

    public function test_form_block_links_to_the_form_pages_without_rendering_a_form(): void
    {
        $html = $this->get('/' . $this->rawPage([['type' => 'formulaire', 'form_key' => 'adhesion'], ['type' => 'formulaire', 'form_key' => 'contact'],
            ['type' => 'formulaire', 'form_key' => 'partenariat']]))->body();
        $this->assertStringContainsString('href="/adhesion-en-ligne"', $html);
        $this->assertSame(2, substr_count($html, 'href="/contact"'));
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('csrf', $html);
    }

    public function test_call_to_action_is_hidden_for_offline_target_and_unsafe_url(): void
    {
        [$draft] = $this->page('Cible brouillon', 'brouillon');
        [$live, $liveSlug] = $this->page('Cible en ligne');
        $html = $this->get('/' . $this->rawPage([
            ['type' => 'appel', 'title' => 'Vers brouillon', 'text' => '', 'button_label' => 'Bouton brouillon', 'page_id' => $draft, 'url' => null],
            ['type' => 'appel', 'title' => 'Vers en ligne', 'text' => '', 'button_label' => 'Bouton en ligne', 'page_id' => $live, 'url' => null],
            ['type' => 'appel', 'title' => 'Vers script', 'text' => '', 'button_label' => 'Bouton script', 'page_id' => null, 'url' => 'javascript:alert(1)'],
            ['type' => 'appel', 'title' => 'Vers hôte', 'text' => '', 'button_label' => 'Bouton hôte', 'page_id' => null, 'url' => '//evil.example'],
            ['type' => 'appel', 'title' => 'Vers relatif', 'text' => '', 'button_label' => 'Bouton relatif', 'page_id' => null, 'url' => '/documents'],
        ]))->body();
        $this->assertStringContainsString('Vers brouillon', $html, 'le titre reste');
        $this->assertStringNotContainsString('Bouton brouillon', $html, 'page hors ligne : aucun bouton');
        $this->assertStringContainsString('href="/' . $liveSlug . '"><span>Bouton en ligne', $html);
        $this->assertStringNotContainsString('Bouton script', $html);
        $this->assertStringNotContainsString('Bouton hôte', $html);
        $this->assertStringContainsString('href="/documents"><span>Bouton relatif', $html, 'lien interne sans rel');
    }

    public function test_image_block_is_omitted_for_private_or_missing_media(): void
    {
        $ok = $this->media('public', 'Quai du port');
        $private = $this->media('private', 'Photo privée');
        $html = $this->get('/' . $this->rawPage([['type' => 'image', 'media_id' => $private, 'caption' => 'Légende privée'],
            ['type' => 'image', 'media_id' => 999999, 'caption' => 'Légende absente'], ['type' => 'image', 'media_id' => $ok, 'caption' => 'Légende visible']]))->body();
        $this->assertStringNotContainsString('Légende privée', $html);
        $this->assertStringNotContainsString('Photo privée', $html);
        $this->assertStringNotContainsString('Légende absente', $html);
        $this->assertStringContainsString('alt="Quai du port"', $html);
        $this->assertStringContainsString('Légende visible', $html);
        $this->assertSame(1, substr_count($html, '<figure'));
    }

    public function test_unknown_block_type_is_ignored(): void
    {
        $res = $this->get('/' . $this->rawPage([['type' => '../../layouts/public', 'html' => 'Secret un'], ['type' => 'inconnu', 'text' => 'Secret deux'], 'pas un tableau',
            ['type' => ['tableau']], ['type' => 'titre', 'text' => ['tableau'], 'level' => 2], ['type' => 'faits', 'items' => 'chaine'], ['type' => 'liste', 'items' => [['text' => 'Visible']]]]));
        $this->assertSame(200, $res->status());
        $this->assertStringNotContainsString('Secret', $res->body());
        $this->assertStringContainsString('<li>Visible</li>', $res->body());
    }

    public function test_dynamic_blocks_show_empty_states(): void
    {
        $html = $this->get('/' . $this->rawPage([['type' => 'documents', 'category' => ''], ['type' => 'articles', 'count' => 3], ['type' => 'evenements', 'count' => 3]]))->body();
        $this->assertStringContainsString('Aucun document publié pour le moment.', $html);
        $this->assertStringContainsString('Aucune actualité publiée pour le moment.', $html);
        $this->assertStringContainsString('Aucun évènement à venir pour le moment.', $html);
        foreach (['/actualites' => 'Aucune actualité publiée', '/evenements' => 'Aucun évènement à venir', '/documents' => 'Aucun document publié', '/galeries' => 'Aucune galerie publiée'] as $p => $msg) {
            $res = $this->get($p);
            $this->assertSame(200, $res->status(), $p);
            $this->assertStringContainsString($msg, $res->body(), $p);
            $this->assertSame(1, substr_count($res->body(), '<h1'), $p);
        }
    }

    public function test_article_list_and_detail_show_only_published_and_never_the_staff_name(): void
    {
        [$audit, $gate] = $this->services();
        $this->db->query("UPDATE users SET name = 'Nom Réel Du Rédacteur' WHERE id = ?", [$this->author]);
        $art = new ArticleService($this->db, $audit, $gate);
        $mk = fn (string $t, array $more = []): int => $art->create(['title' => $t, 'body' => '<p>Corps ' . $t . '</p><script>x()</script>'] + $more, $this->author);
        $pub = $mk('Article public');
        $art->transition($pub, 'publie', $this->publisher, null, (int) $art->get($pub)['content_version']);
        $signed = $mk('Article signé', ['author_label' => 'La rédaction']);
        $art->transition($signed, 'publie', $this->publisher, null, (int) $art->get($signed)['content_version']);
        $mk('Article brouillon');
        $later = $mk('Article futur');
        $art->transition($later, 'programme', $this->publisher, $this->future('+2 days'), (int) $art->get($later)['content_version']);
        $list = $this->get('/actualites')->body();
        $this->assertStringContainsString('href="/actualites/article-public"', $list);
        $this->assertStringNotContainsString('Article brouillon', $list);
        $this->assertStringNotContainsString('Article futur', $list);
        $detail = $this->get('/actualites/article-public');
        $this->assertSame(200, $detail->status());
        $this->assertStringContainsString('<p>Corps Article public</p>', $detail->body());
        $this->assertStringNotContainsString('<script>x()', $detail->body());
        $this->assertStringNotContainsString('Nom Réel', $detail->body());
        $this->assertMatchesRegularExpression('~\d{1,2}(er)? (janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre) \d{4}~', $detail->body());
        $this->assertStringContainsString('La rédaction', $this->get('/actualites/article-signe')->body());
        foreach (['/actualites/article-brouillon', '/actualites/article-futur', '/actualites/inconnu'] as $p) {
            $this->assertSame(404, $this->get($p)->status(), $p);
        }
    }

    public function test_event_document_and_gallery_pages_show_only_published(): void
    {
        [$audit, $gate] = $this->services();
        $ev = new EventService($this->db, $audit, $gate);
        $e = fn (string $t, string $when = '+3 days'): int => $ev->create(['title' => $t, 'location' => 'Port autonome', 'description' => '<p>Programme</p>',
            'starts_at' => $this->future($when)->format('Y-m-d\TH:i')], $this->author);
        $up = $e('Assemblée à venir');
        $ev->transition($up, 'publie', $this->publisher, null, (int) $ev->get($up)['content_version']);
        $past = $e('Réunion passée', '-3 days');
        $ev->transition($past, 'publie', $this->publisher, null, (int) $ev->get($past)['content_version']);
        $e('Évènement brouillon');
        $later = $e('Évènement programmé');
        $ev->transition($later, 'programme', $this->publisher, $this->future('+2 days'), (int) $ev->get($later)['content_version']);
        $list = $this->get('/evenements')->body();
        $this->assertStringContainsString('Assemblée à venir', $list);
        $this->assertStringContainsString('Réunion passée', $list);
        $this->assertStringNotContainsString('brouillon', $list);
        $this->assertStringNotContainsString('programmé', $list);
        $detail = $this->get('/evenements/assemblee-a-venir')->body();
        $this->assertStringContainsString('<p>Programme</p>', $detail);
        $this->assertStringContainsString('Port autonome', $detail);
        $this->assertStringContainsString(' h ', $detail, 'heure en français');
        $this->assertSame(404, $this->get('/evenements/evenement-brouillon')->status());
        $this->assertSame(404, $this->get('/evenements/evenement-programme')->status());

        $docs = new DocumentService($this->db, $audit, $gate);
        $pdf = function (): string {
            $f = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/' . bin2hex(random_bytes(4)) . '.pdf', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
            return (string) $this->db->insert('cms_media', ['file_id' => $f, 'title' => 'Doc', 'alt' => null]);
        };
        $d = $docs->create(['title' => 'Statuts signés', 'category' => 'Statuts', 'document_date' => '2026-03-01', 'media_id' => $pdf()], $this->author);
        $docs->transition($d, 'publie', $this->publisher, (int) $docs->get($d)['content_version']);
        $docs->create(['title' => 'Document brouillon', 'category' => 'Statuts', 'document_date' => '2026-03-02', 'media_id' => $pdf()], $this->author);
        $list = $this->get('/documents')->body();
        $this->assertStringContainsString('Statuts signés', $list);
        $this->assertStringContainsString('1er mars 2026', $list);
        $this->assertStringNotContainsString('Document brouillon', $list);

        $g = new GalleryService($this->db, $audit, $gate);
        $album = $g->create(['title' => 'Journée des marins', 'description' => '<p>Souvenirs</p><script>x()</script>'], $this->author);
        $this->db->insert('cms_gallery_items', ['gallery_id' => $album, 'media_id' => $this->media('public', 'Équipage sur le quai'), 'caption' => 'Le quai', 'sort_order' => 1]);
        $this->db->insert('cms_gallery_items', ['gallery_id' => $album, 'media_id' => $this->media('private', 'Privée'), 'sort_order' => 2]);
        $this->assertSame(404, $this->get('/galeries/journee-des-marins')->status(), 'album brouillon');
        $g->transition($album, 'publie', $this->publisher, (int) $g->get($album)['content_version']);
        $g->create(['title' => 'Album brouillon'], $this->author);
        $list = $this->get('/galeries')->body();
        $this->assertStringContainsString('href="/galeries/journee-des-marins"', $list);
        $this->assertStringNotContainsString('Album brouillon', $list);
        $page = $this->get('/galeries/journee-des-marins')->body();
        $this->assertStringContainsString('alt="Équipage sur le quai"', $page);
        $this->assertStringContainsString('<p>Souvenirs</p>', $page);
        $this->assertStringNotContainsString('<script>x()', $page);
        $this->assertStringNotContainsString('Privée', $page);
    }

    public function test_menu_and_footer_hide_unvalidated_contacts(): void
    {
        // Sans menu publié : logo seul (et « Adhésion » seulement si la page existe).
        [, $slug] = $this->page('Une page');
        $html = $this->get("/$slug")->body();
        $this->assertStringNotContainsString('aria-label="Menu principal"', $html);
        $this->assertStringNotContainsString('data-menu-toggle', $html);
        $this->assertStringNotContainsString('Adhésion', $html);
        $this->page('Adhésion', 'publie', [['type' => 'titre', 'text' => 'x', 'level' => 2]], 'adhesion');
        $this->assertStringContainsString('href="/adhesion"><span>Adhésion</span>', $this->get("/$slug")->body());

        [$audit, $gate] = $this->services();
        [$target, $tslug] = $this->page('Cible');
        [$draft] = $this->page('Brouillon de menu', 'brouillon');
        $menus = new MenuService($this->db, $audit, $gate, $this->pages());
        $menus->save('header', [['label' => self::X, 'page_id' => $target, 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Caché', 'page_id' => $draft, 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Externe', 'page_id' => '', 'url' => 'https://exemple.ci', 'target' => '_blank', 'is_active' => true, 'depth' => 0]], $this->publisher);
        $menus->save('footer', [['label' => 'Mentions', 'page_id' => $target, 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0]], $this->publisher);

        $admin = $this->createUser('adminsite@x.test', ['admin_site']);
        $pca = $this->createUser('pca@x.test', ['pca']);
        $s = new SiteSettings($this->db, $audit, $gate);
        $s->save('contact_email', 'contact@exemple.ci', $admin);
        $s->save('contact_phone', '+225 01 02 03 04', $admin);
        $s->save('address', 'Rue du "port" & quai', $admin);
        $seen = fn (string $k): string => (string) array_column($s->all(), 'seen', 'key')[$k];
        $s->validate('address', $pca, 'PV-1', $seen('address'));

        $html = $this->get("/$slug")->body();
        $this->assertStringContainsString('aria-label="Menu principal"', $html);
        $this->assertStringContainsString('href="/' . $tslug . '">&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('Caché', $html);
        $this->assertStringContainsString('href="https://exemple.ci" target="_blank" rel="noopener noreferrer">Externe', $html);
        $this->assertStringContainsString('Mentions', $html);
        $this->assertStringContainsString('Rue du &quot;port&quot; &amp; quai', $html, 'adresse validée, échappée');
        $this->assertStringNotContainsString('contact@exemple.ci', $html, 'courriel non validé');
        $this->assertStringNotContainsString('01 02 03 04', $html, 'téléphone non validé');
        $this->assertStringNotContainsString('href="/adhesion"><span>', $html, 'un menu publié remplace le lien de secours');
    }

    public function test_specific_routes_still_win_over_the_catch_all(): void
    {
        $patterns = array_column($this->app() ? $this->services['router']->routes() : [], 'pattern');
        $this->assertSame('/{chemin:(?!admin(?:/|$)).+}', end($patterns), 'l\'attrape-tout est la dernière route');
        $this->assertSame(405, $this->request('GET', '/admin/deconnexion')->status(), 'route POST seule : 405, pas une page publique');
        $unknown = $this->request('GET', '/admin/inexistante');
        $this->assertSame(404, $unknown->status());
        $this->assertStringNotContainsString('pub-foot', $unknown->body(), '404 standard de l\'administration');
        $this->assertSame(302, $this->request('GET', '/admin')->status());
        $this->assertSame(200, $this->request('GET', '/admin/connexion')->status());
        $this->assertStringContainsString('name="_csrf"', $this->request('GET', '/admin/connexion')->body());
        $verify = $this->get('/verifier/x');
        $this->assertSame(200, $verify->status());
        $this->assertSame('no-store', $verify->headers()['Cache-Control']);
        $this->assertSame('ok', $this->get('/sante')->body()); // (une page ne peut pas prendre l'adresse « sante » : réservée par PageService)
    }
}
