<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Csrf;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\View;
use App\Modules\Cms\PublishedContent;
use App\Modules\Cms\SiteSettings;
use Closure;
use DomainException;

/**
 * Routes /adhesion-en-ligne : garde d'ouverture relue à CHAQUE requête (GET et POST) ; fermée, toute route rend la page « closed »
 * (200) et rien n'est écrit. Chaque réponse : noindex et no-store. Formulaires en POST simple (aucun JavaScript requis), PRG en 303.
 */
final class ApplyController
{
    private const BASE = '/adhesion-en-ligne';
    private const HEADERS = ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex, nofollow'];

    /**
     * @param Closure(): ApplyFlow $flow @param Closure(): SiteSettings $settings @param Closure(): PublishedContent $content
     * @param Closure(): array<string,mixed> $site données du gabarit public (menus, coordonnées validées)
     */
    public function __construct(private View $view, private Csrf $csrf, private Closure $flow, private Closure $settings, private Closure $content, private Closure $site)
    {
    }

    public function entry(Request $r): Response
    {
        if (!$this->open()) {
            return $this->closed();
        }
        return self::go(self::BASE . '/etape/1');
    }

    public function step(Request $r): Response
    {
        if (!$this->open()) {
            return $this->closed();
        }
        $n = (int) $r->attribute('n');
        if ((string) $n !== (string) $r->attribute('n') || !isset(ApplyFlow::STEPS[$n])) {
            return self::go(self::BASE . '/etape/1');
        }
        $flow = ($this->flow)();
        if ($r->method() === 'GET') {
            if ($n === 1) {
                $flow->start(); // début du brouillon : horodatage signé du délai minimal
            }
            $reached = (int) ($flow->draft()['reached'] ?? 0);
            return $n > max(1, $reached) ? self::go(self::BASE . '/etape/' . max(1, $reached)) : $this->render($flow, $n);
        }
        if ($flow->draft() === null && $n !== 5) {
            return self::go(self::BASE . '/etape/1'); // session expirée : on repart du début
        }
        $op = is_string($r->post('op')) ? $r->post('op') : 'next';
        if ($n === 5) {
            return $op === 'back' ? self::go(self::BASE . '/etape/4') : $this->submit($r, $flow);
        }
        $flow->store($n, $r->all());
        if ($op === 'back') {
            return self::go(self::BASE . '/etape/' . max(1, $n - 1));
        }
        if ($n === 4) {
            if (preg_match('/^remove-(\d{1,18})$/D', $op, $m) === 1) {
                $flow->removeFile((int) $m[1]);
                return self::go(self::BASE . '/etape/4');
            }
            $file = $r->file('piece');
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $error = $flow->addFile($file, $r->post('type'));
                if ($error !== null) {
                    return $this->render($flow, 4, ['piece' => $error], 422);
                }
            } elseif ($op === 'upload') {
                return $this->render($flow, 4, ['piece' => 'Choisissez un fichier à ajouter.'], 422);
            }
            if ($op === 'upload') {
                return self::go(self::BASE . '/etape/4');
            }
        }
        $errors = $flow->errors($n);
        if ($errors !== []) {
            return $this->render($flow, $n, $errors, 422);
        }
        $flow->reach($n + 1);
        return self::go(self::BASE . '/etape/' . ($n + 1));
    }

    public function done(Request $r): Response
    {
        if (!$this->open()) {
            return $this->closed();
        }
        $done = ($this->flow)()->done();
        return $done === null ? self::go(self::BASE) : $this->page('public/apply/done', ['title' => 'Demande envoyée', 'reference' => $done]);
    }

    private function submit(Request $r, ApplyFlow $flow): Response
    {
        try {
            $res = $flow->submit($r->all(), $r->ip());
        } catch (DomainException) {
            return $this->render($flow, 5, ['form' => ApplyFlow::REFUSED], 422); // règle refusée au dernier moment : rien n'est créé
        }
        return match ($res['status']) {
            'done' => self::go(self::BASE . '/envoye'),
            'replay' => $this->page('public/apply/done', ['title' => 'Demande envoyée', 'reference' => $res['reference']]),
            'expired' => self::go(self::BASE . '/etape/1'),
            'throttled' => $this->render($flow, 5, ['form' => 'Trop de demandes envoyées depuis votre connexion : réessayez plus tard.'], 429)->withHeader('Retry-After', '1800'),
            'refused' => $this->render($flow, 5, ['form' => ApplyFlow::REFUSED], 422),
            default => $this->render($flow, 5, $res['errors'] ?? [], 422),
        };
    }

    /** @param array<string,string> $errors */
    private function render(ApplyFlow $flow, int $n, array $errors = [], int $status = 200): Response
    {
        $d = $flow->draft() ?? $flow->start();
        return $this->page('public/apply/frame', ['title' => 'Demande d\'adhésion — ' . ApplyFlow::STEPS[$n], 'n' => $n, 'errors' => $errors,
            'body' => $this->view->partial('public/apply/step' . $n, ['values' => $d['data'] ?? [], 'flags' => $d['flags'] ?? [], 'errors' => $errors,
                'options' => $flow->options(), 'files' => $n >= 4 ? $flow->files() : [], 'types' => $flow->pieceTypes(), 'draft' => $d,
                'consent' => $n === 5 ? (string) ((($this->settings)())->publicValue('consent_text') ?? '') : '']),
            'csrfToken' => $this->csrf->token(), 'draft' => $d], $status);
    }

    private function open(): bool
    {
        return ApplyFlow::isOpen(($this->settings)());
    }

    /** Page « bientôt ouverte » : jamais de mention des réglages internes ; pièces de l'art. 6 seulement si la page Adhésion est publiée. */
    private function closed(): Response
    {
        $pieces = [];
        $page = ($this->content)()->page('/adhesion');
        if ($page !== null && !isset($page['redirect'])) {
            foreach (is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $b) {
                if (is_array($b) && ($b['type'] ?? '') === 'dossier') {
                    foreach (is_array($b['pieces'] ?? null) ? $b['pieces'] : [] as $p) {
                        if (is_array($p) && plain($p['label'] ?? '') !== '') {
                            $pieces[] = plain($p['label']);
                        }
                    }
                }
            }
        }
        return $this->page('public/apply/closed', ['title' => 'Adhésion en ligne', 'pieces' => $pieces]);
    }

    /** @param array<string,mixed> $data */
    private function page(string $view, array $data, int $status = 200): Response
    {
        $site = ($this->site)();
        return new Response($this->view->render($view, $data + ['noindex' => true, 'site' => $site, 'contacts' => $site['contacts'] ?? []], 'layouts/public'), $status, self::HEADERS);
    }

    private static function go(string $to): Response
    {
        return new Response('', 303, ['Location' => $to] + self::HEADERS);
    }
}
