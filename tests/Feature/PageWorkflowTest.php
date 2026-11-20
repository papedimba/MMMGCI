<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Modules\Cms\ContentTransitionInvalid;
use App\Modules\Cms\MediaService;
use App\Modules\Cms\PageInvalid;
use App\Modules\Cms\PageService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

final class PageWorkflowTest extends AdminTestCase
{
    private const EVIL = '<script>alert(1)</script>"><img src=x onerror=alert(2)>';
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_page_redirects', 'cms_menu_items', 'cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_events', 'cms_articles'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query('DELETE FROM users');
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    private function service(): PageService
    {
        $this->app();
        return new PageService($this->db, $this->services['audit'](), $this->services['gate']());
    }

    /** @param array<string,mixed> $extra */
    private function page(string $title, array $extra = [], ?int $actor = null): int
    {
        return $this->service()->create($extra + ['title' => $title, 'blocks' => [['type' => 'titre', 'text' => $title, 'level' => '2']]], $actor ?? $this->author);
    }

    private function image(string $alt = 'Pirogues au quai'): int
    {
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/' . bin2hex(random_bytes(4)) . '.png', 'original_name' => 'x', 'mime' => 'image/png', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        return $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Quai', 'alt' => $alt]);
    }

    private function stateOf(int $id): string
    {
        return (string) $this->db->fetchValue('SELECT status FROM cms_pages WHERE id = ?', [$id]);
    }

    private function future(string $modify = '+2 days'): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($modify);
    }

    public function test_author_cannot_publish_by_forged_post(): void
    {
        $id = $this->page('Statuts');
        $this->actingAs($this->author);
        foreach (['publie', 'programme', 'archive'] as $to) {
            $res = $this->request('POST', "/admin/site/pages/$id/etat", ['to' => $to, 'at' => $this->future()->format('Y-m-d\TH:i')]);
            $this->assertSame(422, $res->status(), $to);
            $this->assertStringContainsString('droit de publication requis', html_entity_decode($res->body()));
        }
        $this->assertSame(422, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'retirer'])->status());
        $this->assertSame('brouillon', $this->stateOf($id));
        $this->assertNull($this->db->fetchValue('SELECT published_by FROM cms_pages WHERE id = ?', [$id]));
        // Il peut soumettre et retirer sa soumission.
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'en_relecture'])->status());
        $this->assertSame('en_relecture', $this->stateOf($id));
        $this->assertSame($this->author, (int) $this->db->fetchValue('SELECT submitted_by FROM cms_pages WHERE id = ?', [$id]));
        $this->assertSame(422, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'publie'])->status());
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'brouillon'])->status());
        $this->assertSame('brouillon', $this->stateOf($id));
        // Le formulaire du rédacteur ne propose pas Publier.
        $html = $this->request('GET', "/admin/site/pages/$id")->body();
        $this->assertStringContainsString('Soumettre pour relecture', $html);
        $this->assertStringNotContainsString('value="publie"', $html);
    }

    public function test_editing_a_published_page_keeps_published_version_public_until_republish(): void
    {
        $s = $this->service();
        $img = $this->image();
        $id = $s->create(['title' => 'Nos missions', 'excerpt' => 'Extrait v1', 'seo_title' => 'SEO v1', 'featured_media_id' => (string) $img,
            'blocks' => [['type' => 'intro', 'title' => 'Version 1', 'note' => '']]], $this->author);
        $s->submit($id, $this->author);
        $s->publish($id, $this->publisher);
        $this->assertSame('Version 1', $s->liveVersion($id)['blocks'][0]['title']);

        $s->update($id, ['title' => 'Nos missions (v2)', 'slug' => 'nos-missions', 'excerpt' => 'Extrait v2', 'blocks' => [['type' => 'intro', 'title' => 'Version 2', 'note' => '']]], $this->author);
        $this->assertSame('brouillon', $this->stateOf($id));
        $live = $s->liveVersion($id);
        $this->assertSame('Version 1', $live['blocks'][0]['title'], 'le public voit encore la version publiée');
        $this->assertSame('Nos missions', $live['title']);
        $this->assertSame('Extrait v1', $live['excerpt']);
        $this->assertSame('SEO v1', $live['seo_title']);
        $this->assertNotNull($live['featured_file_id'], 'image à la une de la version en ligne conservée');
        $this->assertNotNull($live['published_at']);
        $this->assertSame('Version 2', $s->get($id)['blocks'][0]['title'], 'copie de travail');
        // L'image de la version en ligne reste protégée contre la suppression.
        $this->assertNotSame([], (new MediaService($this->db, $this->services['audit'](), $this->services['uploads']()))->usages($img));

        // Relecture : toujours l'ancienne version ; republication : la nouvelle, plus de copie conservée.
        $s->submit($id, $this->author);
        $this->assertSame('Version 1', $s->liveVersion($id)['blocks'][0]['title']);
        $this->actingAs($this->publisher);
        $html = $this->request('GET', "/admin/site/pages/$id")->body();
        $this->assertStringContainsString('Republier', $html);
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'publie'])->status());
        $this->assertSame('publie', $this->stateOf($id));
        $this->assertSame('Version 2', $s->liveVersion($id)['blocks'][0]['title']);
        $this->assertSame('Nos missions (v2)', $s->liveVersion($id)['title']);
        $this->assertNull($this->db->fetchValue('SELECT published_blocks FROM cms_pages WHERE id = ?', [$id]));

        // Un enregistrement sans changement de contenu ne dépublie pas.
        $p = $s->get($id);
        $s->update($id, ['title' => $p['title'], 'slug' => $p['slug'], 'excerpt' => $p['excerpt'], 'blocks' => $p['blocks']], $this->author);
        $this->assertSame('publie', $this->stateOf($id));
    }

    public function test_live_version_rules(): void
    {
        $s = $this->service();
        $draft = $this->page('Brouillon');
        $this->assertNull($s->liveVersion($draft), 'brouillon jamais publié : rien');
        $s->submit($draft, $this->author);
        $this->assertNull($s->liveVersion($draft), 'en relecture : rien');

        $pub = $this->page('Publiée');
        $s->publish($pub, $this->publisher);
        $this->assertSame('Publiée', $s->liveVersion($pub)['blocks'][0]['text'], 'publie : blocks');

        $s->update($pub, ['title' => 'Publiée', 'slug' => 'publiee', 'blocks' => [['type' => 'titre', 'text' => 'Reprise', 'level' => '2']]], $this->author);
        $this->assertSame('Publiée', $s->liveVersion($pub)['blocks'][0]['text'], 'brouillon avec version conservée : published_blocks');

        // Republication programmée d'une page en ligne : l'ancienne version reste jusqu'à l'échéance.
        $s->publish($pub, $this->publisher, $this->future());
        $this->assertSame('programme', $this->stateOf($pub));
        $this->assertSame('Publiée', $s->liveVersion($pub)['blocks'][0]['text']);
        $this->db->query("UPDATE cms_pages SET published_at = '2020-01-01 00:00:00' WHERE id = ?", [$pub]);
        $this->assertSame('Reprise', $s->liveVersion($pub)['blocks'][0]['text'], 'programme échu : copie de travail');

        $sched = $this->page('Programmée');
        $s->publish($sched, $this->publisher, $this->future());
        $this->assertNull($s->liveVersion($sched), 'programme non échu jamais publié : rien');

        $s->transition($sched, 'archive', $this->publisher);
        $this->assertNull($s->liveVersion($sched), 'archive : rien');

        // Retirer la version conservée d'un brouillon en reprise.
        $p3 = $this->page('Trois');
        $s->publish($p3, $this->publisher);
        $s->update($p3, ['title' => 'Trois bis', 'slug' => 'trois', 'blocks' => []], $this->author);
        $this->assertNotNull($s->liveVersion($p3));
        try {
            $s->unpublish($p3, $this->author);
            $this->fail('rédacteur');
        } catch (ContentTransitionInvalid) {
        }
        $s->unpublish($p3, $this->publisher);
        $this->assertNull($s->liveVersion($p3));
        $this->assertSame('brouillon', $this->stateOf($p3));
    }

    public function test_schedule_requires_future_date_and_publisher(): void
    {
        $s = $this->service();
        $id = $this->page('Assemblée');
        foreach ([null, $this->future('-1 minute')] as $at) {
            try {
                $s->transition($id, 'programme', $this->publisher, $at);
                $this->fail('date refusée attendue');
            } catch (ContentTransitionInvalid $e) {
                $this->assertStringContainsString('futures', $e->getMessage());
            }
        }
        try {
            $s->publish($id, $this->author, $this->future());
            $this->fail('rédacteur');
        } catch (ContentTransitionInvalid) {
        }
        $this->assertSame('brouillon', $this->stateOf($id));
        $this->actingAs($this->publisher);
        $this->assertSame(422, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'programme', 'at' => 'demain'])->status());
        $at = $this->future('+3 days')->format('Y-m-d\TH:i');
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'programme', 'at' => $at])->status());
        $this->assertSame('programme', $this->stateOf($id));
        $this->assertSame(str_replace('T', ' ', $at) . ':00', $this->db->fetchValue('SELECT published_at FROM cms_pages WHERE id = ?', [$id]));
        $this->assertSame($this->publisher, (int) $this->db->fetchValue('SELECT published_by FROM cms_pages WHERE id = ?', [$id]));
        // Modifier une page programmée annule la programmation.
        $s->update($id, ['title' => 'Assemblée générale', 'slug' => 'assemblee', 'blocks' => []], $this->author);
        $this->assertSame('brouillon', $this->stateOf($id));
    }

    public function test_parent_cycle_is_refused(): void
    {
        $s = $this->service();
        $a = $this->page('A');
        $b = $this->page('B', ['parent_id' => (string) $a]);
        $c = $this->page('C', ['parent_id' => (string) $b]);
        foreach ([[$a, $c], [$a, $a], [$b, $c]] as [$page, $parent]) {
            try {
                $s->move($page, $parent, $this->publisher);
                $this->fail("$page sous $parent");
            } catch (PageInvalid $e) {
                $this->assertArrayHasKey('parent_id', $e->errors);
            }
        }
        $this->actingAs($this->author);
        $res = $this->request('POST', "/admin/site/pages/$a", ['title' => 'A', 'slug' => 'a', 'template' => 'standard', 'parent_id' => (string) $c]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('sous elle-même', html_entity_decode($res->body()));
        $this->assertNull($this->db->fetchValue('SELECT parent_id FROM cms_pages WHERE id = ?', [$a]));
        $s->move($c, null, $this->author);
        $s->move($a, $c, $this->author);
        $this->assertSame($c, (int) $this->db->fetchValue('SELECT parent_id FROM cms_pages WHERE id = ?', [$a]));
        // Liste hiérarchique : C, puis A sous C, puis B sous A.
        $tree = $s->tree();
        $this->assertSame([[$c, 0], [$a, 1], [$b, 2]], array_map(static fn (array $r): array => [(int) $r['id'], $r['depth']], $tree));
        // Le sélecteur de parent n'offre ni la page ni ses descendants.
        $html = $this->request('GET', "/admin/site/pages/$a")->body();
        $select = explode('</select>', substr($html, (int) strpos($html, 'name="parent_id"')))[0];
        $this->assertStringContainsString('<option value="' . $c . '"', $select);
        $this->assertStringNotContainsString('<option value="' . $b . '"', $select);
        $this->assertStringNotContainsString('<option value="' . $a . '"', $select);
    }

    public function test_reserved_and_duplicate_slugs_are_refused(): void
    {
        $s = $this->service();
        $this->page('Contact', ['slug' => 'contact']);
        foreach (['admin', 'media', 'sitemap.xml', 'contact', 'Mauvais Slug', 'a--b'] as $slug) {
            try {
                $this->page('X', ['slug' => $slug]);
                $this->fail($slug);
            } catch (PageInvalid $e) {
                $this->assertArrayHasKey('slug', $e->errors, $slug);
            }
        }
        // Vide : proposé depuis le titre, suffixé si pris ; réservé suffixé « -page ».
        $this->assertSame('contact-2', $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$this->page('Contact')]));
        $this->assertSame('admin-page', $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$this->page('Admin')]));
        $this->assertSame('ete-a-abidjan', $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$this->page("E\u{0301}te\u{0301} à Abidjan")]));
    }

    public function test_slug_change_creates_redirect(): void
    {
        $s = $this->service();
        $draft = $this->page('Jamais publiée', ['slug' => 'jamais']);
        $s->update($draft, ['title' => 'Jamais publiée', 'slug' => 'jamais-2', 'blocks' => []], $this->author);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_page_redirects'), 'aucune redirection pour une page jamais en ligne');

        $id = $this->page('Adhésion', ['slug' => 'adhesion']);
        $s->publish($id, $this->publisher);
        $s->update($id, ['title' => 'Adhésion', 'slug' => 'adherer', 'blocks' => [['type' => 'titre', 'text' => 'Adhésion', 'level' => '2']]], $this->author);
        $this->assertSame('publie', $this->stateOf($id), 'changer d\'adresse seule ne dépublie pas');
        $this->assertSame($id, (int) $this->db->fetchValue("SELECT page_id FROM cms_page_redirects WHERE old_slug = 'adhesion'"));
        // L'ancienne adresse ne peut pas être reprise par une autre page.
        try {
            $this->page('Autre', ['slug' => 'adhesion']);
            $this->fail('ancienne adresse reprise');
        } catch (PageInvalid $e) {
            $this->assertStringContainsString('ancienne adresse', $e->errors['slug']);
        }
        // Retour à l'ancienne adresse : la redirection devient « adherer ».
        $s->update($id, ['title' => 'Adhésion', 'slug' => 'adhesion', 'blocks' => [['type' => 'titre', 'text' => 'Adhésion', 'level' => '2']]], $this->author);
        $this->assertSame(['adherer'], array_column($this->db->fetchAll('SELECT old_slug FROM cms_page_redirects WHERE page_id = ?', [$id]), 'old_slug'));
    }

    public function test_unknown_block_type_is_refused(): void
    {
        $this->actingAs($this->author);
        $res = $this->request('POST', '/admin/site/pages/nouvelle', ['title' => 'Page', 'template' => 'standard', 'blocs' => [['type' => 'titre', 'text' => 'ok', 'level' => '2'], ['type' => 'iframe', 'src' => 'x']]]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Type de bloc inconnu', html_entity_decode($res->body()));
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
    }

    public function test_block_html_is_sanitised_on_save(): void
    {
        $this->actingAs($this->author);
        $res = $this->request('POST', '/admin/site/pages/nouvelle', ['title' => 'Riche', 'template' => 'standard',
            'blocs' => [['type' => 'texte', 'html' => '<h1 onmouseover="x()">Titre</h1><p style="color:red">Texte <script>alert(1)</script><a href="javascript:x">l</a><img src="https://evil.test/x.png" alt="x"></p>']]]);
        $this->assertSame(302, $res->status());
        $blocks = json_decode((string) $this->db->fetchValue('SELECT blocks FROM cms_pages'), true);
        $this->assertSame('<h2>Titre</h2><p>Texte l</p>', $blocks[0]['html']);
    }

    public function test_block_text_fields_are_escaped_in_preview(): void
    {
        $img = $this->image(self::EVIL);
        $id = $this->service()->create(['title' => self::EVIL, 'excerpt' => self::EVIL, 'blocks' => [
            ['type' => 'titre', 'text' => self::EVIL, 'level' => '2'],
            ['type' => 'intro', 'title' => self::EVIL, 'note' => self::EVIL],
            ['type' => 'faits', 'items' => [['label' => self::EVIL, 'value' => '1']]],
            ['type' => 'domaines', 'items' => [['name' => self::EVIL, 'description' => self::EVIL, 'nature' => self::EVIL]]],
            ['type' => 'liste', 'items' => [['text' => self::EVIL]]],
            ['type' => 'appel', 'title' => self::EVIL, 'text' => self::EVIL, 'button_label' => self::EVIL, 'url' => '/contact?a="><script>'],
            ['type' => 'image', 'media_id' => (string) $img, 'caption' => self::EVIL],
            ['type' => 'documents', 'category' => self::EVIL],
        ]], $this->author);
        $this->actingAs($this->author);
        foreach (["/admin/site/pages/$id/apercu", "/admin/site/pages/$id", '/admin/site/pages'] as $url) {
            $html = $this->request('GET', $url)->body();
            $this->assertStringNotContainsString('<script>alert(1)', $html, $url);
            $this->assertStringNotContainsString('onerror=alert', str_replace('onerror=alert(2)&gt;', '', $html), $url);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $url);
        }
        // Réaffichage d'un formulaire (opération d'éditeur) : saisies hostiles échappées.
        $html = $this->request('POST', "/admin/site/pages/$id", ['title' => self::EVIL, 'op' => 'add', 'add_type' => 'liste', 'blocs' => [['type' => 'liste', 'items' => [['text' => self::EVIL]]]]])->body();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_preview_is_noindex_and_requires_login(): void
    {
        $id = $this->page('Brouillon secret');
        $this->app();
        $res = $this->request('GET', "/admin/site/pages/$id/apercu");
        $this->assertSame(302, $res->status());
        $this->assertStringContainsString('/connexion', $res->headers()['Location'] ?? '');
        $this->actingAs($this->createUser('medias@x.test', ['gest_medias']));
        $this->assertSame(403, $this->request('GET', "/admin/site/pages/$id/apercu")->status());
        foreach ([$this->author, $this->publisher] as $u) {
            $this->actingAs($u);
            $res = $this->request('GET', "/admin/site/pages/$id/apercu");
            $this->assertSame(200, $res->status());
            $this->assertSame('noindex, nofollow', $res->headers()['X-Robots-Tag'] ?? null);
            $this->assertStringContainsString('Aperçu — non publié', $res->body());
            $this->assertStringContainsString('Brouillon secret', $res->body());
            $this->assertStringContainsString('class="public"', $res->body(), 'gabarit public');
        }
    }

    public function test_seo_lengths_enforced(): void
    {
        try {
            $this->page('SEO', ['seo_title' => str_repeat('é', 71), 'seo_description' => str_repeat('a', 161), 'canonical_url' => 'javascript:alert(1)', 'excerpt' => str_repeat('a', 501)]);
            $this->fail('accepté');
        } catch (PageInvalid $e) {
            $this->assertSame(['excerpt', 'seo_title', 'seo_description', 'canonical_url'], array_keys($e->errors));
        }
        $id = $this->page('SEO', ['seo_title' => str_repeat('é', 70), 'seo_description' => str_repeat('a', 160), 'canonical_url' => 'https://exemple.ci/page']);
        $this->assertSame(70, mb_strlen((string) $this->db->fetchValue('SELECT seo_title FROM cms_pages WHERE id = ?', [$id])));
        $this->actingAs($this->author);
        $res = $this->request('POST', "/admin/site/pages/$id", ['title' => 'SEO', 'slug' => 'seo', 'seo_title' => str_repeat('x', 71)]);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('70 caractères au maximum (71 saisis)', $res->body());
    }

    public function test_deleting_a_parent_with_children_is_refused(): void
    {
        $s = $this->service();
        $parent = $this->page('Parent');
        $child = $this->page('Enfant', ['parent_id' => (string) $parent]);
        $this->actingAs($this->author);
        $res = $this->request('POST', "/admin/site/pages/$parent/suppression");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('sous-pages', $res->body());
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$child/suppression")->status());
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$parent/suppression")->status());
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
        // Une page déjà publiée ne se supprime pas (même redevenue brouillon) : on l'archive.
        $pub = $this->page('Publiée');
        $s->publish($pub, $this->publisher);
        $s->transition($pub, 'brouillon', $this->publisher);
        $res = $this->request('POST', "/admin/site/pages/$pub/suppression");
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Archivez-la', $res->body());
        $this->assertStringNotContainsString('Supprimer cette page', $this->request('GET', "/admin/site/pages/$pub")->body());
    }

    public function test_every_transition_is_audited(): void
    {
        $s = $this->service();
        $id = $this->page('Audit');
        $s->submit($id, $this->author);
        $s->withdraw($id, $this->author);
        $s->submit($id, $this->author);
        $s->publish($id, $this->publisher);
        $s->unpublish($id, $this->publisher);
        $s->publish($id, $this->publisher, $this->future());
        $s->transition($id, 'brouillon', $this->publisher);
        $s->publish($id, $this->publisher);
        $s->archive($id, $this->publisher);
        $s->restore($id, $this->publisher);
        $rows = $this->db->fetchAll("SELECT action, old_values, new_values, user_id FROM audit_logs WHERE auditable_type = 'cms_pages' AND auditable_id = ? ORDER BY id", [$id]);
        $this->assertSame(['cms.page_created', 'cms.page_submitted', 'cms.page_withdrawn', 'cms.page_submitted', 'cms.page_published', 'cms.page_unpublished',
            'cms.page_scheduled', 'cms.page_unpublished', 'cms.page_published', 'cms.page_archived', 'cms.page_restored'], array_column($rows, 'action'));
        foreach (array_slice($rows, 1) as $r) {
            $new = json_decode((string) $r['new_values'], true);
            $this->assertSame(['status', 'slug'], array_slice(array_keys($new), 0, 2));
            $this->assertArrayNotHasKey('blocks', $new);
            $this->assertArrayHasKey('status', json_decode((string) $r['old_values'], true));
        }
        $this->assertSame($this->publisher, (int) $rows[4]['user_id']);
        $s->update($id, ['title' => 'Audit 2', 'slug' => 'audit', 'blocks' => []], $this->author);
        $upd = json_decode((string) $this->db->fetchValue("SELECT new_values FROM audit_logs WHERE action = 'cms.page_updated' AND auditable_id = ?", [$id]), true);
        $this->assertSame(['title', 'blocks'], $upd['fields'], 'noms de champs seulement, aucun contenu');
        $this->actingAs($this->author);
        $html = $this->request('GET', "/admin/site/pages/$id")->body();
        $this->assertStringContainsString('Historique', $html);
        $this->assertStringContainsString('Soumise pour relecture', $html);
        $this->assertStringContainsString('Archivée', $html);
    }

    public function test_media_reference_contract_with_usages(): void
    {
        $s = $this->service();
        $media = new MediaService($this->db, $this->services['audit'](), $this->services['uploads']());
        foreach (\App\Modules\Cms\Blocks::FIELDS as $type => $fields) {
            foreach ($fields as $name => [$kind]) {
                if ($kind !== 'media') {
                    continue;
                }
                $img = $this->image();
                $block = \App\Modules\Cms\Blocks::blank($type);
                $block[$name] = (string) $img;
                $id = $s->create(['title' => "Contrat $type", 'blocks' => [$block]], $this->author);
                $this->assertSame([$id], array_column($media->usages($img), 'id'), "$type dans blocks");
                // Après reprise : la référence n'est plus que dans published_blocks.
                $s->publish($id, $this->publisher);
                $s->update($id, ['title' => "Contrat $type", 'slug' => (string) $s->get($id)['slug'], 'blocks' => []], $this->author);
                $this->assertSame('[]', $this->db->fetchValue('SELECT blocks FROM cms_pages WHERE id = ?', [$id]));
                $this->assertSame([$id], array_column($media->usages($img), 'id'), "$type dans published_blocks");
            }
        }
    }

    public function test_missing_media_is_refused_and_cited_media_are_share_locked(): void
    {
        try {
            $this->page('Image', ['blocks' => [['type' => 'image', 'media_id' => '999999', 'caption' => '']]]);
            $this->fail('média inexistant accepté');
        } catch (PageInvalid $e) {
            $this->assertStringContainsString("n'existe pas", $e->errors['blocs[0].media_id']);
        }
        try {
            $this->page('Image', ['featured_media_id' => '999999']);
            $this->fail('image à la une inexistante acceptée');
        } catch (PageInvalid $e) {
            $this->assertArrayHasKey('featured_media_id', $e->errors);
        }
        // Une suppression concurrente tient la ligne du média FOR UPDATE : l'enregistrement doit attendre (verrou partagé).
        $img = $this->image();
        $other = Database::connect(self::config(), test: true);
        $other->pdo()->beginTransaction();
        $other->fetchValue('SELECT id FROM cms_media WHERE id = ? FOR UPDATE', [$img]);
        $this->db->query('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $this->page('Image', ['blocks' => [['type' => 'image', 'media_id' => (string) $img, 'caption' => '']]]);
            $this->fail('aucun verrou pris sur le média');
        } catch (\PDOException $e) {
            $this->assertSame(1205, (int) $e->errorInfo[1]);
        } finally {
            $other->pdo()->rollBack();
            $this->db->query('SET SESSION innodb_lock_wait_timeout = 50');
        }
        $this->page('Image', ['blocks' => [['type' => 'image', 'media_id' => (string) $img, 'caption' => '']]]);
        $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'));
    }

    public function test_block_editor_operations_keep_unsaved_input_without_javascript(): void
    {
        $this->actingAs($this->author);
        $post = ['title' => 'Titre non enregistré', 'slug' => '', 'template' => 'liste', 'excerpt' => 'Extrait en cours', 'blocs' => [
            ['type' => 'titre', 'text' => 'Premier', 'level' => '2'],
            ['type' => 'faits', 'items' => [['label' => 'Marins', 'value' => '800'], ['label' => 'Ports', 'value' => '3']]],
        ]];
        $html = $this->request('POST', '/admin/site/pages/nouvelle', $post + ['op' => 'up:1'])->body();
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'), 'une opération n\'enregistre rien');
        $this->assertStringContainsString('value="Titre non enregistré"', $html);
        $this->assertStringContainsString('Extrait en cours', $html);
        $this->assertStringContainsString('name="blocs[0][type]" value="faits"', $html);
        $this->assertStringContainsString('name="blocs[1][type]" value="titre"', $html);
        $this->assertStringContainsString('name="blocs[0][items][1][label]" class="control"', $html);
        $this->assertMatchesRegularExpression('/<option value="liste" selected>/', $html, 'gabarit conservé');

        $html = $this->request('POST', '/admin/site/pages/nouvelle', $post + ['op' => 'down:0'])->body();
        $this->assertStringContainsString('name="blocs[0][type]" value="faits"', $html);
        $html = $this->request('POST', '/admin/site/pages/nouvelle', $post + ['op' => 'del:0'])->body();
        $this->assertStringContainsString('name="blocs[0][type]" value="faits"', $html);
        $this->assertStringNotContainsString('value="Premier"', $html);
        $html = $this->request('POST', '/admin/site/pages/nouvelle', $post + ['op' => 'add', 'add_type' => 'appel'])->body();
        $this->assertStringContainsString('name="blocs[2][type]" value="appel"', $html);
        $this->assertStringContainsString('value="Premier"', $html);
        $this->assertStringContainsString('formaction="/admin/site/pages/nouvelle#bloc-', $html);
        $this->assertMatchesRegularExpression('/value="del:0" formaction="[^"]+" formnovalidate/', $html, 'un bloc vide (champ requis) ne bloque pas les opérations');
        $html = $this->request('POST', '/admin/site/pages/nouvelle', $post + ['op' => 'item-add:1'])->body();
        $this->assertStringContainsString('name="blocs[1][items][2][label]"', $html);
        $html = $this->request('POST', '/admin/site/pages/nouvelle', $post + ['op' => 'item-del:1:0'])->body();
        $this->assertStringNotContainsString('value="Marins"', $html);
        $this->assertStringContainsString('value="Ports"', $html);
        // Erreur affichée sur le champ fautif avec son chemin lisible ; enregistrement final.
        $bad = $post;
        $bad['blocs'][1]['items'][1]['label'] = '';
        $res = $this->request('POST', '/admin/site/pages/nouvelle', $bad + ['op' => 'save']);
        $this->assertSame(422, $res->status());
        $this->assertStringContainsString('Bloc 2 (Chiffres clés), élément 2, Libellé', $res->body());
        $this->assertMatchesRegularExpression('/id="f-blocs\[1\]\[items\]\[1\]\[label\]"[^>]*aria-invalid="true"/', $res->body());
        $this->assertSame(302, $this->request('POST', '/admin/site/pages/nouvelle', $post)->status(), 'Entrée = enregistrer (sans op)');
        $this->assertSame('titre-non-enregistre', $this->db->fetchValue('SELECT slug FROM cms_pages'));
    }

    public function test_permissions_and_csrf(): void
    {
        $id = $this->page('Perm');
        $this->actingAs($this->createUser('medias@x.test', ['gest_medias']));
        foreach (['/admin/site/pages', '/admin/site/pages/nouvelle', "/admin/site/pages/$id"] as $url) {
            $this->assertSame(403, $this->request('GET', $url)->status(), $url);
        }
        $this->assertSame(403, $this->request('POST', "/admin/site/pages/$id/etat", ['to' => 'en_relecture'])->status());
        $this->actingAs($this->author);
        $list = $this->request('GET', '/admin/site/pages')->body();
        $this->assertStringContainsString('href="/admin/site/pages"', $list, 'entrée de menu « Pages »');
        $this->assertStringContainsString('Perm', $list);
        $res = $this->app()->handle(new \App\Core\Http\Request('POST', "/admin/site/pages/$id/etat", [], ['to' => 'en_relecture', '_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertContains($res->status(), [403, 419]);
        $this->assertSame('brouillon', $this->stateOf($id));
        $this->assertSame(302, $this->request('POST', "/admin/site/pages/$id/ordre", ['dir' => 'down'])->status());
        $this->assertSame(404, $this->request('GET', '/admin/site/pages/999999')->status());
    }
}
