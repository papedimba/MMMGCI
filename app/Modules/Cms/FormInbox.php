<?php
declare(strict_types=1);

namespace App\Modules\Cms;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Export\Csv;
use App\Core\Gate;
use App\Core\NotFound;
use App\Core\Sequence;
use Closure;
use DomainException;
use Throwable;

/**
 * Formulaires publics reçus (contact, information, partenariat) : dépôt (submit, appelé par L4), boîte de réception (cms.forms.manage).
 * Règles : champs en liste blanche, texte brut (pas de HTML, caractères de contrôle retirés), champ piège « website », au plus 5 envois
 * par adresse IP et par 10 minutes (compteur dans login_attempts, repère 5, comme /verifier). Le journal d'audit et les e-mails de
 * notification ne contiennent que la référence (MSG-AAAA-NNNNN) et la clé du formulaire, jamais le message ni les coordonnées du visiteur.
 * Historique : liste JSON {at, by, action, note} où l'on n'ajoute que des entrées. Aucune purge automatique (conservation : réglage
 * site.forms_retention_months, à fixer par les instances).
 */
final class FormInbox
{
    public const HONEYPOT = 'website';
    public const MAX_PER_WINDOW = 5;
    public const WINDOW_SECONDS = 600;
    public const MAX_NOTIFICATIONS_PER_HOUR = 20; // plafond global : protège le quota d'e-mails sortants de l'hébergement
    private const NOTIFY_MARK = 6; // login_attempts.succeeded : compteur des notifications envoyées
    private const THROTTLE_MARK = 5; // login_attempts.succeeded : jamais compté par LoginThrottle (0) ni par /verifier (4)
    public const STATUS_LABELS = ['nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'traite' => 'Traité', 'archive' => 'Archivé'];
    public const TRANSITIONS = ['nouveau' => ['en_cours'], 'en_cours' => ['traite'], 'traite' => ['archive', 'en_cours'], 'archive' => []];
    /** clé => [nom, champs : nom => [libellé, longueur max, obligatoire, type]] */
    public const FORMS = [
        'contact' => ['Contact', ['name' => ['Nom', 120, true, 'text'], 'email' => ['E-mail', 150, true, 'email'], 'phone' => ['Téléphone', 20, false, 'phone'],
            'subject' => ['Objet', 150, false, 'text'], 'message' => ['Message', 4000, true, 'area']]],
        'information' => ['Demande d\'information', ['name' => ['Nom', 120, true, 'text'], 'email' => ['E-mail', 150, true, 'email'], 'phone' => ['Téléphone', 20, false, 'phone'],
            'message' => ['Message', 2000, true, 'area']]],
        'partenariat' => ['Partenariat', ['organisation' => ['Organisation', 150, true, 'text'], 'name' => ['Nom', 120, true, 'text'], 'email' => ['E-mail', 150, true, 'email'],
            'phone' => ['Téléphone', 20, false, 'phone'], 'message' => ['Message', 4000, true, 'area']]],
    ];

    /** @param Closure(): \App\Core\Mailer $mailer @param Closure(callable): void $defer */
    public function __construct(private Database $db, private Audit $audit, private Gate $gate, private Sequence $sequence, private SiteSettings $settings,
        private Closure $mailer, private Closure $defer, private string $appUrl = '')
    {
    }

    /**
     * Dépose un envoi. @param array<string,mixed> $payload champs du formulaire (les champs inconnus sont ignorés)
     * @return int identifiant ; @throws FormRejected (piège, limite, formulaire inconnu) @throws FormInvalid (champs invalides)
     */
    public function submit(string $formKey, array $payload, string $ip): int
    {
        $ip = mb_substr($ip, 0, 45);
        $now = time();
        $since = gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS);
        $key = self::throttleKey($ip);
        // Insérer d'abord, compter ensuite (envoi refusé s'il dépasse) : deux envois simultanés ne passent plus tous deux sous la limite.
        $this->db->insert('login_attempts', ['email' => 'formulaire', 'ip' => $key, 'succeeded' => self::THROTTLE_MARK, 'created_at' => gmdate('Y-m-d H:i:s', $now)]);
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = ? AND created_at >= ?', [$key, self::THROTTLE_MARK, $since]) > self::MAX_PER_WINDOW) {
            throw new FormRejected('Trop d\'envois depuis votre connexion : réessayez dans quelques minutes.');
        }
        if (!isset(self::FORMS[$formKey])) {
            throw new FormRejected('Envoi impossible.');
        }
        $trap = $payload[self::HONEYPOT] ?? '';
        if (!is_scalar($trap) || trim((string) $trap) !== '') {
            throw new FormRejected('Envoi impossible.');
        }
        $clean = $this->clean($formKey, $payload);
        [$id, $ref] = $this->db->atomic(function () use ($formKey, $clean, $ip): array {
            $this->db->query('INSERT IGNORE INTO cms_forms (form_key, name) VALUES (?, ?)', [$formKey, self::FORMS[$formKey][0]]);
            $formId = (int) $this->db->fetchValue('SELECT id FROM cms_forms WHERE form_key = ?', [$formKey]);
            $ref = $this->sequence->next('cms_form', 'MSG', 5);
            $id = $this->db->insert('cms_form_submissions', ['form_id' => $formId, 'reference' => $ref, 'payload' => json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'status' => 'nouveau', 'history' => json_encode([['at' => $this->stamp(), 'by' => null, 'action' => 'recu', 'note' => '']], JSON_THROW_ON_ERROR), 'ip' => $ip]);
            $this->audit->log(null, 'cms.form_received', 'cms_form_submissions', $id, null, ['form' => $formKey], $ref);
            return [$id, $ref];
        });
        $this->notify($id, $ref, $formKey);
        return $id;
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function clean(string $formKey, array $payload): array
    {
        $out = [];
        $errors = [];
        foreach (self::FORMS[$formKey][1] as $name => [$label, $max, $required, $type]) {
            $raw = $payload[$name] ?? '';
            $v = is_scalar($raw) ? (string) $raw : '';
            $v = (string) preg_replace($type === 'area' ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u', $type === 'area' ? '' : ' ', str_replace("\r\n", "\n", $v));
            $v = trim(self::noInvisible($v));
            if ($v === '') {
                if ($required) {
                    $errors[$name] = $label . ' : champ obligatoire.';
                }
                continue;
            }
            if (mb_strlen($v) > $max) {
                $errors[$name] = $label . ' : ' . $max . ' caractères au maximum.';
            } elseif (preg_match('/<\/?[a-z!?]/i', $v) === 1) {
                $errors[$name] = $label . ' : le texte ne peut pas contenir de balises HTML.';
            } elseif ($type === 'email' && filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$name] = 'Adresse e-mail invalide.';
            } elseif ($type === 'phone' && preg_match('/^\+?[0-9 ]{6,19}$/', $v) !== 1) {
                $errors[$name] = 'Téléphone invalide : chiffres, espaces et « + ».';
            } else {
                $out[$name] = $v;
            }
        }
        if ($errors !== []) {
            throw new FormInvalid($errors);
        }
        return $out;
    }

