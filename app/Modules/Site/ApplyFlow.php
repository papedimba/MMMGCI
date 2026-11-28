<?php
declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Database;
use App\Core\IpKey;
use App\Core\Scheduler;
use App\Core\Session;
use App\Core\UploadError;
use App\Core\Uploads;
use App\Modules\Cms\FormInbox;
use App\Modules\Cms\SiteSettings;
use App\Modules\Members\ApplicationService;
use App\Modules\Members\MemberService;
use Closure;
use Throwable;

/**
 * Adhésion en ligne (L4) : brouillon en session (une clé, un jeton aléatoire), validation par étape avec les règles de
 * MemberService (mêmes règles qu'à l'envoi), pièces sur le disque privé liées au jeton (table apply_draft_files), envoi
 * par ApplicationService::submit (source « en_ligne ») : une demande, jamais un membre. Aucune donnée personnelle n'est
 * journalisée ici (error_log : classe d'exception et référence seulement).
 */
final class ApplyFlow
{
    public const SESSION = 'apply';
    public const DONE = 'apply_done';
    public const STEPS = [1 => 'Situation', 2 => 'Identité', 3 => 'Coordonnées et profession', 4 => 'Pièces', 5 => 'Consentement et envoi'];
    public const STEP_FIELDS = [1 => ['category_id'], 2 => ['last_name', 'first_names', 'sex', 'birth_date', 'birth_place', 'nationality'],
        3 => ['phone', 'email', 'zone_id', 'profession_id', 'specialty_id'], 4 => [], 5 => []];
    public const LABELS = ['category_id' => 'Catégorie', 'last_name' => 'Nom', 'first_names' => 'Prénoms', 'sex' => 'Sexe', 'birth_date' => 'Date de naissance',
        'birth_place' => 'Lieu de naissance', 'nationality' => 'Nationalité', 'phone' => 'Téléphone', 'email' => 'Adresse e-mail', 'zone_id' => 'Zone',
        'profession_id' => 'Profession', 'specialty_id' => 'Spécialité'];
    /** Indications de l'art. 6, gardées dans le brouillon seulement (aucune colonne de la demande ne les reçoit). */
    public const FLAGS = ['livret' => 'Livret maritime disponible', 'ayants_droit' => 'Ayants droit à déclarer'];
    public const SEXES = ['Masculin', 'Féminin'];
    public const REFS = ['category_id' => 'member_categories', 'zone_id' => 'zones', 'profession_id' => 'maritime_professions', 'specialty_id' => 'maritime_specialties'];
    /** Codes de member_document_types (0007) ; la photo passe par le profil « image » (ré-encodée), le reste par « document ». */
    public const PIECE_TYPES = ['identite', 'livret_maritime', 'photo', 'autre'];
    public const MAX_FILES = 6;
    public const MAX_TOTAL_BYTES = 15 * 1048576;
    public const MIN_SECONDS = 8;
    public const MAX_PER_WINDOW = 5;
    public const WINDOW_SECONDS = 1800;
    public const MAX_MAILS_PER_HOUR = 20;
    private const THROTTLE_MARK = 7; // login_attempts.succeeded : 0-2 connexion, 4 /verifier, 5-6 formulaires
    private const MAIL_MARK = 8;
    private const UPLOAD_MARK = 9; // tentatives de téléversement par IP (refusées comprises)
    public const MAX_UPLOADS_PER_HOUR = 12;
    /** Volume total des pièces de brouillon non rattachées, tous visiteurs confondus : au-delà, plus aucun téléversement jusqu'à la purge du cron. */
    public const MAX_DRAFT_BYTES = 200 * 1048576;
    public const DRAFT_TTL_HOURS = 6;
    public const UNAVAILABLE = 'Service momentanément indisponible : réessayez plus tard, ou envoyez votre demande sans pièce (la Mutuelle pourra vous les demander).';
    public const REFUSED = 'Votre demande n\'a pas pu être envoyée. Patientez quelques instants, vérifiez la page puis réessayez.';

    /** @param Closure(): int $clock @param Closure(): \App\Core\Mailer $mailer @param Closure(callable): void $defer */
    public function __construct(private Database $db, private Session $session, private MemberService $members, private ApplicationService $applications,
        private Uploads $uploads, private string $key, private Closure $clock, private Closure $mailer, private Closure $defer)
    {
    }

