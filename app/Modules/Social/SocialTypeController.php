<?php
declare(strict_types=1);

namespace App\Modules\Social;

use App\Core\Csrf;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Money;
use App\Core\Session;
use App\Core\View;
use DomainException;
use InvalidArgumentException;

/** Types de demande : lecture = social.view ; paramètres, création et activation = settings.validate (routes/admin.php). */
final class SocialTypeController
{
    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Gate $gate, private SocialTypeService $types, private SocialSettingsService $general)
    {
    }

    public function index(Request $r): Response
    {
        return $this->page($r, 'admin/social/types/index', 'Types de demande d\'assistance', ['rows' => $this->types->all()]);
    }

    public function settings(Request $r): Response
    {
        return $this->page($r, 'admin/social/types/settings', 'Paramètres généraux', ['rows' => $this->general->all(), 'errorKey' => '', 'entered' => '']);
    }

    public function validateSetting(Request $r): Response
    {
        $key = (string) $r->attribute('key');
        $v = $this->texts($r, ['value_' . $key, 'decision_ref_' . $key]);
        try {
            $this->general->validate($key, $v['value_' . $key], $v['decision_ref_' . $key], $this->actor($r));
        } catch (DomainException $e) {
            $this->session->flash('error', $e->getMessage());
            return $this->page($r, 'admin/social/types/settings', 'Paramètres généraux', ['rows' => $this->general->all(), 'errorKey' => $key, 'entered' => $v['value_' . $key]], 422);
        }
        $this->session->flash('status', 'Paramètre validé avec la référence de la décision.');
        return Response::redirect('/admin/demandes-sociales/parametres');
    }

    public function form(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        if ($id === 0) {
            return $this->formPage($r, 0, ['code' => '', 'name' => '', 'action_text' => '', 'decision_ref' => ''], null);
        }
        $t = $this->types->find($id);
        return $this->formPage($r, $id, [
            'criteria_text' => (string) $t['criteria_text'], 'ceiling_amount' => $t['ceiling_amount'] === null ? '' : Money::toDecimal(Money::fromDecimal($t['ceiling_amount'])),
            'waiting_period_days' => (string) $t['waiting_period_days'], 'deciding_body' => (string) $t['deciding_body'], 'decision_ref' => '',
            'allowed_aid_kinds' => $t['allowed_aid_kinds'] === null ? [] : json_decode($t['allowed_aid_kinds'], true),
        ], null);
    }

    public function create(Request $r): Response
    {
        $v = $this->texts($r, ['code', 'name', 'action_text', 'decision_ref']);
        try {
            $id = $this->types->create($v, $v['decision_ref'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->formPage($r, 0, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Type ajouté : ses paramètres restent non configurés.');
        return Response::redirect('/admin/demandes-sociales/types/' . $id . '/parametres');
    }

    public function configure(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $this->types->find($id);
        $v = $this->texts($r, ['criteria_text', 'ceiling_amount', 'waiting_period_days', 'deciding_body', 'decision_ref']);
        $kinds = $r->post('allowed_aid_kinds');
        $v['allowed_aid_kinds'] = is_array($kinds) ? array_values(array_filter($kinds, 'is_string')) : [];
        try {
            $this->types->configure($id, [
                'criteria_text' => $v['criteria_text'], 'deciding_body' => $v['deciding_body'],
                'ceiling_amount' => trim($v['ceiling_amount']) === '' ? null : $this->money($v['ceiling_amount']),
                'waiting_period_days' => trim($v['waiting_period_days']) === '' ? null : (ctype_digit(trim($v['waiting_period_days'])) ? (int) trim($v['waiting_period_days']) : throw new DomainException('Délai de carence : nombre de jours entier.')),
                'allowed_aid_kinds' => $v['allowed_aid_kinds'] === [] ? null : $v['allowed_aid_kinds'],
            ], $v['decision_ref'], $this->actor($r));
        } catch (DomainException $e) {
            return $this->formPage($r, $id, $v, $e->getMessage());
        }
        $this->session->flash('status', 'Paramètres validés avec la référence de la décision.');
        return Response::redirect('/admin/demandes-sociales/types');
    }

    public function activation(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $this->types->find($id);
        $ref = $this->texts($r, ['decision_ref', 'active'])['decision_ref'];
        try {
            $this->types->setActive($id, $r->post('active') === '1', $ref, $this->actor($r));
        } catch (DomainException $e) {
            return $this->formPage($r, $id, ['decision_ref' => $ref] + $this->formValues($id), $e->getMessage());
        }
        $this->session->flash('status', 'État du type mis à jour.');
        return Response::redirect('/admin/demandes-sociales/types');
    }

    /** @return array<string,mixed> */
    private function formValues(int $id): array
    {
        $t = $this->types->find($id);
        return ['criteria_text' => (string) $t['criteria_text'], 'ceiling_amount' => $t['ceiling_amount'] === null ? '' : Money::toDecimal(Money::fromDecimal($t['ceiling_amount'])),
            'waiting_period_days' => (string) $t['waiting_period_days'], 'deciding_body' => (string) $t['deciding_body'],
            'allowed_aid_kinds' => $t['allowed_aid_kinds'] === null ? [] : json_decode($t['allowed_aid_kinds'], true)];
    }

    private function money(string $v): int
    {
        try {
            return Money::parse($v);
        } catch (InvalidArgumentException $e) {
            throw new DomainException('Plafond : ' . $e->getMessage(), 0, $e);
        }
    }

    /** @param array<string,mixed> $values */
    private function formPage(Request $r, int $id, array $values, ?string $error): Response
    {
        return $this->page($r, 'admin/social/types/form', $id > 0 ? 'Paramètres du type' : 'Nouveau type de demande',
            ['id' => $id, 'type' => $id > 0 ? $this->types->find($id) : null, 'values' => $values, 'error' => $error, 'kinds' => SocialTypeService::AID_KINDS], $error === null ? 200 : 422);
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
            'title' => $title, 'user' => $user, 'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(), 'flash' => $error ?? $this->session->pullFlash('status'), 'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
