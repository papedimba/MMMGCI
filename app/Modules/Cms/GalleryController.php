<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use DomainException;

/** Écrans des galeries (/admin/site/galeries) : album, puis éléments en petits formulaires (ajout, légende, Monter/Descendre, retrait), tous sans JavaScript. */
final class GalleryController extends SimpleScreen
{
    protected const SUBTITLE = 'Albums de photos du site. Chaque image vient de la médiathèque et doit avoir un texte alternatif.';

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private GalleryService $galleries)
    {
        parent::__construct($view, $csrf, $session, $gate, $galleries);
    }

    protected function base(): string
    {
        return '/admin/site/galeries';
    }

    protected function names(): array
    {
        return ['galleries', 'album', 'galeries'];
    }

    protected function fields(): array
    {
        return ['title', 'slug', 'description'];
    }

    protected function columns(): array
    {
        $base = $this->base();
        return [['title', 'Album', '', false, static fn (array $a): string => '<a href="' . e($base . '/' . $a['id']) . '">' . e($a['title']) . '</a> <span class="page-slug">/' . e($a['slug']) . '</span>']];
    }

    protected function persist(Request $r, ?array $row, array $values): int
    {
        if ($row === null) {
            return $this->galleries->create($values, $this->actor($r));
        }
        $this->galleries->update((int) $row['id'], $values, $this->actor($r));
        return (int) $row['id'];
    }

    protected function formData(?array $row): array
    {
        return ['items' => $row === null ? [] : $this->galleries->items((int) $row['id']), 'images' => ['' => '— Choisir une image —'] + $this->galleries->mediaChoices(GalleryService::IMAGES),
            'itemErrors' => []];
    }

    /** Actions sur les éléments : ajouter, légender, monter/descendre, retirer. */
    public function item(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            $item = $r->attribute('item') === null ? null : (int) $r->attribute('item');
            $op = $item === null ? 'ajout' : basename($r->path()); // dernier segment : legende, monter, descendre, retrait
            $data = [];
            foreach (['media_id', 'caption', 'credit'] as $k) {
                $v = $r->post($k, '');
                $data[$k] = is_string($v) ? $v : '';
            }
            try {
                match ($op) {
                    'ajout' => $this->galleries->addItem($id, $data, $this->actor($r)),
                    'legende' => $this->galleries->updateItem($id, (int) $item, $data, $this->actor($r)),
                    'monter' => $this->galleries->moveItem($id, (int) $item, -1, $this->actor($r)),
                    'descendre' => $this->galleries->moveItem($id, (int) $item, 1, $this->actor($r)),
                    'retrait' => $this->galleries->removeItem($id, (int) $item, $this->actor($r)),
                    default => throw new DomainException('Action inconnue.'),
                };
            } catch (PageInvalid $e) {
                $row = $this->galleries->get($id);
                return $this->form($r, $row, $this->valuesOf($row), ['form' => implode(' ', $e->errors)], 422);
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'Album mis à jour.');
            return Response::redirect($this->base() . '/' . $id . '#elements');
        });
    }
}