    /** Ouverte seulement si adhesion_open est validé et vaut true (strict) ET si un texte de consentement non vide est validé. */
    public static function isOpen(SiteSettings $s): bool
    {
        $consent = $s->publicValue('consent_text');
        return $s->publicValue('adhesion_open') === true && is_string($consent) && trim($consent) !== '';
    }

    /** @return array<string,mixed>|null */
    public function draft(): ?array
    {
        $d = $this->session->get(self::SESSION);
        return is_array($d) && is_string($d['token'] ?? null) ? $d : null;
    }

    /** Brouillon existant ou nouveau (jeton aléatoire, horodatage signé du début pour le délai minimal). @return array<string,mixed> */
    public function start(): array
    {
        if (($d = $this->draft()) !== null) {
            return $d;
        }
        $token = bin2hex(random_bytes(32));
        $now = ($this->clock)();
        $d = ['token' => $token, 'stamp' => $now . '.' . $this->sign($token, $now), 'data' => [], 'flags' => [], 'reached' => 1];
        $this->session->set(self::SESSION, $d);
        return $d;
    }

    /** Référence de la dernière demande envoyée dans cette session (page « envoyé »), ou null. */
    public function done(): ?string
    {
        $done = $this->session->get(self::DONE);
        return is_array($done) && is_string($done['reference'] ?? null) ? $done['reference'] : null;
    }

    /** @param array<string,mixed> $d */
    private function save(array $d): void
    {
        $this->session->set(self::SESSION, $d);
    }

    /** Enregistre les champs de l'étape (texte brut, caractères de contrôle retirés ; l'e-mail est gardé tel quel pour refuser CR/LF). @param array<string,mixed> $post */
    public function store(int $step, array $post): void
    {
        $d = $this->start();
        foreach (self::STEP_FIELDS[$step] as $k) {
            $v = is_string($post[$k] ?? null) ? mb_substr($post[$k], 0, 300) : '';
            if ($k !== 'email') {
                $v = (string) preg_replace('/(?!\x{200D})\p{Cf}/u', '', (string) preg_replace('/\p{Cc}/u', ' ', $v));
            }
            // E-mail avec CR/LF : gardé tel quel (non rogné) pour être refusé, jamais « réparé » en silence.
            $d['data'][$k] = !mb_check_encoding($v, 'UTF-8') ? '' : ($k === 'email' && preg_match('/[\r\n]/', $v) === 1 ? $v : trim($v));
        }
        if ($step === 1) {
            $d['flags'] = array_values(array_filter(array_keys(self::FLAGS), static fn (string $f): bool => ($post[$f] ?? '') === '1'));
        }
        $this->save($d);
    }

    public function reach(int $step): void
    {
        $d = $this->start();
        $d['reached'] = max((int) ($d['reached'] ?? 1), $step);
        $this->save($d);
    }

    /** Erreurs des champs d'une étape (null : toutes), par champ, en français. @return array<string,string> */
    public function errors(?int $step = null): array
    {
        $data = $this->draft()['data'] ?? [];
        $raw = [];
        foreach (ApplicationService::FIELDS as $k) {
            $raw[$k] = is_string($data[$k] ?? null) ? $data[$k] : '';
        }
        $errors = $this->members->fieldErrors($raw, true); // les règles de l'envoi (ApplicationService::submit → MemberService::validate)
        unset($errors['status']);
        foreach (self::REFS as $k => $table) {
            if (isset($errors[$k]) || ($raw[$k] !== '' && $this->db->fetchValue("SELECT 1 FROM $table WHERE id = ? AND is_active = 1", [(int) $raw[$k]]) === null)) {
                $errors[$k] = self::LABELS[$k] . ' : choisissez une valeur dans la liste proposée.';
            }
        }
        if ($raw['sex'] !== '' && !in_array($raw['sex'], self::SEXES, true)) {
            $errors['sex'] = 'Sexe : choisissez une valeur dans la liste proposée.';
        }
        if (preg_match('/[\r\n]/', $raw['email']) === 1) {
            $errors['email'] = 'Adresse e-mail invalide (ex. nom@exemple.ci).';
        }
        $fields = $step === null ? array_merge(...array_values(self::STEP_FIELDS)) : self::STEP_FIELDS[$step];
        return array_intersect_key($errors, array_flip($fields));
    }

