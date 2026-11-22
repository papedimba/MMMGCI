<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Cms\MenuService;
use App\Modules\Cms\PageService;
use App\Modules\Site\ContentSeed;
use Tests\Support\AdminTestCase;

/** Tout texte semé figure dans le cahier des charges (sauf libellés d'interface listés) : une phrase inventée fait échouer le test. */
final class ContentFidelityTest extends AdminTestCase
{
    /** Libellés d'interface et titres de navigation qui ne sont pas des phrases du cahier (à garder courte). */
    private const ALLOWLIST = ['Contact', 'Missions et objectifs', 'Nous écrire', 'Aller à la page Contact', 'Voir les conditions d’adhésion',
        'Pièces du dossier d’adhésion', 'Livret maritime disponible', 'Ayants droit à déclarer', 'Objectif spécifique']; // singulier du titre de l'art. 2 (un seul objectif cité)

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(str_replace(['’', '‘', '«', '»'], ["'", "'", '', ''], $s))));
    }

    public function test_every_seeded_text_appears_in_the_charter(): void
    {
        foreach (['cms_menu_items', 'cms_page_redirects', 'cms_articles', 'cms_events', 'cms_galleries', 'cms_documents'] as $t) {
            $this->db->query("DELETE FROM $t");
        }
        $this->db->query('UPDATE cms_pages SET parent_id = NULL');
        $this->db->query('DELETE FROM cms_pages');
        $this->db->query('DELETE FROM users');
        $admin = $this->createUser('admin@x.test', ['admin_general']);
        $this->app();
        $audit = $this->services['audit']();
        $gate = $this->services['gate']();
        $pages = new PageService($this->db, $audit, $gate);
        (new ContentSeed($this->db, $pages, new MenuService($this->db, $audit, $gate, $pages), $gate))->run($admin);

        $charter = self::norm((string) file_get_contents(dirname(__DIR__, 2) . '/docs/conception/cahier-des-charges-2026-2027.txt'));
        $allow = array_map([self::class, 'norm'], self::ALLOWLIST);
        $texts = [];
        foreach ($this->db->fetchAll('SELECT id FROM cms_pages') as $r) {
            $p = $pages->get((int) $r['id']);
            array_push($texts, $p['title'], (string) $p['excerpt']);
            foreach ($p['blocks'] as $b) {
                match ($b['type']) {
                    'texte' => array_push($texts, ...preg_split('/\n+/', trim((string) preg_replace('~</?(?:p|li|ul|ol|h[2-4]|br|blockquote)\b[^>]*>~i', "\n", $b['html'])))),
                    'titre' => $texts[] = $b['text'],
                    'liste' => array_push($texts, ...array_column($b['items'], 'text')),
                    'domaines' => array_push($texts, ...array_column($b['items'], 'name'), ...array_column($b['items'], 'nature'), ...array_column($b['items'], 'description')),
                    'appel' => array_push($texts, $b['title'], $b['text'], $b['button_label']),
                    'dossier' => array_push($texts, $b['title'], $b['intro'], ...array_column($b['situations'], 'label'), ...array_column($b['pieces'], 'label')),
                    default => null,
                };
            }
        }
        $checked = 0;
        foreach ($texts as $t) {
            $n = self::norm(html_entity_decode(strip_tags($t)));
            if ($n === '') {
                continue;
            }
            $checked++;
            $this->assertTrue(in_array($n, $allow, true) || str_contains($charter, $n), "Texte absent du cahier des charges : « $t »");
        }
        $this->assertGreaterThan(60, $checked);
    }

    public function test_menu_seed_is_only_reachable_from_content_seed(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['app', 'routes'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->getExtension() === 'php' && preg_match('/->seed\(|function seed\(/', (string) file_get_contents($f->getPathname())) === 1) {
                    $files[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
        }
        sort($files);
        $this->assertSame(['app/Modules/Cms/MenuService.php', 'app/Modules/Site/ContentSeed.php'], $files);
    }
}
