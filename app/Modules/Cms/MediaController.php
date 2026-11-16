<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\UploadError;
use App\Core\View;
use DomainException;

/** Écrans de la médiathèque ; les permissions (cms.media.manage, lecture aussi pour cms.pages.manage) sont posées par routes/admin.php. */
final class MediaController
{
    private const BASE = '/admin/site/medias';

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Gate $gate, private MediaService $media)
    {
    }

    public function index(Request $r): Response
    {
        $query = $this->criteria($r);
        $result = $this->media->search($query, $this->page($r));
        return $this->render($r, 'admin/cms/media/index', ['title' => 'Médias', 'result' => $result, 'query' => $query, 'folders' => $this->media->folders()]);
    }

    /** Fragment HTML pour l'éditeur : images seulement, chaque lien « Choisir » porte id, url publique et texte alternatif. */
    public function picker(Request $r): Response
    {
        $result = $this->media->search(['type' => 'image', 'q' => $r->input('q', '')] + $this->criteria($r), $this->page($r));
        return new Response($this->view->render('admin/cms/media/picker', ['result' => $result, 'q' => (string) $r->input('q', '')], null));
    }

    public function createForm(Request $r): Response
    {
        return $this->form($r, null, []);
    }

    public function create(Request $r): Response
    {
        $meta = $this->meta($r);
        try {
            $file = $r->file('fichier');
            if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new UploadError('Choisissez un fichier à téléverser.');
            }
            $id = $this->media->add($file, $meta, $this->actor($r));
        } catch (DomainException | UploadError $e) {
            return $this->form($r, null, $meta, $e->getMessage(), 422);
        }
        $this->session->flash('status', 'Média ajouté.');
        return Response::redirect(self::BASE . '/' . $id);
    }

    public function editForm(Request $r): Response
    {
        try {
            return $this->editFormInner($r);
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
        }
    }

    private function editFormInner(Request $r): Response
    {
        return $this->form($r, $this->media->get($this->id($r)), []);
    }

    public function update(Request $r): Response
    {
        try {
            return $this->updateInner($r);
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
        }
    }

    private function updateInner(Request $r): Response
    {
        $id = $this->id($r);
        $meta = $this->meta($r);
        try {
            $this->media->update($id, $meta, $this->actor($r));
        } catch (DomainException $e) {
            return $this->form($r, $this->media->get($id), $meta, $e->getMessage(), 422);
        }
        $this->session->flash('status', 'Métadonnées enregistrées.');
        return Response::redirect(self::BASE . '/' . $id);
    }

    public function delete(Request $r): Response
    {
        try {
            return $this->deleteInner($r);
        } catch (NotFound) {
            return new Response($this->view->render('errors/404', ['title' => 'Page introuvable']), 404);
        }
    }

    private function deleteInner(Request $r): Response
    {
        $id = $this->id($r);
        try {
            $this->media->delete($id, $this->actor($r));
        } catch (DomainException $e) {
            return $this->form($r, $this->media->get($id), [], $e->getMessage(), 422);
        }
        $this->session->flash('status', 'Média supprimé.');
        return Response::redirect(self::BASE);
    }

    /** @param array<string,mixed>|null $media @param array<string,string> $values */
    private function form(Request $r, ?array $media, array $values, ?string $error = null, int $status = 200): Response
    {
        $usages = $media === null ? [] : $this->media->usages((int) $media['id']);
        return $this->render($r, 'admin/cms/media/form', [
            'title' => $media === null ? 'Ajouter un média' : 'Modifier le média', 'media' => $media, 'usages' => $usages, 'error' => $error,
            'values' => $values !== [] ? $values : ($media === null ? [] : array_map(static fn ($v): string => (string) $v, array_intersect_key($media, array_flip(['title', 'alt', 'caption', 'credit', 'folder'])))),
            'folders' => $this->media->folders(),
        ], $status);
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

    /** @return array<string,string> */
    private function meta(Request $r): array
    {
        $out = [];
        foreach (['title', 'alt', 'caption', 'credit', 'folder'] as $k) {
            $v = $r->post($k, '');
            $out[$k] = is_string($v) ? $v : '';
        }
        return $out;
    }

    /** @return array<string,string> */
    private function criteria(Request $r): array
    {
        $out = [];
        foreach (['type', 'folder', 'q'] as $k) {
            $v = $r->input($k);
            if (is_string($v) && $v !== '') {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function page(Request $r): int
    {
        $p = $r->input('page');
        return is_string($p) && ctype_digit($p) ? max(1, (int) $p) : 1;
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
