<?php
declare(strict_types=1);

namespace App\Modules\Solidarity;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Finance\AccountService;
use DomainException;

/** Fonds de solidarité : lecture (solidarity.view), création du compte (solidarity.manage ou finance.manage), export (solidarity.view ET reports.export) ; permissions posées par routes/admin.php. */
final class SolidarityController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Gate $gate, private SolidarityService $fund, private AccountService $accounts)
    {
    }

    public function index(Request $r): Response
    {
        $user = $r->attribute('user');
        $uid = (int) $user['id'];
        $error = $this->session->pullFlash('error');
        $has = $this->fund->account() !== null;
        return new Response($this->view->render('admin/solidarity/index', [
            'title' => 'Fonds de solidarité', 'user' => $user, 'can' => fn (string $p): bool => $this->gate->allows($uid, $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $error ?? $this->session->pullFlash('status'), 'flashKind' => $error !== null ? 'danger' : 'ok',
            'activeFunds' => $this->fund->activeFunds(), 'summary' => $has ? $this->fund->summary() : null, 'latest' => $has ? $this->fund->latest(20) : [],
            'canSeeDisbursements' => array_filter(['finance.view', 'finance.control', 'disbursement.initiate', 'disbursement.approve', 'disbursement.pay'], fn (string $p): bool => $this->gate->allows($uid, $p)) !== [],
        ], 'layouts/admin'));
    }

    public function create(Request $r): Response
    {
        try {
            $this->accounts->create('Fonds de solidarité', 'solidarity_fund', '', (int) $r->attribute('user')['id']);
            $this->session->flash('status', 'Compte du fonds de solidarité créé.');
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
        }
        return Response::redirect('/admin/solidarite');
    }

    public function export(Request $r): Response
    {
        $format = str_ends_with($r->path(), '.xlsx') ? 'xlsx' : 'csv';
        try {
            $body = $this->fund->file($format, (int) $r->attribute('user')['id'], $r->ip(), $r->header('User-Agent'));
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
            return Response::redirect('/admin/solidarite');
        }
        return new Response($body, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="fonds-solidarite-' . date('Ymd') . '.' . $format . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
