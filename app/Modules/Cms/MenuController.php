<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use DomainException;

/**
 * Éditeur de menus (routes/admin.php : cms.publish ; le service exige aussi cms.pages.manage). Sans JS : chaque bouton « op »
 * (up:i, down:i, del:i, add) renvoie le formulaire modifié sans rien enregistrer ; seul « save » écrit.
 */
final class MenuController
{
    private const BASE = '/admin/site/menus';
    private const MAX_FIELDS = 400;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Gate $gate, private MenuService $menus, private PageService $pages)
    {
    }

    public function show(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $loc = $this->location($r);
            return $this->form($r, $loc, $this->saved($loc), []);
        });
    }

    /** @return list<array<string,mixed>> */
    private function saved(string $loc): array
    {
        $rows = [];
        foreach ($this->menus->items($loc) as $top) {
            foreach ([$top, ...$top['children']] as $n => $it) {
                $rows[] = ['label' => (string) $it['label'], 'kind' => $it['page_id'] === null ? 'url' : 'page', 'page_id' => (string) ($it['page_id'] ?? ''),
                    'url' => (string) ($it['url'] ?? ''), 'target' => $it['target'] === '_blank' ? '_blank' : '', 'is_active' => (bool) $it['is_active'],
                    'depth' => $n === 0 ? '0' : '1'];
            }
        }
        return $rows;
    }

    public function save(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $loc = $this->location($r);
            // Sentinelle finale absente : envoi tronqué (max_input_vars) ; on ne remplace pas le menu par une version amputée.
            if ($r->post('_form_complete') !== '1') {
                return $this->form($r, $loc, $this->saved($loc), ['menu' => Blocks::TOO_LONG . ' Rien n\'a été enregistré.'], 422);
            }
            $rows = $this->fromRequest($r->post('items'));
            $op = is_string($r->post('op')) ? $r->post('op') : '';
            if ($op !== '' && $op !== 'save') {
                [$rows, $error] = $this->apply($rows, $op);
                return $this->form($r, $loc, $rows, $error === null ? [] : ['menu' => $error]);
            }
            try {
                $this->menus->save($loc, array_map(static fn (array $x): array => [
                    'label' => $x['label'], 'page_id' => $x['kind'] === 'page' ? $x['page_id'] : '', 'url' => $x['kind'] === 'page' ? '' : $x['url'],
                    'target' => $x['target'], 'is_active' => $x['is_active'], 'depth' => $x['depth'],
                ], $rows), (int) $r->attribute('user')['id']);
            } catch (MenuInvalid $e) {
                return $this->form($r, $loc, $rows, $e->errors, 422);
            } catch (DomainException $e) {
                return $this->form($r, $loc, $rows, ['menu' => $e->getMessage()], 422);
            }
            $this->session->flash('status', 'Menu enregistré : il est en ligne.');
            return Response::redirect(self::BASE . '/' . $loc);
        });
    }

    /** @return list<array<string,mixed>> lignes saisies, valeurs en texte (rien n'est validé ici) */
    private function fromRequest(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $it) {
            $s = static fn (string $k): string => is_array($it) && is_scalar($it[$k] ?? null) ? (string) $it[$k] : '';
            $out[] = ['label' => $s('label'), 'kind' => $s('kind') === 'url' ? 'url' : 'page', 'page_id' => $s('page_id'), 'url' => $s('url'),
                'target' => $s('target'), 'is_active' => $s('is_active') === '1', 'depth' => $s('depth') === '1' ? '1' : '0'];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows @return array{0:list<array<string,mixed>>,1:?string} */
    private function apply(array $rows, string $op): array
    {
        $parts = explode(':', $op);
        $i = isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : -1;
        $n = count($rows);
        if ($parts[0] === 'add') {
            if ($n >= MenuService::MAX_ITEMS || ($n + 1) * 8 > self::MAX_FIELDS) {
                return [$rows, 'Un menu compte ' . MenuService::MAX_ITEMS . ' éléments au maximum.'];
            }
            $rows[] = ['label' => '', 'kind' => 'page', 'page_id' => '', 'url' => '', 'target' => '', 'is_active' => true, 'depth' => '0'];
        } elseif ($i >= 0 && $i < $n) {
            if ($parts[0] === 'del') {
                array_splice($rows, $i, 1);
            } elseif (in_array($parts[0], ['up', 'down'], true)) {
                $j = $parts[0] === 'up' ? $i - 1 : $i + 1;
                if (isset($rows[$j])) {
                    [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
                }
            }
        }
        return [$rows, null];
    }

    /** @param list<array<string,mixed>> $rows @param array<string,string> $errors */
    private function form(Request $r, string $loc, array $rows, array $errors, int $status = 200): Response
    {
        $labels = PageService::STATUS_LABELS;
        $choices = ['' => '— Choisir une page —'];
        foreach ($this->pages->tree() as $p) {
            $choices[(int) $p['id']] = str_repeat('— ', (int) $p['depth']) . $p['title'] . ' (' . ($labels[$p['status']] ?? $p['status']) . ($p['live'] && $p['status'] !== 'publie' ? ', version en ligne' : '') . ')';
        }
foreach ($rows as &$row) { // avertissement « page non publiée » recalculé à chaque affichage
            $row['live'] = $row['kind'] === 'page' && ctype_digit((string) $row['page_id']) && $row['page_id'] !== '' ? $this->pages->liveVersion((int) $row['page_id']) !== null : null;
        }
        unset($row);
        $user = $r->attribute('user');
        return new Response($this->view->render('admin/cms/menus/edit', [
            'title' => 'Menus du site', 'location' => $loc, 'rows' => $rows, 'errors' => $errors, 'pageChoices' => $choices,
            'user' => $user, 'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $this->session->pullFlash('status'),
        ], 'layouts/admin'), $status);
    }

    private function location(Request $r): string
    {
        $loc = (string) $r->attribute('location');
        return isset(MenuService::LOCATIONS[$loc]) ? $loc : throw new NotFound('Menu introuvable.');
    }

    private function found(callable $fn): Response
    {
        try {
            return $fn();
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
        }
    }
}