    /** Étape qui contient le champ. */
    public static function stepOf(string $field): int
    {
        foreach (self::STEP_FIELDS as $n => $fields) {
            if (in_array($field, $fields, true)) {
                return $n;
            }
        }
        return 5;
    }

    /** Référentiels actifs (listes proposées). @return array<string,array<int,string>> */
    public function options(): array
    {
        $out = [];
        foreach (self::REFS as $k => $table) {
            $out[$k] = [];
            foreach ($this->db->fetchAll("SELECT id, name FROM $table WHERE is_active = 1 ORDER BY name, id") as $r) {
                $out[$k][(int) $r['id']] = (string) $r['name'];
            }
        }
        return $out;
    }

    /** @return array<string,string> code => libellé */
    public function pieceTypes(): array
    {
        $labels = [];
        foreach ($this->db->fetchAll('SELECT code, label FROM member_document_types') as $r) {
            $labels[(string) $r['code']] = (string) $r['label'];
        }
        $out = [];
        foreach (self::PIECE_TYPES as $code) {
            $out[$code] = $labels[$code] ?? $code;
        }
        return $out;
    }

    /** Pièces du brouillon (la base fait foi : une pièce purgée par le cron disparaît). @return list<array{file_id:int,type:string,size:int}> */
    public function files(): array
    {
        $token = $this->draft()['token'] ?? null;
        if ($token === null) {
            return [];
        }
        return array_map(static fn (array $r): array => ['file_id' => (int) $r['file_id'], 'type' => (string) $r['type'], 'size' => (int) $r['size']],
            $this->db->fetchAll('SELECT d.file_id, d.type, f.size FROM apply_draft_files d JOIN files f ON f.id = d.file_id WHERE d.token = ? ORDER BY d.id', [$token]));
    }

