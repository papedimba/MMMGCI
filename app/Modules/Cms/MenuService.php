<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Gate;
use App\Core\NotFound;
use DomainException;
use PDOException;

/**
 * Menus du site (emplacements header et footer, créés par la migration 0016). Deux niveaux au plus : un élément « sous-niveau » dépend du
 * dernier élément de premier niveau qui le précède dans l'ordre. L'enregistrement remplace tout le menu et est publié immédiatement.
 */
final class MenuService
{
    public const LOCATIONS = ['header' => 'En-tête', 'footer' => 'Pied de page'];
    public const MAX_TOP = ['header' => 7, 'footer' => 12];
    public const MAX_ITEMS = 40;
    public const MAX_LABEL = 60;

    public function __construct(private Database $db, private Audit $audit, private Gate $gate, private PageService $pages)
    {
    }

    /**
     * Arbre d'administration : tous les éléments, actifs ou non ; target_live = la page ciblée a une version en ligne (null pour une URL).
     * @return list<array<string,mixed>> éléments de premier niveau, chacun avec 'children'
     */
    public function items(string $location): array
    {
        $out = [];
        foreach ($this->rows($location, false) as $row) {
            $row['target_live'] = $row['page_id'] === null ? null : $this->pages->liveVersion((int) $row['page_id']) !== null;
            $row['children'] = [];
            if ($row['parent_id'] === null) {
                $out[(int) $row['id']] = $row;
            } elseif (isset($out[(int) $row['parent_id']])) {
                $out[(int) $row['parent_id']]['children'][] = $row;
            }
        }
        return array_values($out);
    }

    /**
     * Menu tel que le public le voit : éléments actifs seulement ; un élément dont la page n'a pas de version en ligne est écarté AVEC ses
     * sous-éléments (règle volontairement simple : pas de promotion) ; un parent inactif écarte aussi ses enfants ; l'adresse d'une page est
     * son chemin public actuel (/slug) ; une adresse externe est gardée telle quelle.
     * @return list<array{label:string,href:string,target:string,rel:string,children:list<array<string,string>>}>
     */
    public function publicItems(string $location): array
    {
        $out = [];
        $rows = $this->rows($location, true);
        $slugs = $this->pages->liveSlugs(array_values(array_unique(array_map(static fn (array $r): int => (int) $r['page_id'], array_filter($rows, static fn (array $r): bool => $r['page_id'] !== null)))));
        foreach ($rows as $row) {
            $href = $row['url'];
            if ($row['page_id'] !== null) {
                $href = isset($slugs[(int) $row['page_id']]) ? '/' . $slugs[(int) $row['page_id']] : null;
            }
            $parent = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            if ($href === null || ($parent !== null && !isset($out[$parent]))) {
                continue;
            }
            $item = ['label' => (string) $row['label'], 'href' => (string) $href, 'target' => (string) $row['target'],
                'rel' => $row['target'] === '_blank' ? 'noopener noreferrer' : '', 'children' => []];
            if ($parent === null) {
                $out[(int) $row['id']] = $item;
            } else {
                $out[$parent]['children'][] = $item;
            }
        }
        return array_values($out);
    }

    /**
     * Remplace tout le menu. $items : label, page_id (ou null/''), url, target, is_active, depth (0 premier niveau, 1 sous-niveau).
     * @param list<array<string,mixed>> $items
     */
    public function save(string $location, array $items, int $actor): void
    {
        if (!$this->gate->allows($actor, 'cms.pages.manage') || !$this->gate->allows($actor, 'cms.publish')) {
            throw new DomainException('Enregistrer un menu demande les droits de rédaction et de publication.');
        }
        $this->db->atomic(function () use ($location, $items, $actor): void {
            $menu = $this->db->fetch('SELECT id FROM cms_menus WHERE location = ? FOR UPDATE', [$location]) ?? throw new NotFound('Menu introuvable.');
            $menuId = (int) $menu['id'];
            $rows = $this->validate($location, array_values($items));
            $before = (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_menu_items WHERE menu_id = ?', [$menuId]);
            try {
                $this->db->query('DELETE FROM cms_menu_items WHERE menu_id = ?', [$menuId]);
                $top = null;
                foreach ($rows as $i => $row) {
                    $id = $this->db->insert('cms_menu_items', ['menu_id' => $menuId, 'parent_id' => $row['depth'] === 0 ? null : $top, 'label' => $row['label'],
                        'page_id' => $row['page_id'], 'url' => $row['url'], 'target' => $row['target'], 'sort_order' => $i, 'is_active' => $row['is_active']]);
                    $top = $row['depth'] === 0 ? $id : $top;
                }
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1452) { // page supprimée entre-temps
                    throw new MenuInvalid(['menu' => 'Une page choisie n\'existe plus : rechargez l\'écran.']);
                }
                throw $e;
            }
            $this->audit->log($actor, 'cms.menu_saved', 'cms_menus', $menuId, ['items' => $before], ['location' => $location, 'items' => count($rows)]);
        });
    }

