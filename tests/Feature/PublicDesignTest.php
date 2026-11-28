<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\ContrastChecker;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Cms\PageService;
use Tests\Support\AdminTestCase;

/** Site public (L4, tâche 2) : direction « La page est l'objet » — accueil, carte de menu, « Préparer mon dossier », dessins, budget de poids. */
final class PublicDesignTest extends AdminTestCase
{
    private int $author;
    private int $publisher;

    private const DOSSIER = ['type' => 'dossier', 'title' => 'Préparer mon dossier', 'intro' => 'Cochez votre situation.',
        'situations' => [['key' => 'livret', 'label' => 'Livret maritime disponible'], ['key' => 'ayants-droit', 'label' => 'Ayants droit à déclarer']],
        'pieces' => [['label' => "Une fiche individuelle d'adhésion dûment renseignée", 'situations' => ''],
            ['label' => 'Une copie du livret maritime, lorsque disponible ou pertinent', 'situations' => 'livret'],
            ['label' => 'Les informations relatives aux ayants droit', 'situations' => 'ayants-droit, livret']]];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_article_tag', 'cms_articles'] as $t) {
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

    private function publish(array $data): int
    {
        $this->app();
        $p = new PageService($this->db, $this->services['audit'](), $this->services['gate']());
        $id = $p->create($data + ['template' => 'standard'], $this->author);
        $p->publish($id, $this->publisher, null, (int) $p->get($id)['content_version']);
        return $id;
    }

    private function home(array $extra = [], array $blocks = []): void
    {
        $this->publish(['title' => 'Un nouveau jour se lève', 'slug' => 'accueil', 'template' => 'accueil',
            'excerpt' => "Une organisation de solidarité, d'entraide et d'accompagnement social.",
            'blocks' => $blocks !== [] ? $blocks : [['type' => 'domaines', 'items' => [['name' => 'Santé', 'nature' => "Information, orientation sanitaire et dispositifs d'accès aux soins.", 'description' => ''],
                ['name' => 'Maladie', 'nature' => 'Assistance exceptionnelle après examen du dossier.', 'description' => '']]]]] + $extra);
    }

    private function get(string $path): Response
    {
        return $this->app()->handle(new Request('GET', $path, [], [], [], ['REMOTE_ADDR' => '127.0.0.1']));
    }

    public function test_home_has_one_h1_and_primary_adhesion_action_first(): void
    {
        $this->home();
        $html = $this->get('/')->body();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Un nouveau jour se lève</h1>', $html);
        $this->assertStringNotContainsString('btn-primary" href="/adhesion"', $html, 'pas de page « adhesion » en ligne : pas de bouton principal');
        $this->assertStringContainsString('href="#aides">Voir les aides</a>', $html);

        $this->publish(['title' => 'Demander mon adhésion', 'slug' => 'adhesion', 'blocks' => [['type' => 'titre', 'text' => 'Conditions', 'level' => '2']]]);
        $html = $this->get('/')->body();
        $main = substr($html, (int) strpos($html, '<main'));
        $primary = strpos($main, '<a class="btn btn-primary" href="/adhesion"><span>Comment adhérer</span></a>');
        $this->assertNotFalse($primary);
        $this->assertLessThan(strpos($main, 'Voir les aides'), $primary, 'action principale avant la secondaire');
        $this->assertLessThan($primary, strpos($main, '<h1'), 'titre puis action');
        $this->assertSame($primary, strpos($main, '<a '), 'premier lien du contenu');
        $this->assertSame('no-cache', $this->get('/')->headers()['Cache-Control'], 'accueil revalidé : le bouton suit l\'ouverture');

        // Adhésion en ligne ouverte (deux paramètres validés) : le bouton mène au formulaire.
        $s = new \App\Core\Settings($this->db);
        $s->validate('site', 'adhesion_open', true, $this->publisher, 'PV test');
        $s->validate('site', 'consent_text', 'Texte de consentement de test.', $this->publisher, 'PV test');
        $this->assertStringContainsString('<a class="btn btn-primary" href="/adhesion-en-ligne"><span>Demander mon adhésion</span></a>', $this->get('/')->body());
    }

    public function test_menu_card_renders_domains_with_their_reservations(): void
    {
        $this->home();
        $html = $this->get('/')->body();
        $this->assertStringContainsString('<div class="pub-block pub-menu-card" id="aides">', $html);
        $this->assertMatchesRegularExpression('~<strong class="pub-domain-name">Santé</strong><span class="pub-leader" aria-hidden="true"></span><span class="pub-domain-nature">Information, orientation sanitaire et dispositifs d&#039;accès aux soins\.</span>~u', $html);
        $this->assertStringContainsString('<span class="pub-domain-nature">Assistance exceptionnelle après examen du dossier.</span>', $html);
        $this->assertSame(1, substr_count($html, 'id="aides"'), 'une seule ancre');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/public.css');
        $this->assertMatchesRegularExpression('~\.pub-leader \{[^}]*border-bottom: 3px dotted~', $css, 'pointillés légers (3 px au plus)');
    }