    /** Ajoute une pièce ; renvoie un message d'erreur ou null. @param array<string,mixed> $phpFile entrée de $_FILES */
    public function addFile(array $phpFile, mixed $type, string $ip): ?string
    {
        $token = $this->start()['token'];
        // Limite par IP (toute tentative compte, même refusée ensuite), puis plafond global des brouillons : contre le remplissage du disque privé.
        $key = IpKey::of($ip);
        $now = ($this->clock)();
        $this->db->insert('login_attempts', ['email' => 'adhesion-piece', 'ip' => $key, 'succeeded' => self::UPLOAD_MARK, 'created_at' => gmdate('Y-m-d H:i:s', $now)]);
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = ? AND created_at >= ?',
            [$key, self::UPLOAD_MARK, gmdate('Y-m-d H:i:s', $now - 3600)]) > self::MAX_UPLOADS_PER_HOUR) {
            return 'Trop de pièces envoyées depuis votre connexion : réessayez plus tard, ou envoyez votre demande sans pièce (la Mutuelle pourra vous les demander).';
        }
        $tmpSize = is_string($phpFile['tmp_name'] ?? null) && is_file($phpFile['tmp_name']) ? (int) filesize($phpFile['tmp_name']) : 0;
        if ((int) $this->db->fetchValue('SELECT COALESCE(SUM(f.size), 0) FROM apply_draft_files d JOIN files f ON f.id = d.file_id') + $tmpSize > self::MAX_DRAFT_BYTES) {
            return self::UNAVAILABLE;
        }
        if (!is_string($type) || !in_array($type, self::PIECE_TYPES, true)) {
            return 'Choisissez le type de la pièce dans la liste proposée.';
        }
        $files = $this->files();
        if (count($files) >= self::MAX_FILES) {
            return self::MAX_FILES . ' pièces au maximum : retirez-en une avant d\'en ajouter une autre.';
        }
        $tmp = (string) ($phpFile['tmp_name'] ?? '');
        $size = $tmp !== '' && is_file($tmp) ? (int) filesize($tmp) : 0;
        if (array_sum(array_column($files, 'size')) + $size > self::MAX_TOTAL_BYTES) {
            return 'Le total des pièces dépasse ' . intdiv(self::MAX_TOTAL_BYTES, 1048576) . ' Mo : réduisez la taille du fichier ou retirez une pièce.';
        }
        // Nom neutre : le nom choisi par le visiteur n'est jamais conservé (seule l'extension sert au contrôle du format).
        $ext = strtolower(pathinfo(is_string($phpFile['name'] ?? null) ? $phpFile['name'] : '', PATHINFO_EXTENSION));
        try {
            $fileId = $this->uploads->store(['name' => $type . '.' . $ext] + $phpFile, $type === 'photo' ? 'image' : 'document', false, null);
        } catch (UploadError $e) {
            return $e->getMessage();
        }
        try {
            $this->db->insert('apply_draft_files', ['token' => $token, 'file_id' => $fileId, 'type' => $type]);
        } catch (Throwable $e) {
            self::purge($this->db, $this->uploads, $fileId);
            throw $e;
        }
        return null;
    }

    public function removeFile(int $fileId): void
    {
        $token = $this->draft()['token'] ?? null;
        if ($token !== null && $this->db->fetchValue('SELECT 1 FROM apply_draft_files WHERE token = ? AND file_id = ?', [$token, $fileId]) !== null) {
            self::purge($this->db, $this->uploads, $fileId);
        }
    }

    /**
     * Envoi final. @param array<string,mixed> $post @return array{status:string,reference?:string,errors?:array<string,string>}
     * status : done (référence), replay (même jeton déjà envoyé : même accusé), expired (aucun brouillon), refused (piège ou délai :
     * message générique), throttled, invalid (erreurs par champ).
     */
    public function submit(array $post, string $ip): array
    {
        $posted = is_string($post['draft'] ?? null) ? $post['draft'] : '';
        $d = $this->draft();
        if ($d === null || !hash_equals((string) $d['token'], $posted)) {
            $done = $this->session->get(self::DONE);
            return is_array($done) && is_string($done['token'] ?? null) && hash_equals($done['token'], $posted)
                ? ['status' => 'replay', 'reference' => (string) $done['reference']] : ['status' => 'expired'];
        }
        $trap = $post[FormInbox::HONEYPOT] ?? '';
        if (!is_string($trap) || trim($trap) !== '' || !$this->stampOk((string) $d['token'], $post['t'] ?? null)) {
            return ['status' => 'refused'];
        }
        $errors = $this->errors();
        if (($post['consent'] ?? '') !== '1') {
            $errors['consent'] = 'Cochez « J\'ai lu et j\'accepte » pour envoyer votre demande.';
        }
        if ($errors !== []) {
            return ['status' => 'invalid', 'errors' => $errors];
        }
        // Compteur par IP : insérer d'abord, compter ensuite (deux envois simultanés ne passent pas tous deux sous la limite).
        $key = IpKey::of($ip);
        $now = ($this->clock)();
        $this->db->insert('login_attempts', ['email' => 'adhesion', 'ip' => $key, 'succeeded' => self::THROTTLE_MARK, 'created_at' => gmdate('Y-m-d H:i:s', $now)]);
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND succeeded = ? AND created_at >= ?',
            [$key, self::THROTTLE_MARK, gmdate('Y-m-d H:i:s', $now - self::WINDOW_SECONDS)]) > self::MAX_PER_WINDOW) {
            return ['status' => 'throttled'];
        }
        $token = (string) $d['token'];
        $payload = array_intersect_key($d['data'], array_flip(ApplicationService::FIELDS));
        try {
            [$ref, $email] = $this->db->transaction(function () use ($payload, $ip, $token): array {
                $id = $this->applications->submit($payload, 'en_ligne', null, mb_substr($ip, 0, 45));
                // Jeton consommé en base (unique) : sans verrou de session, un second envoi simultané échoue ici et toute sa transaction est annulée.
                $this->db->query('UPDATE membership_applications SET draft_token = ? WHERE id = ?', [$token, $id]);
                $this->db->query('INSERT INTO membership_application_files (application_id, type, file_id) SELECT ?, type, file_id FROM apply_draft_files WHERE token = ? ORDER BY id', [$id, $token]);
                $this->db->query('DELETE FROM apply_draft_files WHERE token = ?', [$token]);
                $app = $this->db->fetch('SELECT reference, payload FROM membership_applications WHERE id = ?', [$id]) ?? [];
                return [(string) $app['reference'], (string) (json_decode((string) $app['payload'], true)['email'] ?? '')];
            });
        } catch (\PDOException $e) {
            $done = (int) ($e->errorInfo[1] ?? 0) === 1062 ? $this->db->fetchValue('SELECT reference FROM membership_applications WHERE draft_token = ?', [$token]) : null;
            if ($done === null) {
                throw $e;
            }
            return ['status' => 'replay', 'reference' => (string) $done];
        }
        $this->session->forget(self::SESSION);
        $this->session->set(self::DONE, ['token' => $token, 'reference' => $ref]);
        $this->session->regenerate();
        $this->acknowledge(mb_strtolower(trim($email)), $ref);
        return ['status' => 'done', 'reference' => $ref];
    }

    /** Accusé de réception différé : texte fixe et référence seulement ; ignoré sans e-mail, sans configuration valide ou au-delà du plafond horaire global. */
    private function acknowledge(string $email, string $ref): void
    {
        if ($email === '' || preg_match('/[\r\n]/', $email) === 1 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        try {
            $mailer = ($this->mailer)();
        } catch (Throwable) {
            return; // courrier non configuré (MAIL_FROM) : la demande est reçue, sans accusé
        }
        $this->db->insert('login_attempts', ['email' => 'accuse', 'ip' => '-', 'succeeded' => self::MAIL_MARK, 'created_at' => gmdate('Y-m-d H:i:s')]);
        if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM login_attempts WHERE succeeded = ? AND created_at >= ?', [self::MAIL_MARK, gmdate('Y-m-d H:i:s', time() - 3600)]) > self::MAX_MAILS_PER_HOUR) {
            return;
        }
        $body = "Votre demande d'adhésion a été reçue par la Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire.\n\nRéférence : $ref\n\n"
            . "Elle sera examinée par la Mutuelle. Conservez cette référence.\n\nCet e-mail est envoyé automatiquement : n'y répondez pas.";
        ($this->defer)(static function () use ($mailer, $email, $ref, $body): void {
            try {
                $mailer->send($email, "Demande d'adhésion reçue ($ref)", $body);
            } catch (Throwable $e) {
                error_log('Accusé de demande d\'adhésion non envoyé (' . $ref . ') : ' . get_class($e)); // jamais l'adresse
            }
        });
    }

    private function sign(string $token, int $t): string
    {
        return hash_hmac('sha256', "apply|$token|$t", $this->key);
    }

    /** Jeton horodaté signé (APP_KEY) posé au début du brouillon : au moins MIN_SECONDS avant l'envoi. */
    private function stampOk(string $token, mixed $stamp): bool
    {
        if (!is_string($stamp) || preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/D', $stamp, $m) !== 1 || !hash_equals($this->sign($token, (int) $m[1]), $m[2])) {
            return false;
        }
        return ($this->clock)() - (int) $m[1] >= self::MIN_SECONDS;
    }

    /** Supprime une pièce de brouillon : lignes en base (brouillon, puis fichier s'il n'est rattaché à aucune demande), puis le fichier disque. */
    private static function purge(Database $db, Uploads $uploads, int $fileId): void
    {
        try {
            $path = $uploads->path($fileId);
        } catch (\InvalidArgumentException) {
            $path = null; // déjà absent du disque
        }
        $attached = $db->atomic(static function (Database $db) use ($fileId): bool {
            $db->query('DELETE FROM apply_draft_files WHERE file_id = ?', [$fileId]);
            if ($db->fetchValue('SELECT 1 FROM membership_application_files WHERE file_id = ?', [$fileId]) !== null) {
                return true;
            }
            $db->query('DELETE FROM files WHERE id = ?', [$fileId]);
            return false;
        });
        if (!$attached && $path !== null && is_file($path) && !@unlink($path)) {
            error_log('site.cleanup_apply_drafts : fichier non supprimable (n° ' . $fileId . ')'); // la ligne est déjà retirée : la purge continue avec les suivants
        }
    }

    /** Pièces de brouillons abandonnés (plus de DRAFT_TTL_HOURS, jamais rattachées) : idempotent. @return int nombre de pièces traitées */
    public static function cleanup(Database $db, Uploads $uploads): int
    {
        $ids = array_map('intval', array_column($db->fetchAll('SELECT file_id FROM apply_draft_files WHERE created_at < NOW() - INTERVAL ' . self::DRAFT_TTL_HOURS . ' HOUR ORDER BY id'), 'file_id'));
        foreach ($ids as $id) {
            self::purge($db, $uploads, $id);
        }
        return count($ids);
    }

    public static function register(Scheduler $scheduler, Database $db, Uploads $uploads): void
    {
        $scheduler->register('site.cleanup_apply_drafts', 15, static fn () => self::cleanup($db, $uploads));
    }
}
