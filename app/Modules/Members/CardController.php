<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\NotFound;
use App\Core\Session;
use App\Core\View;
use DomainException;

/** Carte de membre côté administration : aperçu, délivrance, révocation, page imprimable (permission members.update posée par la route). */
final class CardController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db, private Gate $gate,
        private MemberScope $scope, private CardService $cards, private string $appUrl)
    {
    }

    public function show(Request $r): Response
    {
        return $this->page($r, []);
    }

    public function issue(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        try {
            $this->cards->issue($id, (int) $r->attribute('user')['id'], $r->post('conditions_checked') === '1');
        } catch (DomainException $e) {
            return $this->page($r, ['conditions' => $e->getMessage()], 422);
        }
        $this->session->flash('status', 'Carte délivrée.');
        return Response::redirect("/admin/adherents/$id/carte");
    }

    public function revoke(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $cardId = (int) $r->attribute('cardId');
        $reason = $r->post('reason');
        $own = $this->db->fetchValue('SELECT 1 FROM membership_cards WHERE id = ? AND member_id = ?', [$cardId, $id]);
        $this->scope->assertMayAccess((int) $r->attribute('user')['id'], $id);
        if ($own === null) {
            throw new NotFound("Carte $cardId introuvable.");
        }
        try {
            $this->cards->revoke($cardId, is_string($reason) ? $reason : '', (int) $r->attribute('user')['id']);
        } catch (DomainException $e) {
            return $this->page($r, ['reason' => $e->getMessage()], 422);
        }
        $this->session->flash('status', 'Carte révoquée.');
        return Response::redirect("/admin/adherents/$id/carte");
    }

    public function printCard(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess((int) $r->attribute('user')['id'], $id);
        $card = $this->active($id);
        if ($card === null) {
            $this->session->flash('error', 'Aucune carte active à imprimer : délivrez d\'abord une carte.');
            return Response::redirect("/admin/adherents/$id/carte");
        }
        $m = $this->member($id);
        $base = rtrim($this->appUrl !== '' ? $this->appUrl : ($r->isSecure() ? 'https' : 'http') . '://' . ($r->header('Host') ?? 'localhost'), '/');
        return new Response($this->view->render('admin/members/card_print', [
            'title' => 'Carte de membre', 'm' => $m, 'card' => $card, 'verifyUrl' => $base . '/verifier/' . $card['verify_token'],
            'photoUrl' => $m['photo_file_id'] !== null ? "/admin/adherents/$id/photo" : null,
        ], 'layouts/print'), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array<string,mixed>|null */
    private function active(int $memberId): ?array
    {
        return $this->db->fetch('SELECT id, card_number, verify_token, issued_at, expires_at FROM membership_cards WHERE member_id = ? AND revoked_at IS NULL', [$memberId]);
    }

    /** @return array<string,mixed> */
    private function member(int $id): array
    {
        return $this->db->fetch('SELECT m.id, m.last_name, m.first_names, m.member_number, m.photo_file_id, m.status, s.label AS status_label
            FROM members m LEFT JOIN member_statuses s ON s.code = m.status WHERE m.id = ? AND m.deleted_at IS NULL', [$id]) ?? throw new NotFound("Adhérent $id introuvable.");
    }

    /** @param array<string,string> $errors */
    private function page(Request $r, array $errors, int $status = 200): Response
    {
        $user = $r->attribute('user');
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess((int) $user['id'], $id);
        $err = $this->session->pullFlash('error');
        $cards = $this->db->fetchAll('SELECT id, card_number, issued_at, expires_at, revoked_at FROM membership_cards WHERE member_id = ? ORDER BY id DESC', [$id]);
        return new Response($this->view->render('admin/members/card', [
            'title' => 'Carte de membre', 'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $err ?? $this->session->pullFlash('status'), 'flashKind' => $err !== null ? 'danger' : 'ok',
            'm' => $this->member($id), 'cards' => $cards, 'active' => $this->active($id), 'conditions' => $this->cards->conditions(),
            'errors' => $errors, 'maxReason' => CardService::REASON_MAX,
        ], 'layouts/admin'), $status);
    }
}
