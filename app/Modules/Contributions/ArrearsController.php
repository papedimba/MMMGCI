<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;

/** Impayés : écran (contributions.view) et export (contributions.export), posés par routes/admin.php. */
final class ArrearsController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate, private ArrearsService $arrears)
    {
    }

    /** @return array<string,string> critères lisibles dans la requête (non-texte ignoré, jamais d'erreur) */
    private function criteria(Request $r): array
    {
        $c = [];
        foreach (['q', 'zone_id', 'type_id', 'min_age_days'] as $k) {
            $v = $r->input($k);
            if (is_string($v) && trim($v) !== '') {
                $c[$k] = trim($v);
            }
        }
        return $c;
    }

    public function index(Request $r): Response
    {
        $user = $r->attribute('user');
        $raw = $this->criteria($r);
        $query = array_intersect_key($raw, ArrearsService::applied($raw)); // seulement les critères valides
        $res = $this->arrears->page((int) $user['id'], $query, (int) $r->input('page', 1));
        $error = $this->session->pullFlash('error');
        $opts = static fn (array $rows, string $empty): array => ['' => $empty] + array_column($rows, 'name', 'id');
        return new Response($this->view->render('admin/contributions/arrears', [
            'title' => 'Impayés de cotisation', 'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $error ?? $this->session->pullFlash('status'), 'flashKind' => $error !== null ? 'danger' : 'ok',
            'res' => $res, 'query' => $query, 'rule' => $this->arrears->rule(),
            'values' => $query + ['q' => '', 'zone_id' => '', 'type_id' => '', 'min_age_days' => ''],
            'zones' => $opts($this->db->fetchAll('SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name'), 'Toutes'),
            'types' => $opts($this->db->fetchAll('SELECT id, name FROM contribution_types ORDER BY name'), 'Tous'),
        ], 'layouts/admin'));
    }

    public function export(Request $r): Response
    {
        $format = str_ends_with($r->path(), '.xlsx') ? 'xlsx' : 'csv';
        $body = $this->arrears->file($format, (int) $r->attribute('user')['id'], $this->criteria($r), $r->ip(), $r->header('User-Agent'));
        return new Response($body, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="impayes-' . date('Ymd') . '.' . $format . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