    /**
     * Chemin interne du semis du site (bin/seed-site.php) : sans droits d'utilisateur, mêmes validations, JAMAIS d'écrasement :
     * n'insère que dans un menu VIDE (retourne false sinon). Journal : cms.menu_seeded.
     * @param list<array<string,mixed>> $items
     */
    public function seed(string $location, array $items, int $actor): bool
    {
        return $this->db->atomic(function () use ($location, $items, $actor): bool {
            $menu = $this->db->fetch('SELECT id FROM cms_menus WHERE location = ? FOR UPDATE', [$location]) ?? throw new NotFound('Menu introuvable.');
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_menu_items WHERE menu_id = ?', [(int) $menu['id']]) > 0) {
                return false;
            }
            $rows = $this->validate($location, array_values($items));
            $top = null;
            foreach ($rows as $i => $row) {
                $id = $this->db->insert('cms_menu_items', ['menu_id' => (int) $menu['id'], 'parent_id' => $row['depth'] === 0 ? null : $top, 'label' => $row['label'],
                    'page_id' => $row['page_id'], 'url' => $row['url'], 'target' => $row['target'], 'sort_order' => $i, 'is_active' => $row['is_active']]);
                $top = $row['depth'] === 0 ? $id : $top;
            }
            $this->audit->log($actor, 'cms.menu_seeded', 'cms_menus', (int) $menu['id'], ['items' => 0], ['location' => $location, 'items' => count($rows)]);
            return true;
        });
    }

    /** @param list<array<string,mixed>> $items @return list<array<string,mixed>> */
    private function validate(string $location, array $items): array
    {
        $errors = [];
        $rows = [];
        $topCount = 0;
        if (count($items) > self::MAX_ITEMS) {
            $errors['menu'] = 'Un menu compte ' . self::MAX_ITEMS . ' éléments au maximum.';
        }
        foreach ($items as $i => $it) {
            $k = "items[$i]";
            $str = static fn (string $f): string => is_scalar($it[$f] ?? null) ? trim((string) $it[$f]) : '';
            $label = Blocks::text($str('label'), false);
            if ($label === '' || mb_strlen($label) > self::MAX_LABEL) {
                $errors[$k . '.label'] = 'Le libellé fait de 1 à ' . self::MAX_LABEL . ' caractères.';
            }
            $pageRaw = $str('page_id');
            $url = $str('url');
            $pageId = null;
            if (($pageRaw !== '') === ($url !== '')) {
                $errors[$k . '.target'] = 'Choisissez une page ou saisissez une adresse (l\'un des deux).';
            } elseif ($pageRaw !== '') {
                $pageId = ctype_digit($pageRaw) ? (int) $pageRaw : 0;
                if ($pageId === 0 || $this->db->fetchValue('SELECT id FROM cms_pages WHERE id = ?', [$pageId]) === null) {
                    $errors[$k . '.target'] = 'Cette page n\'existe pas.';
                }
            } elseif (mb_strlen($url) > 255 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || !Blocks::isSafeUrl($url)) {
                $errors[$k . '.target'] = 'Adresse refusée : https://…, mailto:…, tel:… ou chemin du site (/contact).';
            }
            $target = $str('target');
            if ($target !== '' && $target !== '_blank') {
                $errors[$k . '.target_window'] = 'Ouverture inconnue.';
            }
            $depth = (int) ($it['depth'] ?? 0);
            if ($depth !== 0 && $depth !== 1) {
                $errors[$k . '.depth'] = 'Un menu a deux niveaux au plus.';
            } elseif ($depth === 1 && $topCount === 0) {
                $errors[$k . '.depth'] = 'Un sous-élément doit suivre un élément de premier niveau.';
            }
            $topCount += $depth === 0 ? 1 : 0;
            $rows[] = ['label' => $label, 'page_id' => $pageId, 'url' => $pageId === null ? $url : null, 'target' => $target === '' ? '_self' : $target,
                'is_active' => empty($it['is_active']) ? 0 : 1, 'depth' => $depth === 1 ? 1 : 0];
        }
        if ($topCount > self::MAX_TOP[$location]) {
            $errors['menu'] = 'Le menu « ' . self::LOCATIONS[$location] . ' » compte ' . self::MAX_TOP[$location] . ' éléments de premier niveau au maximum.';
        }
        if ($errors !== []) {
            throw new MenuInvalid($errors);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $location, bool $activeOnly): array
    {
        return $this->db->fetchAll('SELECT i.* FROM cms_menu_items i JOIN cms_menus m ON m.id = i.menu_id WHERE m.location = ?'
            . ($activeOnly ? ' AND i.is_active = 1' : '') . ' ORDER BY i.sort_order, i.id', [$location]);
    }
}
