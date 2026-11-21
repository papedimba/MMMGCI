<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Settings;
use App\Core\Uploads;
use App\Modules\Cms\ArticleService;
use App\Modules\Cms\BannerService;
use App\Modules\Cms\DocumentService;
use App\Modules\Cms\EventService;
use App\Modules\Cms\GalleryService;
use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Cms\PublishedContent;
use App\Modules\Cms\TestimonialService;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AdminTestCase;

final class PublishedContentTest extends AdminTestCase
{
    private int $author;
    private int $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_gallery_items', 'cms_galleries', 'cms_banners', 'cms_documents', 'cms_events', 'cms_testimonials',
            'cms_article_tag', 'cms_articles'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM cms_media');
        $this->db->query('DELETE FROM users');
        $this->author = $this->createUser('redac@x.test', ['redacteur']);
        $this->publisher = $this->createUser('editeur@x.test', ['editeur']);
    }

    private function pages(): PageService
    {
        $this->app();
        return new PageService($this->db, $this->services['audit'](), $this->services['gate']());
    }

    private function content(): PublishedContent
    {
        $this->app();
        $a = $this->services['audit']();
        $g = $this->services['gate']();
        $pages = new PageService($this->db, $a, $g);
        return new PublishedContent($this->db, $pages, new MenuService($this->db, $a, $g, $pages), new ArticleService($this->db, $a, $g), new EventService($this->db, $a, $g),
            new DocumentService($this->db, $a, $g), new BannerService($this->db, $a, $g), new GalleryService($this->db, $a, $g),
            new TestimonialService($this->db, $a, $g, new Settings($this->db), new Uploads($this->db, sys_get_temp_dir(), sys_get_temp_dir(), 1048576, true)), time(...));
    }

    private function future(string $m): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->modify($m);
    }

    /** @return array{0:int,1:string} */
    private function page(string $title, string $state = 'publie'): array
    {
        $p = $this->pages();
        $id = $p->create(['title' => $title, 'blocks' => [['type' => 'titre', 'text' => $title, 'level' => '2']]], $this->author);
        match ($state) {
            'publie' => $p->publish($id, $this->publisher),
            'archive' => [$p->publish($id, $this->publisher), $p->archive($id, $this->publisher)],
            'futur' => $p->publish($id, $this->publisher, $this->future('+2 days')),
            default => null,
        };
        return [$id, (string) $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$id])];
    }

    private function pdf(): int
    {
        $file = $this->db->insert('files', ['disk' => 'public', 'path' => 'p/' . bin2hex(random_bytes(4)) . '.pdf', 'original_name' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'sha256' => str_repeat('0', 64)]);
        return $this->db->insert('cms_media', ['file_id' => $file, 'title' => 'Doc', 'alt' => null]);
    }

    public function test_published_content_never_returns_draft_archived_or_future_scheduled(): void
    {
        $this->app();
        $audit = $this->services['audit']();
        $gate = $this->services['gate']();
        // Pages.
        [, $live] = $this->page('Page en ligne');
        [, $draft] = $this->page('Page brouillon', 'brouillon');
        [, $arch] = $this->page('Page archivée', 'archive');
        [, $sched] = $this->page('Page programmée', 'futur');
        $c = $this->content();
        $this->assertSame('Page en ligne', $c->page("/$live")['title']);
        foreach ([$draft, $arch, $sched] as $slug) {
            $this->assertNull($c->page("/$slug"), $slug);
        }
        // Articles.
        $art = new ArticleService($this->db, $audit, $gate);
        $mk = fn (string $t): int => $art->create(['title' => $t, 'body' => '<p>' . $t . '</p>'], $this->author);
        $art->transition($mk('Article en ligne'), 'publie', $this->publisher);
        $mk('Article brouillon');
        $x = $mk('Article archivé');
        $art->transition($x, 'publie', $this->publisher);
        $art->transition($x, 'archive', $this->publisher);
        $art->transition($mk('Article programmé'), 'programme', $this->publisher, $this->future('+2 days'));
        // Évènements.
        $ev = new EventService($this->db, $audit, $gate);
        $e = fn (string $t): int => $ev->create(['title' => $t, 'starts_at' => $this->future('+3 days')->format('Y-m-d\TH:i')], $this->author);
        $ev->transition($e('Évènement en ligne'), 'publie', $this->publisher);
        $e('Évènement brouillon');
        $y = $e('Évènement archivé');
        $ev->transition($y, 'publie', $this->publisher);
        $ev->transition($y, 'archive', $this->publisher);
        $ev->transition($e('Évènement programmé'), 'programme', $this->publisher, $this->future('+2 days'));
        // Documents.
        $docs = new DocumentService($this->db, $audit, $gate);
        $d = fn (string $t): int => $docs->create(['title' => $t, 'category' => 'Statuts', 'document_date' => '2026-03-03', 'media_id' => (string) $this->pdf()], $this->author);
        $docs->transition($dl = $d('Document en ligne'), 'publie', $this->publisher, $this->v($docs, $dl));
        $d('Document brouillon');
        $z = $d('Document archivé');
        $docs->transition($z, 'publie', $this->publisher, $this->v($docs, $z));
        $docs->transition($z, 'archive', $this->publisher);

        $c = $this->content();
        $this->assertSame(['Article en ligne'], array_column($c->articles(), 'title'));
        $this->assertSame(['Évènement en ligne'], array_column($c->events(), 'title'));
        $this->assertSame(['Document en ligne'], array_column($c->documents(), 'title'));
        $this->assertSame(['Document en ligne'], array_column($c->documents('Statuts'), 'title'));
        $this->assertNull($c->article('article-brouillon'));
        $this->assertNull($c->article('article-archive'));
        $this->assertNull($c->article('article-programme'));
        $this->assertSame('Article en ligne', $c->article('article-en-ligne')['title']);
        $urls = $c->sitemapUrls();
        sort($urls);
        $this->assertSame(['/actualites/article-en-ligne', '/evenements/evenement-en-ligne', '/' . $live], $urls);
        // Menus : rien d'autre que ce qui est en ligne.
        $m = new MenuService($this->db, $audit, $gate, $this->pages());
        $m->save('header', [['label' => 'Ok', 'page_id' => $this->idOf($live), 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Brouillon', 'page_id' => $this->idOf($draft), 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Archive', 'page_id' => $this->idOf($arch), 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Programmée', 'page_id' => $this->idOf($sched), 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0]], $this->publisher);
        $this->assertSame(['Ok'], array_column($this->content()->menu('header'), 'label'));
    }

    private function v(object $svc, int $id): int
    {
        return (int) $svc->get($id)['content_version'];
    }

    private function idOf(string $slug): int
    {
        return (int) $this->db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$slug]);
    }

    public function test_menu_hides_unpublished_targets(): void
    {
        [$ok, $okSlug] = $this->page('Visible');
        [$dr] = $this->page('Cachée', 'brouillon');
        $this->app();
        $m = new MenuService($this->db, $this->services['audit'](), $this->services['gate'](), $this->pages());
        $m->save('footer', [['label' => 'Visible', 'page_id' => $ok, 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Enfant caché', 'page_id' => $dr, 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 1],
            ['label' => 'Cachée', 'page_id' => $dr, 'url' => '', 'target' => '', 'is_active' => true, 'depth' => 0],
            ['label' => 'Externe', 'page_id' => '', 'url' => 'https://exemple.ci', 'target' => '_blank', 'is_active' => true, 'depth' => 0],
            ['label' => 'Inactif', 'page_id' => '', 'url' => 'https://exemple.ci/x', 'target' => '', 'is_active' => false, 'depth' => 0]], $this->publisher);
        $menu = $this->content()->menu('footer');
        $this->assertSame(['Visible', 'Externe'], array_column($menu, 'label'));
        $this->assertSame('/' . $okSlug, $menu[0]['href']);
        $this->assertSame([], $menu[0]['children']);
        $this->assertSame('noopener noreferrer', $menu[1]['rel']);
        // Le calcul par lot (liveSlugs) suit liveVersion pour chaque état de page.
        $p = $this->pages();
        $ids = [$ok, $dr, $this->page('Archivée', 'archive')[0], $this->page('Plus tard', 'futur')[0]];
        $review = $this->page('Relue')[0];
        $p->update($review, ['title' => 'Relue 2', 'slug' => $this->db->fetchValue('SELECT slug FROM cms_pages WHERE id = ?', [$review]), 'blocks' => [['type' => 'titre', 'text' => 'x', 'level' => '2']]], $this->author);
        $p->transition($review, 'en_relecture', $this->author);
        $ids[] = $review;
        $expected = [];
        foreach ($ids as $id) {
            if ($p->liveVersion($id) !== null) {
                $expected[$id] = $p->liveVersion($id)['slug'];
            }
        }
        $this->assertEquals($expected, $p->liveSlugs($ids));
        $this->assertCount(2, $expected);
    }

    public function test_page_in_review_serves_the_published_version(): void
    {
        [$id, $slug] = $this->page('Titre publié');
        $p = $this->pages();
        $p->update($id, ['title' => 'Titre en chantier', 'slug' => $slug, 'blocks' => [['type' => 'titre', 'text' => 'Brouillon secret', 'level' => '2']]], $this->author);
        $this->assertSame('Titre publié', $this->content()->page("/$slug")['title'], 'brouillon de reprise : version publiée');
        $p->transition($id, 'en_relecture', $this->author);
        $page = $this->content()->page("/$slug");
        $this->assertSame('Titre publié', $page['title']);
        $this->assertStringNotContainsString('Brouillon secret', json_encode($page));
        $this->assertSame('/' . $slug, $page['path']);
        // Jamais publiée : introuvable, même en relecture.
        [$n, $nslug] = $this->page('Jamais publiée', 'brouillon');
        $p->transition($n, 'en_relecture', $this->author);
        $this->assertNull($this->content()->page("/$nslug"));
        // Retirée du site : plus rien.
        $p->unpublish($id, $this->publisher);
        $this->assertNull($this->content()->page("/$slug"));
    }

    public function test_page_paths_and_redirects(): void
    {
        [$parent, $pslug] = $this->page('Mutuelle');
        [$child, $cslug] = $this->page('Histoire');
        $p = $this->pages();
        $this->db->query('UPDATE cms_pages SET parent_id = ? WHERE id = ?', [$parent, $child]);
        $c = $this->content();
        $this->assertSame('Histoire', $c->page("/$cslug")['title']);
        $this->assertSame(['redirect' => "/$cslug"], $c->page("/$pslug/$cslug"), 'chemin hiérarchique : vers l\'adresse plate');
        $this->assertNull($c->page("/inconnu/$cslug"));
        $this->assertNull($c->page("/$cslug/$pslug"));
        foreach (['', '/', '/../etc', '/Majuscule', '/a b', '/' . str_repeat('a', 300)] as $bad) {
            $this->assertNull($c->page($bad), $bad);
        }
        // Changement d'adresse d'une page publiée : l'ancienne adresse redirige.
        $old = $cslug;
        $p->update($child, ['title' => 'Histoire', 'slug' => 'notre-histoire', 'parent_id' => (string) $parent, 'blocks' => [['type' => 'titre', 'text' => 'Histoire', 'level' => '2']]], $this->publisher);
        $c = $this->content();
        $this->assertSame(['redirect' => '/notre-histoire'], $c->page("/$old"));
        $this->assertSame('Histoire', $c->page('/notre-histoire')['title']);
        // Redirection vers une page hors ligne : rien.
        $p->unpublish($child, $this->publisher);
        $this->assertNull($this->content()->page("/$old"));
    }

    public function test_hierarchical_path_needs_live_parents(): void
    {
        [$parent, $pslug] = $this->page('Parent brouillon', 'brouillon');
        [$child, $cslug] = $this->page('Enfant en ligne');
        $this->db->query('UPDATE cms_pages SET parent_id = ? WHERE id = ?', [$parent, $child]);
        $this->assertNull($this->content()->page("/$pslug/$cslug"), 'le slug d\'un brouillon n\'est pas confirmable');
        $this->pages()->publish($parent, $this->publisher);
        $this->assertSame(['redirect' => "/$cslug"], $this->content()->page("/$pslug/$cslug"));
    }

    public function test_banners_galleries_testimonials_and_memoisation(): void
    {
        $this->app();
        $audit = $this->services['audit']();
        $gate = $this->services['gate']();
        $b = new BannerService($this->db, $audit, $gate);
        $live = $b->create(['title' => 'Annonce'], $this->author);
        $b->transition($live, 'publie', $this->publisher, $this->v($b, $live));
        $b->create(['title' => 'Brouillon'], $this->author);
        $g = new GalleryService($this->db, $audit, $gate);
        $album = $g->create(['title' => 'Album ouvert'], $this->author);
        $g->transition($album, 'publie', $this->publisher, $this->v($g, $album));
        $g->create(['title' => 'Album brouillon'], $this->author);
        $c = $this->content();
        $this->assertSame(['Annonce'], array_column($c->banners(), 'text'));
        $this->assertSame(['Album ouvert'], array_column($c->galleries(), 'title'));
        $this->assertSame([], $c->galleryItems($album));
        $this->assertSame([], $c->testimonials(), 'témoignages désactivés par défaut');
        // Mémorisation : un second appel du même objet ne relit pas la base ; un nouvel objet (nouvelle requête) la relit.
        $this->db->query("UPDATE cms_banners SET status = 'archive'");
        $this->assertSame(['Annonce'], array_column($c->banners(), 'text'));
        $this->assertSame([], $this->content()->banners());
    }
}
