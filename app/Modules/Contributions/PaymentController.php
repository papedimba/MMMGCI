<?php
declare(strict_types=1);

namespace App\Modules\Contributions;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Money;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\View;
use App\Modules\Finance\Ledger;
use App\Modules\Members\MemberScope;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;

/** Encaissement, fiche de paiement, reçu imprimable et annulation (lecture : contributions.view ; écriture : contributions.manage, via routes/admin.php). */
final class PaymentController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private PaymentService $payments, private ReceiptService $receipts, private MemberScope $scope, private Ledger $ledger)
    {
    }

    public function form(Request $r): Response
    {
        return $this->formPage($r, ['amount' => '', 'method' => 'especes', 'paid_on' => Ledger::now()->format('Y-m-d'), 'external_ref' => '', 'key' => bin2hex(random_bytes(16))], null);
    }

    public function record(Request $r): Response
    {
        $call = $this->call($r);
        $v = $this->texts($r, ['amount', 'method', 'paid_on', 'external_ref', 'key']);
        try {
            $day = DateTimeImmutable::createFromFormat('!Y-m-d', $v['paid_on'], new DateTimeZone('Africa/Abidjan'));
            if ($day === false || $day->format('Y-m-d') !== $v['paid_on']) {
                throw new DomainException('Date invalide : choisissez une date.');
            }
            try {
                $amount = Money::parse($v['amount']);
            } catch (InvalidArgumentException $e) {
                throw new DomainException($e->getMessage(), 0, $e);
            }
            $now = Ledger::now();
            $paidAt = $day->format('Y-m-d') === $now->format('Y-m-d') ? $now : $day->setTime(12, 0);
            $id = $this->payments->record((int) $call['member_id'], (int) $call['id'], $amount, $v['method'], $paidAt, $v['external_ref'] === '' ? null : $v['external_ref'],
                (int) $call['affects_account_id'], $v['key'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->formPage($r, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Paiement enregistré.');
        return Response::redirect('/admin/cotisations/paiements/' . $id);
    }

    public function show(Request $r): Response
    {
        $p = $this->payment($r);
        $call = $this->db->fetch('SELECT c.id, c.period_label, c.amount_due, c.status, t.name AS type_name FROM contribution_calls c JOIN contribution_types t ON t.id = c.contribution_type_id WHERE c.id = ?', [$p['contribution_call_id']]);
        $tx = $this->db->fetch('SELECT reference, account_id FROM financial_transactions WHERE id = ?', [$p['financial_transaction_id']]);
        $balance = $tx === null ? null : $this->ledger->balance((int) $tx['account_id']);
        return $this->page($r, 'admin/contributions/payment', 'Paiement ' . $p['reference'], ['p' => $p, 'call' => $call, 'tx' => $tx, 'balance' => $balance, 'methods' => PaymentService::METHODS]);
    }

    public function receipt(Request $r): Response
    {
        $p = $this->payment($r);
        $rc = $this->receipts->forPayment((int) $p['id']) ?? throw new NotFound('Reçu introuvable.');
        return new Response($this->view->render('admin/contributions/receipt', ['title' => 'Reçu de cotisation', 'reference' => $rc['receipt_number'], 'rc' => $rc, 'methods' => PaymentService::METHODS], 'layouts/print'),
            200, ['Cache-Control' => 'private, no-store']);
    }

    public function cancelForm(Request $r): Response
    {
        return $this->cancelPage($r, '', null);
    }

    public function cancel(Request $r): Response
    {
        $p = $this->payment($r);
        $reason = $this->texts($r, ['reason'])['reason'];
        try {
            $this->payments->cancel((int) $p['id'], $reason, $this->actor($r));
        } catch (DomainException $e) {
            return $this->cancelPage($r, $reason, $e->getMessage());
        }
        $this->session->flash('status', 'Paiement annulé par contre-passation ; le reçu est marqué annulé.');
        return Response::redirect('/admin/cotisations/paiements/' . $p['id']);
    }

    /** @return array<string,mixed> appel visible par l'acteur, sinon 404 */
    private function call(Request $r): array
    {
        $id = (int) $r->attribute('id');
        $c = $this->db->fetch('SELECT c.id, c.member_id, c.period_label, c.amount_due, c.status, t.name AS type_name, t.affects_account_id, m.last_name, m.first_names, m.member_number,
            COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.contribution_call_id = c.id AND p.cancelled_at IS NULL), 0) AS paid
            FROM contribution_calls c JOIN members m ON m.id = c.member_id JOIN contribution_types t ON t.id = c.contribution_type_id WHERE c.id = ?', [$id]) ?? throw new NotFound("Appel $id introuvable.");
        $this->scope->assertMayAccess($this->actor($r), (int) $c['member_id']);
        return $c;
    }

    /** @return array<string,mixed> paiement visible par l'acteur, sinon 404 */
    private function payment(Request $r): array
    {
        $id = (int) $r->attribute('id');
        $p = $this->db->fetch('SELECT p.*, m.last_name, m.first_names, m.member_number, u.name AS received_by_name, rc.receipt_number FROM payments p JOIN members m ON m.id = p.member_id
            LEFT JOIN users u ON u.id = p.received_by LEFT JOIN receipts rc ON rc.payment_id = p.id WHERE p.id = ?', [$id]) ?? throw new NotFound("Paiement $id introuvable.");
        $this->scope->assertMayAccess($this->actor($r), (int) $p['member_id']);
        return $p;
    }

    /** @param array<string,string> $values */
    private function formPage(Request $r, array $values, ?string $error): Response
    {
        $c = $this->call($r);
        $rest = max(0, Money::fromDecimal($c['amount_due']) - Money::fromDecimal((string) $c['paid']));
        return $this->page($r, 'admin/contributions/payment-form', 'Encaisser une cotisation', ['c' => $c, 'rest' => $rest, 'values' => $values, 'error' => $error, 'methods' => PaymentService::METHODS,
            'today' => Ledger::now()->format('Y-m-d')], $error === null ? 200 : 422);
    }

    private function cancelPage(Request $r, string $reason, ?string $error): Response
    {
        return $this->page($r, 'admin/contributions/payment-cancel', 'Annuler un paiement', ['p' => $this->payment($r), 'reason' => $reason, 'error' => $error], $error === null ? 200 : 422);
    }

    /** @param list<string> $keys @return array<string,string> */
    private function texts(Request $r, array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $v = $r->post($k);
            $out[$k] = is_string($v) ? $v : '';
        }
        return $out;
    }

    private function actor(Request $r): int
    {
        return (int) $r->attribute('user')['id'];
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $view, string $title, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        $error = $this->session->pullFlash('error');
        return new Response($this->view->render($view, [
            'title' => $title, 'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'autoBanner' => CallService::autoBanner($this->db),
            'flash' => $error ?? $this->session->pullFlash('status'), 'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
