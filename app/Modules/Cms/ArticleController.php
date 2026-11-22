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

/** Écrans des articles, catégories et étiquettes (routes/admin.php). Le droit de publier est vérifié par ArticleService::transition. */
final class ArticleController
{
    private const BASE = '/admin/site/articles';
    private const FIELDS = ['title', 'slug', 'body', 'excerpt', 'featured_media_id', 'og_media_id', 'category_id', 'tags', 'author_label', 'seo_title', 'seo_description', 'canonical_url'];
    private const PER_PAGE = 20;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Gate $gate, private ArticleService $articles, private TaxonomyService $taxonomy)
    {
    }

    public function index(Request $r): Response
    {
        $f = [];
        foreach (['status', 'category', 'q'] as $k) {
            $v = $r->input($k, '');
            $f[$k] = is_string($v) ? trim($v) : '';
        }
        $page = max(1, ctype_digit((string) $r->input('page', '1')) ? (int) $r->input('page', '1') : 1);
        $res = $this->articles->search($f, $page, self::PER_PAGE);
        return $this->render($r, 'admin/cms/articles/index', ['title' => 'Articles', 'rows' => $res['rows'], 'filters' => $f, 'page' => $page,
            'pages' => max(1, (int) ceil($res['total'] / self::PER_PAGE)), 'total' => $res['total'], 'categories' => $this->articles->categoryChoices()]);
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, null, [], []);
    }

    public function create(Request $r): Response
    {
        return $this->save($r, null);
    }

    public function editForm(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $a = $this->articles->get($this->id($r));
            return $this->form($r, $a, $this->valuesOf($a), []);
        });
    }

    public function update(Request $r): Response
    {
        return $this->found(fn (): Response => $this->save($r, $this->articles->get($this->id($r))));
    }

    public function transition(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            $to = is_string($r->post('to')) ? $r->post('to') : '';
            try {
                if ($to === 'retirer') {
                    $this->articles->unpublish($id, $this->actor($r));
                } elseif (in_array($to, ContentWorkflow::STATUSES, true)) {
                    $seen = is_string($r->post('version')) && ctype_digit($r->post('version')) ? (int) $r->post('version') : -1;
                    $this->articles->transition($id, $to, $this->actor($r), $to === 'programme' ? $this->scheduledAt($r->post('at')) : null, $seen);
                } else {
                    throw new ContentTransitionInvalid('Action inconnue.');
                }
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'État de l\'article : ' . strtolower(ArticleService::STATUS_LABELS[(string) $this->articles->get($id)['status']]) . '.');
            return Response::redirect(self::BASE . '/' . $id);
        });
    }

    public function delete(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            try {
                $this->articles->delete($id, $this->actor($r));
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'Article supprimé.');
            return Response::redirect(self::BASE);
        });
    }

    public function preview(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $a = $this->articles->get($this->id($r));
            $html = $this->view->render('admin/cms/articles/preview', ['noindex' => true, 'title' => 'Aperçu — ' . $a['title'], 'article' => $a,
                'image' => $this->articles->mediaOf($a['featured_file_id'] === null ? null : (int) $a['featured_file_id'])], 'layouts/public');
            return (new Response($html))->withHeader('X-Robots-Tag', 'noindex, nofollow')->withHeader('Cache-Control', 'no-store');
        });
    }

    public function taxonomyPage(Request $r): Response
    {
        return $this->taxonomyView($r, [], ['name' => '', 'slug' => '']);
    }

    public function taxonomySave(Request $r): Response
    {
        $kind = $this->kind($r);
        $id = $r->attribute('id') === null ? null : $this->id($r);
        $name = is_string($r->post('name')) ? $r->post('name') : '';
        $slug = is_string($r->post('slug')) ? $r->post('slug') : '';
        try {
            $id === null ? $this->taxonomy->create($kind, $name, $slug, $this->actor($r)) : $this->taxonomy->update($kind, $id, $name, $slug, $this->actor($r));
        } catch (PageInvalid $e) {
            return $this->taxonomyView($r, $e->errors, ['name' => $name, 'slug' => $slug], 422, $id);
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Introuvable']), 404);
        }
        $this->session->flash('status', 'Enregistré.');
        return Response::redirect(self::BASE . '/' . $kind);
    }

    public function taxonomyDelete(Request $r): Response
    {
        try {
            $this->taxonomy->delete($this->kind($r), $this->id($r), $this->actor($r));
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Introuvable']), 404);
        } catch (DomainException $e) {
            return $this->taxonomyView($r, [], ['name' => '', 'slug' => ''], 422, null, $e->getMessage());
        }
        $this->session->flash('status', 'Supprimé.');
        return Response::redirect(self::BASE . '/' . $this->kind($r));
    }

    /** @param array<string,string> $errors @param array<string,string> $values */
    private function taxonomyView(Request $r, array $errors, array $values, int $status = 200, ?int $editing = null, ?string $flash = null): Response
    {
        $kind = $this->kind($r);
        return $this->render($r, 'admin/cms/articles/taxonomy', ['title' => $kind === 'categories' ? 'Catégories' : 'Étiquettes', 'kind' => $kind,
            'rows' => $this->taxonomy->all($kind), 'errors' => $errors, 'values' => $values, 'editing' => $editing]
            + ($flash === null ? [] : ['flash' => $flash, 'flashKind' => 'danger']), $status);
    }

    /** @param array<string,mixed>|null $article */
    private function save(Request $r, ?array $article): Response
    {
        // Champ sentinelle final : absent, PHP a tronqué l'envoi (max_input_vars) ; rien n'est enregistré.
        if ($r->post('_form_complete') !== '1') {
            return $this->form($r, $article, $article === null ? [] : $this->valuesOf($article), ['form' => 'Le formulaire est arrivé incomplet (trop de champs). Rien n\'a été enregistré.'], 422);
        }
        $values = [];
        foreach (self::FIELDS as $k) {
            $v = $r->post($k, '');
            $values[$k] = is_string($v) ? $v : '';
        }
        try {
            if ($article === null) {
                $id = $this->articles->create($values, $this->actor($r));
            } else {
                $id = (int) $article['id'];
                $this->articles->update($id, $values, $this->actor($r));
            }
        } catch (PageInvalid $e) {
            return $this->form($r, $article, $values, $e->errors, 422);
        } catch (DomainException $e) {
            return $this->form($r, $article, $values, ['form' => $e->getMessage()], 422);
        }
        $this->session->flash('status', 'Article enregistré.');
        return Response::redirect(self::BASE . '/' . $id);
    }

    /** @param array<string,mixed> $a @return array<string,string> */
    private function valuesOf(array $a): array
    {
        $v = [];
        foreach (self::FIELDS as $k) {
            $v[$k] = (string) ($a[$k] ?? '');
        }
        return $v;
    }

    private function editWithError(Request $r, int $id, string $message): Response
    {
        $a = $this->articles->get($id);
        return $this->form($r, $a, $this->valuesOf($a), ['form' => $message], 422);
    }

    /** @param array<string,mixed>|null $article @param array<string,string> $values @param array<string,string> $errors */
    private function form(Request $r, ?array $article, array $values, array $errors, int $status = 200): Response
    {
        $mayPublish = $this->gate->allows($this->actor($r), 'cms.publish');
        return $this->render($r, 'admin/cms/articles/form', [
            'title' => $article === null ? 'Nouvel article' : 'Modifier l\'article', 'article' => $article, 'values' => $values, 'errors' => $errors,
            'categories' => ['' => '— Aucune —'] + $this->articles->categoryChoices(), 'images' => $this->articles->imageChoices(), 'mayPublish' => $mayPublish,
            'transitions' => $article === null ? [] : ContentWorkflow::transitionsFor((string) $article['status'], $mayPublish),
            'live' => $article === null ? null : $this->articles->liveVersion((int) $article['id']),
            'history' => $article === null ? [] : $this->articles->history((int) $article['id']),
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

    private function kind(Request $r): string
    {
        $k = preg_match('~^/admin/site/articles/(categories|etiquettes)(?:/|$)~', $r->path(), $m) === 1 ? $m[1] : '';
        return TaxonomyService::valid($k) ? $k : throw new NotFound('Introuvable.');
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