    public function test_dossier_list_is_fully_visible_without_js(): void
    {
        $id = $this->publish(['title' => 'Adhésion', 'slug' => 'dossier-test', 'blocks' => [self::DOSSIER]]);
        $this->assertGreaterThan(0, $id);
        $html = $this->get('/dossier-test')->body();
        foreach (self::DOSSIER['pieces'] as $p) {
            $this->assertStringContainsString(e($p['label']), $html);
        }
        $this->assertStringNotContainsString(' hidden', substr($html, (int) strpos($html, 'pub-dossier-pieces')), 'aucune pièce masquée côté serveur');
        $this->assertSame(2, substr_count($html, '(selon votre situation)'), 'mention sur les pièces conditionnelles seulement');
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/public.css');
        $this->assertStringContainsString('.pub-dossier-choices { display: none;', $css, 'sans JS : cases masquées');
        $this->assertStringContainsString('html.js .pub-dossier-choices { display: grid;', $css, 'avec JS (js-flag.js) : cases affichées');
        $this->assertStringNotContainsString('<form', substr($html, (int) strpos($html, 'data-dossier')), 'rien n\'est envoyé');
    }

    public function test_dossier_filter_markup_and_script(): void
    {
        $this->publish(['title' => 'Adhésion', 'slug' => 'dossier-test', 'blocks' => [self::DOSSIER]]);
        $html = $this->get('/dossier-test')->body();
        $this->assertStringContainsString('<section class="pub-block pub-dossier" data-dossier>', $html);
        $this->assertStringContainsString('<input type="checkbox" value="livret" data-situation>', $html);
        $this->assertStringContainsString('<input type="checkbox" value="ayants-droit" data-situation>', $html);
        $this->assertStringContainsString('<li><span>Une fiche individuelle', $html, 'pièce commune : sans data-for');
        $this->assertStringContainsString('<li data-for="livret"><span>Une copie du livret', $html);
        $this->assertStringContainsString('<li data-for="ayants-droit livret"><span>Les informations', $html);
        $this->assertStringContainsString('aria-live="polite" data-dossier-count', $html);
        $this->assertStringContainsString('<script src="' . asset_url('/assets/js/site.js') . '" defer></script>', $html);
        $js = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/site.js');
        $this->assertLessThanOrEqual(4096, strlen($js));
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'eval(', 'fetch(', 'XMLHttpRequest', 'localStorage', 'sessionStorage', 'cookie'] as $bad) {
            $this->assertStringNotContainsString($bad, $js, $bad);
        }
    }

    public function test_svg_art_is_a_static_file_under_6_kb_and_has_no_script(): void
    {
        $dir = dirname(__DIR__, 2) . '/public/assets/img/art';
        foreach (['livret', 'ancre', 'cordage'] as $name) {
            $file = "$dir/$name.svg";
            $this->assertFileExists($file);
            $svg = (string) file_get_contents($file);
            $this->assertLessThan(6 * 1024, strlen($svg), $name);
            $this->assertMatchesRegularExpression('~^<svg xmlns="http://www.w3.org/2000/svg"~', $svg);
            $this->assertMatchesRegularExpression('~<svg[^>]*(?:role="img" aria-label="Illustration[^"]+"|aria-hidden="true")~', $svg, "$name : image nommée ou décorative");
            foreach (['~<script~i', '~\son[a-z]+\s*=~i', '~javascript:~i', '~<foreignObject~i', '~(?:href|src)\s*=\s*"(?!#)~i', '~url\(~i', '~@import~i'] as $p) {
                $this->assertDoesNotMatchRegularExpression($p, $svg, "$name : $p");
            }
        }
    }

    public function test_image_slot_absent_when_no_media_and_never_a_fake_photo(): void
    {
        $this->home();
        $html = $this->get('/')->body();
        $figure = substr($html, (int) strpos($html, '<figure class="home-figure">'), 600);
        $this->assertStringContainsString('src="/assets/img/art/livret.svg" alt="Illustration d', $figure);
        $this->assertStringContainsString('<figcaption>Illustration : le livret maritime</figcaption>', $figure);
        $this->assertStringNotContainsStringIgnoringCase('photo', $figure);
        $this->assertStringNotContainsString('/media/', $figure);
        $this->assertStringNotContainsString('<svg', $html, 'dessins servis comme fichiers, pas incrustés');

        // Image à la une réelle, publique, avec texte alternatif : elle prend l'emplacement, sans mention « illustration ».
        $this->db->query('DELETE FROM cms_pages');
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => '2026/11/' . bin2hex(random_bytes(4)) . '.jpg', 'original_name' => 'x', 'mime' => 'image/jpeg', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        $media = $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Port', 'alt' => 'Navires à quai']);
        $this->home(['featured_media_id' => $media]);
        $figure = substr(($html = $this->get('/')->body()), (int) strpos($html, '<figure class="home-figure">'), 400);
        $this->assertStringContainsString('alt="Navires à quai"', $figure);
        $this->assertStringContainsString('src="/media/2026/11/', $figure);
        $this->assertStringNotContainsString('livret.svg', $figure);
        $this->assertStringNotContainsString('Illustration', $figure);
    }

    public function test_no_inline_styles_scripts_or_handlers_in_public_views(): void
    {
        $this->home([], [['type' => 'domaines', 'items' => [['name' => 'Santé', 'nature' => 'Information', 'description' => '']]], self::DOSSIER,
            ['type' => 'liste', 'items' => [['text' => 'Étape']]], ['type' => 'faits', 'items' => [['label' => 'Période de référence', 'value' => '2026–2027']]],
            ['type' => 'appel', 'title' => 'Adhérer', 'text' => '', 'button_label' => 'Demander', 'page_id' => null, 'url' => '/contact']]);
        foreach (['/', '/actualites', '/documents', '/galeries', '/introuvable'] as $path) {
            $html = $this->get($path)->body();
            foreach (['~<[^>]*\sstyle\s*=~i', '~<style\b~i', '~<script\b(?![^>]*\ssrc=)~i', '~<[^>]*\son[a-z]+\s*=~i', '~javascript:~i'] as $p) {
                $this->assertDoesNotMatchRegularExpression($p, $html, "$path : $p");
            }
        }
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/public.css');
        $this->assertDoesNotMatchRegularExpression('~(?:linear|radial|conic)-gradient~', $css, 'aucun dégradé');
        $site = strstr($css, '/* Vérification publique', true); // la vérification de carte et l'aperçu d'administration gardent le kit L2
        $this->assertIsString($site);
        $this->assertDoesNotMatchRegularExpression('~border-radius:\s*var\(--r-(?:md|lg)\)~', $site, 'un seul rayon (--r-sm) sur le site public');
        $this->assertDoesNotMatchRegularExpression('~font-family:\s*+(?!var\(--font-(?:display|body)\))~', $css, 'deux polices seulement');
    }

    public function test_contrast_pairs_for_public_tokens(): void
    {
        $declared = array_map(static fn (array $p): string => $p[0] . ' sur ' . $p[1], ContrastChecker::PAIRS);
        foreach (['--ink sur --paper', '--paper sur --navy-950', '--navy-900 sur --paper', '--navy-800 sur --paper', '--gold-700 sur --paper',
            '--ocean-100 sur --navy-950', '--navy-950 sur --gold-400', '--gold-400 sur --navy-950', '--ocean-600 sur --paper'] as $pair) {
            $this->assertContains($pair, $declared);
        }
        $rows = ContrastChecker::check((string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/tokens.css'));
        $this->assertSame([], array_values(array_filter($rows, static fn (array $r): bool => !$r['ok'])));
    }

    public function test_home_page_weight_budget(): void
    {
        $this->home([], [['type' => 'domaines', 'items' => array_fill(0, 8, ['name' => 'Santé', 'nature' => "Information, orientation sanitaire et dispositifs d'accès aux soins.", 'description' => ''])],
            self::DOSSIER, ['type' => 'liste', 'items' => array_fill(0, 3, ['text' => "Constituer le dossier d'adhésion prévu à l'article 6."])]]);
        $html = $this->get('/')->body();
        $public = dirname(__DIR__, 2) . '/public';
        preg_match_all('~<(?:link rel="stylesheet" href|script src)="(/assets/[^"?]+)(?:\?v=[0-9a-f]+)?"~', $html, $m);
        $this->assertNotEmpty($m[1]);
        $total = strlen($html);
        $css = '';
        foreach ($m[1] as $asset) {
            $this->assertFileExists($public . $asset);
            $total += (int) filesize($public . $asset);
            $css .= str_ends_with($asset, '.css') ? (string) file_get_contents($public . $asset) : '';
        }
        preg_match_all('~url\("\.\./fonts/([^"]+\.woff2)"\)~', $css, $fonts);
        $this->assertCount(2, $fonts[1], 'deux polices');
        foreach ($fonts[1] as $font) {
            $total += (int) filesize("$public/assets/fonts/$font");
        }
        $this->assertLessThan(200 * 1024, $total, "accueil : $total octets (HTML + CSS + JS + polices)");
    }
}
