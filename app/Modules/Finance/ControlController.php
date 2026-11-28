<?php
declare(strict_types=1);

namespace App\Modules\Finance;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\View;

/** Écran de contrôle des finances (finance.control) : lecture seule, uniquement des routes GET (permission posée par routes/admin.php). */
final class ControlController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate, private ControlService $control)
    {
    }

    public function transactions(Request $r): Response
    {
        $f = ControlService::txFilters($this->in($r));
        return $this->page($r, 'ecritures', 'Contrôle : écritures', ['f' => $f, 'accounts' => $this->accounts(), 'sources' => ControlService::SOURCES, 'query' => $f] + $this->control->transactions($f, (int) $r->input('page', 1)));
    }

    public function disbursements(Request $r): Response
    {
        $f = ControlService::disbFilters($this->in($r));
        return $this->page($r, 'decaissements', 'Contrôle : décaissements', ['f' => $f, 'accounts' => $this->accounts(), 'query' => $f] + $this->control->disbursements($f, (int) $r->input('page', 1)));
    }

    public function disbursement(Request $r): Response
    {
        $d = $this->control->disbursement((int) $r->attribute('id')) ?? throw new NotFound('Demande introuvable.');
        return $this->page($r, 'decaissements', 'Contrôle : demande ' . $d['reference'], ['d' => $d], 'decaissement');
    }

    public function reversals(Request $r): Response
    {
        return $this->page($r, 'contre-passations', 'Contrôle : contre-passations', ['rows' => $this->control->reversals()]);
    }

    public function cancelled(Request $r): Response
    {
        return $this->page($r, 'annulations', 'Contrôle : paiements et reçus annulés', ['rows' => $this->control->cancelledPayments()]);
    }

    public function balances(Request $r): Response
    {
        return $this->page($r, 'soldes', 'Contrôle : soldes et intégrité', ['balances' => $this->control->balances(), 'anomalies' => $this->control->anomalies()]);
    }

    /** Export CSV/XLSX : le jeu (écritures ou décaissements) et le format viennent du chemin ; critères = ceux de l'écran. */
    public function export(Request $r): Response
    {
        $dataset = str_contains($r->path(), '/ecritures.') ? 'ecritures' : 'decaissements';
        $format = str_ends_with($r->path(), '.xlsx') ? 'xlsx' : 'csv';
        $f = $dataset === 'ecritures' ? ControlService::txFilters($this->in($r)) : ControlService::disbFilters($this->in($r));
        $out = $this->control->export($dataset, $format, $f, (int) $r->attribute('user')['id'], $r->ip(), $r->header('User-Agent'));
        return new Response($out['body'], 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Length' => (string) strlen($out['body']),
            'Content-Disposition' => 'attachment; filename="controle-' . $dataset . '-' . date('Ymd') . '.' . $format . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array<string,mixed> */
    private function in(Request $r): array
    {
        $out = [];
        foreach (['du', 'au', 'compte', 'sens', 'source', 'etat'] as $k) {
            $out[$k] = $r->input($k, '');
        }
        return $out;
    }

    /** @return array<int|string,string> */
    private function accounts(): array
    {
        return ['' => 'Tous'] + array_column($this->db->fetchAll('SELECT id, name FROM financial_accounts ORDER BY name'), 'name', 'id');
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $tab, string $title, array $data, string $view = ''): Response
    {
        $user = $r->attribute('user');
        return new Response($this->view->render('admin/finance/control/' . ($view !== '' ? $view : $tab), [
            'title' => $title, 'user' => $user, 'tab' => $tab, 'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $this->session->pullFlash('status'), 'flashKind' => 'ok',
        ] + $data, 'layouts/admin'), 200, ['Cache-Control' => 'private, no-store']);
    }
}
