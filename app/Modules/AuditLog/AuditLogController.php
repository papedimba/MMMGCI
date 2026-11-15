<?php
declare(strict_types=1);

namespace App\Modules\AuditLog;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Core\View;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Consultation du journal d'audit et des consultations sensibles (permission audit.view, posée par routes/admin.php). Aucune écriture hormis audit.verified. */
final class AuditLogController
{
    private const MAX_VALUE = 2000;

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Gate $gate, private Audit $audit, private AuditQuery $logs, private SensitiveAccess $sensitive)
    {
    }

    public function index(Request $r): Response
    {
        $tab = $r->input('onglet') === 'sensibles' ? 'sensibles' : 'journal';
        $raw = [];
        foreach (['du', 'au', 'utilisateur', 'action'] as $k) {
            $raw[$k] = is_string($v = $r->input($k)) ? $v : null;
        }
        if ($tab === 'sensibles') {
            $raw['action'] = null;
        }
        $v = Validator::make($raw, ['du' => 'nullable|date', 'au' => 'nullable|date', 'utilisateur' => 'nullable|integer', 'action' => 'nullable|max:50'],
            ['utilisateur' => 'Utilisateur', 'action' => 'Action']);
        $errors = $v->errors();
        $values = $v->validated();
        if (!isset($errors['action']) && $values['action'] !== null && !preg_match('/^[a-z._]+$/', (string) $values['action'])) {
            $errors['action'] = 'Action : saisissez le début d\'un nom d\'action en lettres minuscules, points ou soulignés (ex. auth.).';
        }
        if (!isset($errors['du']) && !isset($errors['au']) && $values['du'] !== null && $values['au'] !== null && $values['du'] > $values['au']) { // ISO : comparables en texte ; la vue préfixe « Au : »
            $errors['au'] = 'la date doit être postérieure ou égale à la date « Du ».';
        }
        $page = $r->input('page');
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;

        $result = ['rows' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        if ($errors === []) {
            $filters = array_filter([
                'from' => $values['du'] === null ? null : $this->utc($values['du'] . ' 00:00:00'),
                'to' => $values['au'] === null ? null : $this->utc($values['au'] . ' 23:59:59'),
                'user_id' => $values['utilisateur'] === null ? null : (int) $values['utilisateur'],
                'action' => $values['action'],
            ], static fn ($x) => $x !== null);
            $result = $tab === 'sensibles' ? $this->sensitive->list($filters, $page) : $this->logs->list($filters, $page);
        }

        $user = $r->attribute('user');
        // Clé distincte : l'anomalie d'intégrité ne s'affiche jamais comme un succès. Les deux sont retirées : aucune ne reste en attente.
        $danger = $this->session->pullFlash('status_danger');
        $status = $this->session->pullFlash('status');
        $html = $this->view->render('admin/audit/index', [
            'title' => $tab === 'sensibles' ? 'Consultations sensibles' : "Journal d'audit",
            'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $danger ?? $status,
            'flashKind' => $danger !== null ? 'danger' : 'ok',
            'tab' => $tab,
            'filters' => array_filter($raw, static fn ($x) => $x !== null && $x !== ''),
            'errors' => $errors,
            'users' => $this->db->fetchAll('SELECT id, name FROM users ORDER BY name, id'),
            'when' => fn (string $utc): string => (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i:s'),
            'pretty' => self::pretty(...),
        ] + $result, 'layouts/admin');
        // Le journal contient e-mails et noms : jamais en cache (navigateur ni proxy).
        return (new Response($html, $errors === [] ? 200 : 422))->withHeader('Cache-Control', 'no-store');
    }

    public function verify(Request $r): Response
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(60); // verify() relit tout le journal par lots de 1000 : quelques secondes sur un gros journal
        }
        $bad = $this->audit->verify();
        $message = $bad === null ? 'Journal intact.'
            : "Anomalie détectée à partir de l'entrée n° $bad : le journal a pu être modifié. Prévenez l'organe de contrôle.";
        $key = $bad === null ? 'status' : 'status_danger';
        $this->session->flash($key, $message);
        // Journalisé après le contrôle pour ne pas l'influencer ; s'il échoue (ex. ligne audit_chain absente), le résultat reste affiché.
        try {
            $this->audit->log((int) $r->attribute('user')['id'], 'audit.verified', 'audit_logs', null, null,
                ['result' => $bad === null ? 'intact' : 'anomaly', 'first_bad_id' => $bad], null, $r->ip(), $r->header('User-Agent'));
        } catch (Throwable) {
            $this->session->flash($key, $message . " (l'enregistrement de cette vérification a échoué : vérifiez la migration 0002)");
        }
        return Response::redirect('/admin/journal');
    }

    /** Heure locale (fuseau configuré) → UTC, fuseau de stockage. */
    private function utc(string $local): string
    {
        return (new DateTimeImmutable($local, new DateTimeZone(date_default_timezone_get())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** JSON stocké → texte indenté, tronqué ; à échapper par la vue. */
    private static function pretty(?string $json): string
    {
        if ($json === null) {
            return '';
        }
        $decoded = json_decode($json, true);
        $text = $decoded === null ? $json : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return mb_strlen($text) > self::MAX_VALUE ? mb_substr($text, 0, self::MAX_VALUE) . ' … (tronqué)' : $text;
    }
}