    /** Clé du compteur : IPv4 telle quelle ; IPv6 regroupée par /64 (une personne dispose de 2^64 adresses). */
    private static function throttleKey(string $ip): string
    {
        $bin = str_contains($ip, ':') ? @inet_pton($ip) : false;
        return $bin !== false && strlen($bin) === 16 ? bin2hex(substr($bin, 0, 8)) . '/64' : $ip;
    }

    /** Retire les caractères de mise en forme invisibles (bidi, largeur nulle…), sauf la jonction U+200D des émojis. */
    private static function noInvisible(string $v): string
    {
        return (string) preg_replace('/(?!\x{200D})\p{Cf}/u', '', $v);
    }

    /** E-mail interne différé : référence et type seulement, jamais le contenu. */
    private function notify(int $id, string $ref, string $formKey): void
    {
        $to = $this->settings->publicValue('contact_email');
        $recipients = is_string($to) && $to !== '' ? [$to] : array_column($this->db->fetchAll(
            'SELECT DISTINCT u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id WHERE p.name = ? AND u.is_active = 1 ORDER BY u.email', ['cms.forms.manage']), 'email');
        if ($recipients === []) {
            return;
        }
        // Plafond horaire global : au-delà, l'envoi reste dans la boîte de réception mais aucun e-mail n'est émis.
        $this->db->insert('login_attempts', ['email' => 'notification', 'ip' => '-', 'succeeded' => self::NOTIFY_MARK, 'created_at' => gmdate('Y-m-d H:i:s')]);
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE succeeded = ? AND created_at >= ?', [self::NOTIFY_MARK, gmdate('Y-m-d H:i:s', time() - 3600)]) > self::MAX_NOTIFICATIONS_PER_HOUR) {
            return;
        }
        $link = preg_match('#^https?://[^/\s]+#i', $this->appUrl) === 1 ? "\n\nConsulter : " . rtrim($this->appUrl, '/') . '/admin/site/formulaires/' . $id : '';
        $body = "Un nouvel envoi a été reçu sur le site.\n\nRéférence : $ref\nFormulaire : " . self::FORMS[$formKey][0] . $link . "\n\nConnectez-vous à l'administration pour le lire.";
        ($this->defer)(function () use ($recipients, $ref, $body): void {
            foreach ($recipients as $address) {
                try {
                    ($this->mailer)()->send($address, "Nouveau message reçu ($ref)", $body);
                } catch (Throwable $e) { // la classe seulement : le message d'erreur pourrait citer l'adresse
                    error_log('Notification de formulaire non envoyée (' . $ref . ') : ' . get_class($e));
                }
            }
        });
    }

    /**
     * @param array{status?:string,form?:string} $f @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function search(array $f, int $page, int $per = 20): array
    {
        [$where, $args] = $this->filter($f);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM cms_form_submissions s JOIN cms_forms f ON f.id = s.form_id WHERE $where", $args);
        $rows = $this->db->fetchAll("SELECT s.id, s.reference, s.status, s.created_at, f.form_key, f.name AS form_name, u.name AS assignee
            FROM cms_form_submissions s JOIN cms_forms f ON f.id = s.form_id LEFT JOIN users u ON u.id = s.assigned_to WHERE $where
            ORDER BY s.created_at DESC, s.id DESC LIMIT " . (int) $per . ' OFFSET ' . (max(1, $page) - 1) * $per, $args);
        return ['rows' => $rows, 'total' => $total];
    }

    /** @param array{status?:string,form?:string} $f @return array{0:string,1:list<string>} */
    private function filter(array $f): array
    {
        $where = ['1=1'];
        $args = [];
        if (isset(self::STATUS_LABELS[$f['status'] ?? ''])) {
            $where[] = 's.status = ?';
            $args[] = $f['status'];
        }
        if (isset(self::FORMS[$f['form'] ?? ''])) {
            $where[] = 'f.form_key = ?';
            $args[] = $f['form'];
        }
        return [implode(' AND ', $where), $args];
    }

    /** @return array<string,mixed> payload et historique décodés */
    public function get(int $id): array
    {
        $r = $this->db->fetch('SELECT s.*, f.form_key, f.name AS form_name, u.name AS assignee FROM cms_form_submissions s JOIN cms_forms f ON f.id = s.form_id
            LEFT JOIN users u ON u.id = s.assigned_to WHERE s.id = ?', [$id]) ?? throw new NotFound('Envoi introuvable.');
        $r['payload'] = json_decode((string) $r['payload'], true) ?: [];
        $r['history'] = json_decode((string) ($r['history'] ?? '[]'), true) ?: [];
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $h): int => (int) ($h['by'] ?? 0), $r['history']))));
        $r['history_users'] = [];
        if ($ids !== []) {
            foreach ($this->db->fetchAll('SELECT id, name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $u) {
                $r['history_users'][(int) $u['id']] = (string) $u['name'];
            }
        }
        return $r;
    }

    /** Utilisateurs actifs pouvant traiter les formulaires. @return array<int,string> */
    public function assignees(): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT DISTINCT u.id, u.name FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE p.name = ? AND u.is_active = 1 ORDER BY u.name', ['cms.forms.manage']) as $u) {
            $out[(int) $u['id']] = (string) $u['name'];
        }
        return $out;
    }

    public function transition(int $id, string $to, int $actor, string $note = ''): void
    {
        $this->mutate($id, $actor, function (array $row) use ($to, $note): array {
            if (!in_array($to, self::TRANSITIONS[$row['status']] ?? [], true)) {
                throw new DomainException('Ce changement d\'état n\'est pas possible : ' . (self::STATUS_LABELS[$row['status']] ?? $row['status']) . ' vers ' . (self::STATUS_LABELS[$to] ?? $to) . '.');
            }
            return [['status' => $to], 'etat:' . $to, $note, 'cms.form_status_changed', ['status' => $row['status']], ['status' => $to]];
        });
    }

    public function assign(int $id, ?int $userId, int $actor): void
    {
        if ($userId !== null && !isset($this->assignees()[$userId])) {
            throw new DomainException('Cette personne ne peut pas traiter les formulaires.');
        }
        $this->mutate($id, $actor, static fn (array $row): array => [['assigned_to' => $userId], 'affectation', '', 'cms.form_assigned', ['assigned_to' => $row['assigned_to']], ['assigned_to' => $userId]]);
    }

    public function addNote(int $id, string $note, int $actor): void
    {
        $note = trim(self::noInvisible((string) preg_replace('/[^\P{Cc}\n]/u', '', str_replace("\r\n", "\n", $note))));
        if ($note === '' || mb_strlen($note) > 1000) {
            throw new DomainException('La note est obligatoire (1000 caractères au maximum).');
        }
        $this->mutate($id, $actor, static fn (array $row): array => [[], 'note', $note, 'cms.form_noted', null, null]);
    }

    /** @param callable(array<string,mixed>): array{0:array<string,mixed>,1:string,2:string,3:string,4:?array,5:?array} $change */
    private function mutate(int $id, int $actor, callable $change): void
    {
        if (!$this->gate->allows($actor, 'cms.forms.manage')) {
            throw new DomainException('Traiter les formulaires reçus demande le droit « formulaires ».');
        }
        $this->db->atomic(function () use ($id, $actor, $change): void {
            $row = $this->db->fetch('SELECT id, reference, status, assigned_to, history FROM cms_form_submissions WHERE id = ? FOR UPDATE', [$id]) ?? throw new NotFound('Envoi introuvable.');
            [$set, $action, $note, $audit, $old, $new] = $change($row);
            $history = json_decode((string) ($row['history'] ?? '[]'), true) ?: [];
            $history[] = ['at' => $this->stamp(), 'by' => $actor, 'action' => $action, 'note' => mb_substr($note, 0, 1000)]; // ajout seulement
            $this->db->update('cms_form_submissions', $set + ['history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)], ['id' => $id]);
            $this->audit->log($actor, $audit, 'cms_form_submissions', $id, $old, $new, (string) $row['reference']); // jamais le contenu de l'envoi ni la note
        });
    }

    /** Export CSV neutralisé de tout le filtre (5000 lignes au plus), audité avec le nombre de lignes seulement. @param array{status?:string,form?:string} $f */
    public function exportCsv(array $f, int $actor): string
    {
        if (!$this->gate->allows($actor, 'cms.forms.manage')) {
            throw new DomainException('Exporter les formulaires demande le droit « formulaires ».');
        }
        [$where, $args] = $this->filter($f);
        $rows = $this->db->fetchAll("SELECT s.reference, s.status, s.created_at, s.payload, f.name AS form_name, u.name AS assignee
            FROM cms_form_submissions s JOIN cms_forms f ON f.id = s.form_id LEFT JOIN users u ON u.id = s.assigned_to WHERE $where ORDER BY s.created_at DESC, s.id DESC LIMIT 5000", $args);
        $this->audit->log($actor, 'cms.forms_exported', 'cms_form_submissions', null, null, ['count' => count($rows)]);
        $cols = ['name' => 'Nom', 'organisation' => 'Organisation', 'email' => 'E-mail', 'phone' => 'Téléphone', 'subject' => 'Objet', 'message' => 'Message'];
        return Csv::build(['Référence', 'Formulaire', 'Reçu le', 'État', 'Affecté à', ...array_values($cols)], (function () use ($rows, $cols): \Generator {
            foreach ($rows as $r) {
                $p = json_decode((string) $r['payload'], true) ?: [];
                yield [$r['reference'], $r['form_name'], $r['created_at'], self::STATUS_LABELS[$r['status']] ?? $r['status'], $r['assignee'], ...array_map(static fn (string $k): string => (string) ($p[$k] ?? ''), array_keys($cols))];
            }
        })());
    }

    private function stamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->format('Y-m-d H:i:s');
    }
}
