<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View;
use App\Modules\Cms\FormInbox;
use App\Modules\Cms\FormInvalid;
use App\Modules\Cms\FormRejected;
use App\Modules\Cms\PageService;
use App\Modules\Cms\PublishedContent;
use App\Modules\Cms\SiteSettings;
use Closure;
use Throwable;

/**
 * Formulaires publics : /contact (contact, ou « information » avec ?demande=information) et /partenariat. Chaque route n'existe que si sa page
 * hôte du CMS est en ligne (« contact », « partenaires ») : sinon 404 sans session (StartSession saute, voir hostLive). Dépôt par FormInbox::submit
 * seulement (champ piège, limite par IP, plafond des notifications) ; accusé sans aucune donnée du visiteur ; réponses no-store (jeton CSRF).
 */
final class ContactController
{
    /** route => [slug de la page hôte, formulaires possibles (le premier par défaut)] */
    public const ROUTES = ['/contact' => ['contact', ['contact', 'information']], '/partenariat' => ['partenaires', ['partenariat']]];
    private const HEADERS = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'];

    /** @param Closure(): FormInbox $inbox @param Closure(): PublishedContent $content @param Closure(): SiteSettings $settings @param Closure(): SiteController $site */
    public function __construct(private View $view, private Csrf $csrf, private Closure $inbox, private Closure $content, private Closure $settings, private Closure $site)
    {
    }

    /** Page hôte en ligne pour ce chemin exact (StartSession : pas de session sinon). */
    public static function hostLive(Database $db, PageService $pages, string $path): bool
    {
        $slug = self::ROUTES[$path][0] ?? null;
        $id = $slug === null ? null : $db->fetchValue('SELECT id FROM cms_pages WHERE slug = ?', [$slug]);
        return $id !== null && $id !== false && $pages->liveSlugs([(int) $id]) !== [];
    }

    public function handle(Request $r): Response
    {
        $site = ($this->site)();
        $bad = SiteController::check($r);
        if ($bad !== null) {
            return $bad->status() === 404 ? $site->notFound() : $bad;
        }
        $path = $r->path();
        try {
            $page = ($this->content)()->page('/' . self::ROUTES[$path][0]);
        } catch (Throwable $e) {
            error_log('Formulaire public : ' . get_class($e));
            $page = null;
        }
        if ($page === null || isset($page['redirect'])) {
            return $site->notFound();
        }
        $keys = self::ROUTES[$path][1];
        $asked = plain($r->input('demande'));
        $key = in_array($asked, $keys, true) ? $asked : $keys[0];
        if ($r->method() !== 'POST') {
            return $r->input('envoye') === '1' ? $this->done($site, $path) : $this->form($site, $page, $path, $key);
        }
        $payload = array_diff_key($r->all(), ['_csrf' => 1, 'demande' => 1]);
        try {
            (($this->inbox)())->submit($key, $payload, $r->ip());
        } catch (FormInvalid $e) {
            return $this->form($site, $page, $path, $key, $payload, $e->errors, 422);
        } catch (FormRejected $e) {
            return $this->form($site, $page, $path, $key, $payload, ['form' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            error_log('Formulaire public non enregistré : ' . get_class($e)); // jamais le contenu
            return $this->form($site, $page, $path, $key, $payload, ['form' => 'Le message n\'a pas pu être envoyé : réessayez dans quelques minutes.'], 503);
        }
        return new Response('', 303, ['Location' => $path . '?envoye=1'] + self::HEADERS); // PRG : un rechargement ne renvoie pas le formulaire
    }

    /** Accusé (GET ?envoye=1) : identique pour tous, aucune donnée du visiteur. */
    private function done(SiteController $site, string $path): Response
    {
        return new Response($site->layout('public/contact/done', ['title' => 'Message envoyé', 'noindex' => true, 'back' => $path]), 200, self::HEADERS + ['X-Robots-Tag' => 'noindex']);
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $values @param array<string,string> $errors */
    private function form(SiteController $site, array $page, string $path, string $key, array $values = [], array $errors = [], int $status = 200): Response
    {
        $blocks = array_values(array_filter(is_array($page['blocks'] ?? null) ? $page['blocks'] : [], static fn ($b): bool => !is_array($b) || ($b['type'] ?? '') !== 'formulaire'));
        $data = $site->safeSite();
        try {
            $consent = (($this->settings)())->publicValue('consent_text');
        } catch (Throwable) {
            $consent = null;
        }
        $html = $site->layout('public/contact/form', ['title' => $key === 'partenariat' ? 'Proposer un partenariat' : (string) ($page['seo_title'] ?: $page['title']),
            'description' => $page['seo_description'] ?: $page['excerpt'], 'page' => $page, 'path' => $path, 'key' => $key, 'keys' => self::ROUTES[$path][1],
            'body' => $path === '/contact' ? (new PageRenderer($this->view, ($this->content)()))->render(['blocks' => $blocks] + $page) : '', 'fields' => FormInbox::FORMS[$key][1],
            'values' => $values, 'errors' => $errors, 'csrfToken' => $this->csrf->token(), 'contacts' => $path === '/contact' ? ($data['contacts'] ?? []) : [],
            'consent' => is_string($consent) ? $consent : '', 'site' => $data], $path === '/contact' ? ($page['canonical_url'] ?? null) : null, $path);
        return new Response($html, $status, self::HEADERS);
    }
}
