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

/** Réglages du site (/admin/site/reglages) : saisie (cms.settings.manage) et validation avec référence de décision (settings.validate) ; droits posés par routes/admin.php. */
final class SettingsController extends CmsScreen
{
    private const BASE = '/admin/site/reglages';

    public function __construct(View $view, Csrf $csrf, Session $session, Gate $gate, private SiteSettings $settings)
    {
        parent::__construct($view, $csrf, $session, $gate);
    }

    public function index(Request $r): Response
    {
        return $this->page($r, [], '');
    }

    public function save(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $key = (string) $r->attribute('key');
            $raw = $r->post($key, '');
            $raw = is_string($raw) ? $raw : '';
            try {
                $this->settings->save($key, $raw, $this->actor($r));
            } catch (PageInvalid $e) {
                return $this->page($r, [$key => $raw], '', $e->errors);
            } catch (DomainException $e) {
                return $this->page($r, [$key => $raw], $e->getMessage());
            }
            $this->session->flash('status', 'Réglage enregistré : il reste en attente de validation par les instances.');
            return Response::redirect(self::BASE . '#r-' . $key);
        });
    }

    public function validate(Request $r): Response
    {
        return $this->found(function () use ($r): Response {
            $key = (string) $r->attribute('key');
            $ref = $r->post('decision_ref_' . $key, '');
            $seen = $r->post('seen_' . $key, '');
            try {
                $this->settings->validate($key, $this->actor($r), is_string($ref) ? $ref : '', is_string($seen) ? $seen : '');
            } catch (DomainException $e) {
                return $this->page($r, [], $e->getMessage());
            }
            $this->session->flash('status', 'Réglage validé : il peut désormais être utilisé sur le site public.');
            return Response::redirect(self::BASE . '#r-' . $key);
        });
    }

    /** @param array<string,string> $entered valeurs saisies à réafficher @param array<string,string> $errors */
    private function page(Request $r, array $entered, string $error, array $errors = []): Response
    {
        $uid = $this->actor($r);
        return $this->render($r, 'admin/cms/settings/index', ['title' => 'Réglages du site', 'rows' => $this->settings->all(), 'entered' => $entered, 'errors' => $errors,
            'error' => $error, 'canManage' => $this->gate->allows($uid, 'cms.settings.manage'), 'canValidate' => $this->gate->allows($uid, 'settings.validate'), 'base' => self::BASE],
            $error !== '' || $errors !== [] ? 422 : 200);
    }
}
