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

/** Écrans des contenus à circuit simple (documents, galeries, bannières, témoignages) : liste, formulaire, état, suppression. */
abstract class SimpleScreen extends CmsScreen
{
    private const KINDS = ['brouillon' => 'neutral', 'publie' => 'ok', 'archive' => 'neutral'];
    protected const SUBTITLE = '';

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, protected SimpleContent $content)
    {
        parent::__construct($view, $csrf, $session, $gate);
    }

    abstract protected function base(): string;

    /** Dossier de vues, nom au singulier (« Nouveau … »), nom du type pour les messages. @return array{0:string,1:string,2:string} */
    abstract protected function names(): array;

    /** @return list<string> champs texte lus dans la requête */
    abstract protected function fields(): array;

    /** @return list<array<int,mixed>> colonnes du tableau (hors état) */
    abstract protected function columns(): array;

    /** @param array<string,string> $values @param array<string,mixed> $request @param array<string,mixed>|null $row @return int identifiant */
    abstract protected function persist(Request $r, ?array $row, array $values): int;

    /** Données propres au type pour le formulaire. @param array<string,mixed>|null $row @return array<string,mixed> */
    protected function formData(?array $row): array
    {
        return [];
    }

    /** Valeurs affichables dans le formulaire à partir de la ligne. @param array<string,mixed> $row @return array<string,mixed> */
    protected function prefill(array $row): array
    {
        return $row;
    }

    /** HTML de confiance affiché au-dessus de la liste. */
    protected function notice(): string
    {
        return '';
    }

    public function index(Request $r): Response
    {
        [, $label] = $this->names();
        $labels = SimpleContent::STATUS_LABELS;
        $columns = [...$this->columns(), ['status', 'État', '', false, static fn (array $a): string => ui('badge', ['kind' => self::KINDS[$a['status']] ?? 'neutral', 'label' => $labels[$a['status']] ?? $a['status']]), 'status']];
        return $this->render($r, 'admin/cms/simple-index', ['title' => $this->title(), 'subtitle' => static::SUBTITLE, 'base' => $this->base(), 'newLabel' => 'Nouveau ' . $label,
            'rows' => $this->content->all(), 'columns' => $columns, 'notice' => $this->notice()]);
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
            $row = $this->content->get($this->id($r));
            return $this->form($r, $row, $this->valuesOf($row), []);
        });
    }

    public function update(Request $r): Response
    {
        return $this->found(fn (): Response => $this->save($r, $this->content->get($this->id($r))));
    }

    public function transition(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            $to = is_string($r->post('to')) ? $r->post('to') : '';
            try {
                $this->content->transition($id, $to, $this->actor($r));
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'État : ' . strtolower(SimpleContent::STATUS_LABELS[(string) $this->content->get($id)['status']]) . '.');
            return Response::redirect($this->base() . '/' . $id);
        });
    }

    public function delete(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $id = $this->id($r);
            try {
                $this->content->delete($id, $this->actor($r));
            } catch (DomainException $e) {
                return $this->editWithError($r, $id, $e->getMessage());
            }
            $this->session->flash('status', 'Supprimé.');
            return Response::redirect($this->base());
        });
    }

    protected function title(): string
    {
        return ucfirst($this->names()[2]);
    }

    /** @param array<string,mixed>|null $row */
    protected function save(Request $r, ?array $row): Response
    {
        // Champ sentinelle final : absent, PHP a tronqué l'envoi (max_input_vars) ; rien n'est enregistré.
        if ($r->post('_form_complete') !== '1') {
            return $this->form($r, $row, $row === null ? [] : $this->valuesOf($row), ['form' => 'Le formulaire est arrivé incomplet (trop de champs). Rien n\'a été enregistré.'], 422);
        }
        $values = [];
        foreach ($this->fields() as $k) {
            $v = $r->post($k, '');
            $values[$k] = is_string($v) ? $v : '';
        }
        try {
            $id = $this->persist($r, $row, $values);
        } catch (PageInvalid $e) {
            return $this->form($r, $row, $values, $e->errors, 422);
        } catch (DomainException $e) {
            return $this->form($r, $row, $values, ['form' => $e->getMessage()], 422);
        }
        $this->session->flash('status', 'Enregistré.');
        return Response::redirect($this->base() . '/' . $id);
    }

    /** @param array<string,mixed> $row @return array<string,string> */
    protected function valuesOf(array $row): array
    {
        $row = $this->prefill($row);
        $v = [];
        foreach ($this->fields() as $k) {
            $v[$k] = (string) ($row[$k] ?? '');
        }
        return $v;
    }

    protected function editWithError(Request $r, int $id, string $message): Response
    {
        $row = $this->content->get($id);
        return $this->form($r, $row, $this->valuesOf($row), ['form' => $message], 422);
    }

    /** @param array<string,mixed>|null $row @param array<string,string> $values @param array<string,string> $errors */
    protected function form(Request $r, ?array $row, array $values, array $errors, int $status = 200): Response
    {
        [$dir, $label, $noun] = $this->names();
        $mayPublish = $this->mayPublish($r);
        $transitions = $row === null ? [] : SimpleContent::transitionsFor((string) $row['status'], $mayPublish);
        $published = $row !== null && $row['published_at'] !== null;
        return $this->render($r, 'admin/cms/' . $dir . '/form', [
            'title' => $row === null ? 'Nouveau ' . $label : 'Modifier : ' . $label, 'base' => $this->base(), 'row' => $row, 'values' => $values, 'errors' => $errors,
            'errorSummary' => self::summary($errors), 'mayPublish' => $mayPublish, 'locked' => $row !== null && $row['status'] === 'publie' && !$mayPublish, 'noun' => $noun,
            'publication' => $row === null ? '' : $this->view->partial('admin/cms/_publication', ['base' => $this->base(), 'row' => $row, 'transitions' => $transitions, 'mayPublish' => $mayPublish,
                'canDelete' => !$published && $row['status'] === 'brouillon', 'csrfToken' => $this->csrf->token(), 'noun' => $noun, 'extra' => $this->publicationNote($row)]),
        ] + $this->formData($row), $status);
    }

    /** Remarque propre au type sous l'état (texte). @param array<string,mixed> $row */
    protected function publicationNote(array $row): string
    {
        return '';
    }
}
