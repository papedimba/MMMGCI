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

/** Boîte des formulaires reçus (/admin/site/formulaires, cms.forms.manage posé par routes/admin.php) : liste, fiche, état, affectation, notes, export CSV. */
final class FormController extends CmsScreen
{
    private const BASE = '/admin/site/formulaires';
    private const PER_PAGE = 20;

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private FormInbox $inbox)
    {
        parent::__construct($view, $csrf, $session, $gate);
    }

    /** @return array{status:string,form:string} */
    private function filters(Request $r): array
    {
        $f = [];
        foreach (['status', 'form'] as $k) {
            $v = $r->input($k, '');
            $f[$k] = is_string($v) ? trim($v) : '';
        }
        return $f;
    }

    public function index(Request $r): Response
    {
        $f = $this->filters($r);
        $page = max(1, ctype_digit((string) $r->input('page', '1')) ? (int) $r->input('page', '1') : 1);
        $res = $this->inbox->search($f, $page, self::PER_PAGE);
        return $this->render($r, 'admin/cms/forms/index', ['title' => 'Formulaires reçus', 'rows' => $res['rows'], 'filters' => $f, 'page' => $page, 'total' => $res['total'],
            'pages' => max(1, (int) ceil($res['total'] / self::PER_PAGE))]);
    }

    public function show(Request $r): Response
    {
        return $this->found(fn (): Response => $this->detail($r, ''));
    }

    public function transition(Request $r): Response
    {
        $to = $r->post('to', '');
        $note = $r->post('note', '');
        return $this->act($r, fn (int $id, int $uid) => $this->inbox->transition($id, is_string($to) ? $to : '', $uid, is_string($note) ? $note : ''), 'État mis à jour.');
    }

    public function assign(Request $r): Response
    {
        $to = $r->post('assigned_to', '');
        return $this->act($r, fn (int $id, int $uid) => $this->inbox->assign($id, is_string($to) && ctype_digit($to) && $to !== '' ? (int) $to : null, $uid), 'Affectation enregistrée.');
    }

    public function note(Request $r): Response
    {
        $n = $r->post('note', '');
        return $this->act($r, fn (int $id, int $uid) => $this->inbox->addNote($id, is_string($n) ? $n : '', $uid), 'Note ajoutée.');
    }

    /** @param callable(int,int): void $do */
    private function act(Request $r, callable $do, string $ok): Response
    {
        return $this->found(function () use ($r, $do, $ok): Response {
            $id = $this->id($r);
            try {
                $do($id, $this->actor($r));
            } catch (DomainException $e) {
                return $this->detail($r, $e->getMessage(), 422);
            }
            $this->session->flash('status', $ok);
            return Response::redirect(self::BASE . '/' . $id);
        });
    }

    private function detail(Request $r, string $error, int $status = 200): Response
    {
        $row = $this->inbox->get($this->id($r));
        return $this->render($r, 'admin/cms/forms/show', ['title' => 'Envoi ' . $row['reference'], 'row' => $row, 'error' => $error, 'assignees' => $this->inbox->assignees(),
            'fields' => FormInbox::FORMS[$row['form_key']][1] ?? []], $status);
    }

    public function export(Request $r): Response
    {
        $csv = $this->inbox->exportCsv($this->filters($r), $this->actor($r));
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="formulaires-' . date('Ymd') . '.csv"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
