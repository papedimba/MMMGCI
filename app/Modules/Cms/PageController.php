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
use DateTimeImmutable;
use DateTimeZone;
use DomainException;

/**
 * Écrans des pages (routes/admin.php : cms.pages.manage ou cms.publish). Le droit de publier est vérifié par PageService::transition.
 * Éditeur de blocs sans JS : chaque bouton « op » renvoie le formulaire réaffiché avec la modification, sans rien enregistrer.
 */
final class PageController
{
    private const BASE = '/admin/site/pages';
    private const FIELDS = ['title', 'slug', 'template', 'parent_id', 'excerpt', 'featured_media_id', 'og_media_id', 'seo_title', 'seo_description', 'canonical_url'];

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Gate $gate, private PageService $pages)
    {
    }

    public function index(Request $r): Response
    {
        return $this->render($r, 'admin/cms/pages/index', ['title' => 'Pages', 'rows' => $this->pages->tree()]);
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, null, ['template' => 'standard'], [], []);
    }

    public function create(Request $r): Response
    {
        return $this->save($r, null);
    }

    public function editForm(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $p = $this->pages->get($this->id($r));
            $values = [];
            foreach (self::FIELDS as $k) {
                $values[$k] = (string) ($p[$k] ?? '');
            }
            return $this->form($r, $p, $values, Blocks::fromRequest($p['blocks']), []);
        });
    }

    public function update(Request $r): Response
    {
        return $this->found(fn (): Response => $this->save($r, $this->pages->get($this->id($r))));
    }

    public function transition(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            $to = (string) (is_string($r->post('to')) ? $r->post('to') : '');
            try {
                if ($to === 'retirer') {
                    $this->pages->unpublish($id, $this->actor($r));
                } elseif (in_array($to, ContentWorkflow::STATUSES, true)) {
                    // Version affichée au publicateur ; absente ou illisible = -1, donc refusée pour publier/programmer.
                    $seen = is_string($r->post('version')) && ctype_digit($r->post('version')) ? (int) $r->post('version') : -1;
                    $this->pages->transition($id, $to, $this->actor($r), $to === 'programme' ? $this->scheduledAt($r->post('at')) : null, $seen);
                } else {
                    throw new ContentTransitionInvalid('Action inconnue.');
                }
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'État de la page : ' . strtolower(PageService::STATUS_LABELS[(string) $this->pages->get($id)['status']]) . '.');
            return Response::redirect(self::BASE . '/' . $id);
        });
    }

    public function reorder(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            try {
                $this->pages->reorder($this->id($r), $r->post('dir') === 'up' ? -1 : 1, $this->actor($r));
            } catch (PageInvalid $e) {
                return $this->render($r, 'admin/cms/pages/index', ['title' => 'Pages', 'rows' => $this->pages->tree(), 'flash' => implode(' ', $e->errors), 'flashKind' => 'danger'], 422);
            } catch (DomainException $e) {
                return $this->render($r, 'admin/cms/pages/index', ['title' => 'Pages', 'rows' => $this->pages->tree(), 'flash' => $e->getMessage(), 'flashKind' => 'danger'], 422);
            }
            return Response::redirect(self::BASE);
        });
    }

    public function delete(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            try {
                $this->pages->delete($id, $this->actor($r));
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'Page supprimée.');
            return Response::redirect(self::BASE);
        });
    }

    /** Aperçu de la copie de travail dans le gabarit public ; jamais indexé. */
    public function preview(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $p = $this->pages->get($this->id($r));
            $html = $this->view->render('admin/cms/pages/preview', ['noindex' => true, 'title' => 'Aperçu — ' . $p['title'], 'page' => $p, 'refs' => $this->pages->resolve($p['blocks'])], 'layouts/public');
            return (new Response($html))->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Cache-Control', 'no-store');
        });
    }

    /** @param array<string,mixed>|null $page */
    private function save(Request $r, ?array $page): Response
    {
        // Champ sentinelle placé en dernier : absent, PHP a tronqué l'envoi (max_input_vars) ; rien n'est enregistré ni réaffiché tronqué.
        if ($r->post('_form_complete') !== '1') {
            $values = ['template' => 'standard'];
            foreach ($page === null ? [] : self::FIELDS as $k) {
                $values[$k] = (string) ($page[$k] ?? '');
            }
            return $this->form($r, $page, $values, $page === null ? [] : Blocks::fromRequest($page['blocks']), ['form' => Blocks::TOO_LONG . ' Rien n\'a été enregistré.'], 422);
        }
        $values = [];
        foreach (self::FIELDS as $k) {
            $v = $r->post($k, '');
            $values[$k] = is_string($v) ? $v : '';
        }
        $blocks = Blocks::fromRequest($r->post('blocs'));
        $op = is_string($r->post('op')) ? (string) $r->post('op') : '';
        if ($op !== '' && $op !== 'save') {
            [$blocks, $error] = Blocks::apply($blocks, $op, is_string($r->post('add_type')) ? (string) $r->post('add_type') : '');
            return $this->form($r, $page, $values, $blocks, $error === null ? [] : ['blocs' => $error]);
        }
        $data = $values + ['blocks' => $r->post('blocs', [])];
        try {
            if ($page === null) {
                $id = $this->pages->create($data, $this->actor($r));
            } else {
                $id = (int) $page['id'];
                $this->pages->update($id, $data, $this->actor($r));
            }
        } catch (PageInvalid $e) {
            return $this->form($r, $page, $values, $blocks, $e->errors, 422);
        } catch (DomainException $e) {
            return $this->form($r, $page, $values, $blocks, ['form' => $e->getMessage()], 422);
        }
        $this->session->flash('status', 'Page enregistrée.');
        return Response::redirect(self::BASE . '/' . $id);
    }

    private function editWithError(Request $r, int $id, string $message): Response
    {
        $p = $this->pages->get($id);
        $values = [];
        foreach (self::FIELDS as $k) {
            $values[$k] = (string) ($p[$k] ?? '');
        }
        return $this->form($r, $p, $values, Blocks::fromRequest($p['blocks']), ['form' => $message], 422);
    }

    /**
     * @param array<string,mixed>|null $page @param array<string,string> $values @param list<array<string,mixed>> $blocks @param array<string,string> $errors
     */
    private function form(Request $r, ?array $page, array $values, array $blocks, array $errors, int $status = 200): Response
    {
        $tree = $this->pages->tree();
        $exclude = $page === null ? [] : $this->pages->subtree((int) $page['id']);
        $parents = ['' => '— Aucune (page de premier niveau) —'];
        $all = ['' => '— Aucune —'];
        foreach ($tree as $t) {
            $label = str_repeat('— ', (int) $t['depth']) . $t['title'];
            $all[(int) $t['id']] = $label;
            if (!in_array((int) $t['id'], $exclude, true)) {
                $parents[(int) $t['id']] = $label;
            }
        }
        $actor = $this->actor($r);
        $mayPublish = $this->gate->allows($actor, 'cms.publish');
        return $this->render($r, 'admin/cms/pages/form', [
            'title' => $page === null ? 'Nouvelle page' : 'Modifier la page', 'page' => $page, 'values' => $values, 'blocks' => $blocks, 'errors' => $errors,
            'parents' => $parents, 'pageChoices' => $all, 'images' => $this->pages->imageChoices(), 'mayPublish' => $mayPublish,
            'transitions' => $page === null ? [] : ContentWorkflow::transitionsFor((string) $page['status'], $mayPublish),
            'live' => $page === null ? null : $this->pages->liveVersion((int) $page['id']),
            'history' => $page === null ? [] : $this->pages->history((int) $page['id']),
        ], $status);
    }

    private function scheduledAt(mixed $raw): ?DateTimeImmutable
    {
        $at = is_string($raw) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $raw, new DateTimeZone('Africa/Abidjan')) : false;
        return $at === false ? null : $at;
    }

    private function found(callable $fn): Response
    {
        try {
            return $fn();
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
        }
    }

    /** @param array<string,mixed> $data */
    private function render(Request $r, string $view, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        return new Response($this->view->render($view, $data + [
            'user' => $user, 'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $this->session->pullFlash('status'),
        ], 'layouts/admin'), $status);
    }

    private function id(Request $r): int
    {
        return (int) $r->attribute('id');
    }

    private function actor(Request $r): int
    {
        return (int) $r->attribute('user')['id'];
    }
}
