<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Gate;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Core\UploadError;
use App\Core\Uploads;
use App\Core\Validator;
use App\Core\View;
use App\Modules\AuditLog\SensitiveAccess;
use DomainException;

/** Liste, fiche, création, modification et ayants droit des adhérents (permissions posées par routes/admin.php ; périmètre par MemberScope). */
final class MemberController
{
    public const TABS = ['identite' => 'Identité', 'profession' => 'Profession', 'ayants-droit' => 'Ayants droit', 'pieces' => 'Pièces',
        'cotisations' => 'Cotisations', 'demandes' => 'Demandes', 'historique' => 'Historique'];
    private const FIELDS = ['last_name', 'first_names', 'sex', 'birth_date', 'birth_place', 'nationality', 'phone', 'phone_alt', 'email', 'address',
        'zone_id', 'category_id', 'profession_id', 'specialty_id', 'employment_status', 'employer', 'joined_at'];
    /** Pièce d'identité et livret : réservés à members.documents.view (absents du formulaire sinon, valeurs existantes conservées). */
    private const DOC_FIELDS = ['id_document_type', 'id_document_number', 'seaman_book_number', 'seaman_book_expiry'];
    private const SORTS = ['name' => 'Nom (A à Z)', '-name' => 'Nom (Z à A)', 'number' => 'Numéro', '-joined_at' => 'Adhésion (récentes d\'abord)', 'joined_at' => 'Adhésion (anciennes d\'abord)', 'status' => 'Statut'];

    public function __construct(private View $view, private Csrf $csrf, private Session $session, private Database $db,
        private Gate $gate, private MemberSearch $search, private MemberScope $scope, private SensitiveAccess $sensitive,
        private MemberService $members, private Uploads $uploads, private Audit $audit, private StatusService $statuses)
    {
    }

    /** @return array{0: array<string,string>, 1: string} critères et tri de la liste, lus dans la requête (partagés avec les exports) */
    private function filters(Request $r): array
    {
        $criteria = [];
        foreach (['q', 'status', 'category_id', 'zone_id', 'profession_id'] as $k) {
            $v = $r->input($k);
            if (is_string($v) && trim($v) !== '') {
                $criteria[$k] = trim($v);
            }
        }
        $sort = $r->input('tri');
        return [$criteria, is_string($sort) && isset(self::SORTS[$sort]) ? $sort : 'name'];
    }

    /** Export CSV ou XLSX de la liste filtrée (route : members.export). */
    public function export(Request $r): Response
    {
        $format = str_ends_with($r->path(), '.xlsx') ? 'xlsx' : 'csv';
        [$criteria, $sort] = $this->filters($r);
        $body = (new MemberExport($this->search, $this->audit))->file($format, (int) $r->attribute('user')['id'], $criteria, $sort, $r->ip(), $r->header('User-Agent'));
        return new Response($body, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8',
            'Content-Length' => (string) strlen($body),
            'Content-Disposition' => 'attachment; filename="adherents-' . date('Ymd') . '.' . $format . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Liste imprimable (gabarit d'impression L2), mêmes critères et périmètre que l'export. */
    public function printList(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        [$criteria, $sort] = $this->filters($r);
        $export = new MemberExport($this->search, $this->audit);
        $rows = iterator_to_array($export->rows($userId, $criteria, $sort), false);
        $export->audited('print', count($rows), $userId, $criteria, $r->ip(), $r->header('User-Agent'));
        return new Response($this->view->render('admin/members/print', ['title' => 'Liste des adhérents', 'headers' => MemberExport::HEADERS, 'rows' => $rows, 'summary' => $this->summary($criteria)], 'layouts/print'), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** @param array<string,mixed> $criteria @return list<string> libellés lisibles des critères appliqués (échappés par la vue) */
    private function summary(array $criteria): array
    {
        $applied = MemberExport::applied($criteria);
        $name = fn (string $table, string $col, mixed $id): string => (string) ($this->db->fetchValue("SELECT $col FROM $table WHERE id = ?", [(int) $id]) ?? '?');
        $out = [];
        foreach ($applied as $k => $v) {
            $out[] = match ($k) {
                'q' => 'Recherche : « ' . trim((string) $criteria['q']) . ' »',
                'status' => 'Statut : ' . ($this->db->fetchValue('SELECT label FROM member_statuses WHERE code = ?', [$v]) ?? $v),
                'category_id' => 'Catégorie : ' . $name('member_categories', 'name', $v),
                'zone_id' => 'Zone : ' . $name('zones', 'name', $v),
                'profession_id' => 'Profession : ' . $name('maritime_professions', 'name', $v),
                'joined_from' => 'Adhésion depuis le ' . $v,
                'joined_to' => 'Adhésion jusqu\'au ' . $v,
            };
        }
        return $out;
    }

    public function index(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        [$criteria, $sort] = $this->filters($r);
        $page = $r->input('page');
        $page = is_string($page) && ctype_digit($page) ? max(1, (int) $page) : 1;
        $res = $this->search->run($userId, $criteria, $sort, $page);
        $page = min($page, $res['pages']);
        $opts = static fn (array $rows, string $key, string $label): array => ['' => 'Tous'] + array_column($rows, $label, $key);
        $db = $this->db;
        return $this->page($r, 'admin/members/index', 'Adhérents', $res + [
            'page' => $page, 'criteria' => $criteria, 'sort' => $sort, 'sorts' => self::SORTS,
            'query' => $criteria + ($sort !== 'name' ? ['tri' => $sort] : []),
            'statuses' => $opts($db->fetchAll('SELECT code, label FROM member_statuses WHERE is_active = 1 ORDER BY label'), 'code', 'label'),
            'categories' => $opts($db->fetchAll('SELECT id, name FROM member_categories WHERE is_active = 1 ORDER BY name'), 'id', 'name'),
            'zones' => $opts($db->fetchAll('SELECT id, name FROM zones WHERE is_active = 1 ORDER BY name'), 'id', 'name'),
            'professions' => $opts($db->fetchAll('SELECT id, name FROM maritime_professions WHERE is_active = 1 ORDER BY name'), 'id', 'name'),
            'statusLabels' => array_column($db->fetchAll('SELECT code, label FROM member_statuses'), 'label', 'code'),
        ]);
    }

    public function show(Request $r): Response
    {
        return $this->showPage($r);
    }

    /** @param array<string,mixed> $extra @param ?string $forceTab onglet imposé (retour d'erreur d'un formulaire d'onglet) */
    private function showPage(Request $r, array $extra = [], int $status = 200, ?string $forceTab = null): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id); // NotFound : 404 central
        $m = $this->db->fetch(
            'SELECT m.*, z.name AS zone_name, c.name AS category_name, p.name AS profession_name, s.name AS specialty_name, st.label AS status_label
             FROM members m LEFT JOIN zones z ON z.id = m.zone_id LEFT JOIN member_categories c ON c.id = m.category_id
             LEFT JOIN maritime_professions p ON p.id = m.profession_id LEFT JOIN maritime_specialties s ON s.id = m.specialty_id
             LEFT JOIN member_statuses st ON st.code = m.status WHERE m.id = ?', [$id]);
        $docs = $this->gate->allows($userId, 'members.documents.view');
        $tabs = array_filter(self::TABS, static fn (string $k): bool => $k !== 'pieces' || $docs, ARRAY_FILTER_USE_KEY);
        $tab = $forceTab ?? $r->input('onglet');
        $tab = is_string($tab) && isset($tabs[$tab]) ? $tab : 'identite';
        $data = ['m' => $m, 'tab' => $tab, 'tabs' => $tabs, 'canDocuments' => $docs, 'revealed' => []] + $extra;
        if ($tab === 'ayants-droit') {
            $data['rows'] = $this->db->fetchAll('SELECT id, last_name, first_names, relationship, birth_date, phone FROM member_beneficiaries WHERE member_id = ? ORDER BY last_name, first_names, id', [$id]);
        } elseif ($tab === 'pieces') {
            $data['rows'] = $this->db->fetchAll('SELECT d.file_id, d.created_at, t.label AS type_label, f.original_name, f.size
                FROM member_documents d JOIN member_document_types t ON t.code = d.type JOIN files f ON f.id = d.file_id WHERE d.member_id = ? ORDER BY d.id DESC', [$id]);
            $data['docTypes'] = array_column($this->db->fetchAll('SELECT code, label FROM member_document_types ORDER BY label'), 'label', 'code');
        } elseif ($tab === 'historique') {
            $data['rows'] = $this->db->fetchAll(
                'SELECT h.from_status, h.to_status, h.reason, h.decision_ref, h.created_at, u.name AS author, f.label AS from_label, t.label AS to_label
                 FROM member_status_history h LEFT JOIN users u ON u.id = h.changed_by LEFT JOIN member_statuses f ON f.code = h.from_status
                 LEFT JOIN member_statuses t ON t.code = h.to_status WHERE h.member_id = ? ORDER BY h.id DESC', [$id]);
        } elseif ($docs && ($tab === 'identite' || $tab === 'profession')) {
            // Une consultation journalisée par affichage effectif du numéro (pièce d'identité ou livret), jamais pour un champ vide.
            $field = $tab === 'identite' ? 'id_document_number' : 'seaman_book_number';
            if ((string) $m[$field] !== '') {
                $this->sensitive->record($userId, 'member', $id, $field, $r->ip());
                $data['revealed'][$field] = true;
            }
        }
        return $this->page($r, 'admin/members/show', $m['last_name'] . ' ' . $m['first_names'], $data, $status);
    }

    public function statusForm(Request $r): Response
    {
        return $this->statusPage($r, ['to' => '', 'reason' => '', 'decision_ref' => ''], []);
    }

    public function changeStatus(Request $r): Response
    {
        $id = (int) $r->attribute('id');
        $values = [];
        foreach (['to', 'reason', 'decision_ref'] as $k) {
            $v = $r->post($k);
            $values[$k] = is_string($v) ? $v : '';
        }
        try {
            $this->statuses->change($id, $values['to'], $values['reason'], $values['decision_ref'], (int) $r->attribute('user')['id']);
        } catch (StatusInvalid $e) {
            return $this->statusPage($r, $values, $e->errors, 422);
        }
        $this->session->flash('status', 'Statut modifié.');
        return Response::redirect("/admin/adherents/$id?onglet=historique");
    }

    /** @param array<string,string> $values @param array<string,string> $errors */
    private function statusPage(Request $r, array $values, array $errors, int $status = 200): Response
    {
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess((int) $r->attribute('user')['id'], $id);
        $m = $this->db->fetch('SELECT id, last_name, first_names, member_number, status FROM members WHERE id = ?', [$id]) ?? throw new \App\Core\NotFound("Adhérent $id introuvable.");
        $labels = array_column($this->db->fetchAll('SELECT code, label FROM member_statuses WHERE is_active = 1 AND code <> ? ORDER BY label', [$m['status']]), 'label', 'code');
        return $this->page($r, 'admin/members/status', 'Changer le statut', [
            'm' => $m, 'values' => $values, 'errors' => $errors, 'options' => ['' => 'Choisir un statut'] + $labels,
            'currentLabel' => (string) $this->db->fetchValue('SELECT label FROM member_statuses WHERE code = ?', [$m['status']]) ?: $m['status'],
            'maxReason' => StatusService::REASON_MAX, 'maxRef' => StatusService::REF_MAX,
        ], $status);
    }

    public function createForm(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        if ($this->scope->forUser($userId) === 0) {
            return $this->noZone($r);
        }
        return $this->form($r, null, array_fill_keys([...self::FIELDS, ...self::DOC_FIELDS], ''));
    }

    public function store(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        if ($this->scope->forUser($userId) === 0) {
            return $this->noZone($r);
        }
        $data = $this->posted($r, $userId, null);
        if ($r->post('back') !== null) {
            return $this->form($r, null, $data);
        }
        $confirmed = $r->post('confirm_duplicate') === '1';
        $photo = $this->photoFile($r);
        try {
            $id = $this->db->transaction(function () use ($data, $userId, $confirmed, $photo): int {
                $id = $this->members->create($data, $userId);
                if (!$confirmed) {
                    $found = $this->members->findDuplicates($data, $id, $userId);
                    if ($found['visible'] !== [] || $found['hidden_count'] > 0) {
                        throw new DuplicatesFound($found); // annule la création : rien n'est écrit avant confirmation
                    }
                }
                if ($photo !== null) {
                    $this->setPhoto($id, $photo, $userId);
                }
                return $id;
            });
        } catch (DuplicatesFound $d) {
            return $this->duplicates($r, $data, $d->found, $photo !== null);
        } catch (DomainException $e) {
            return $this->form($r, null, $data, $e->getMessage());
        } catch (UploadError $e) {
            return $this->form($r, null, $data, null, ['photo' => $e->getMessage()]);
        }
        $this->session->flash('status', 'Adhérent créé : n° ' . $this->db->fetchValue('SELECT member_number FROM members WHERE id = ?', [$id]) . '.');
        return Response::redirect("/admin/adherents/$id");
    }

    public function editForm(Request $r): Response
    {
        $m = $this->editable($r);
        return $this->form($r, $m, array_map(static fn ($v) => $v === null ? '' : (string) $v, array_intersect_key($m, array_flip([...self::FIELDS, ...self::DOC_FIELDS]))));
    }

    public function update(Request $r): Response
    {
        $m = $this->editable($r);
        $userId = (int) $r->attribute('user')['id'];
        $data = $this->posted($r, $userId, $m);
        $photo = $this->photoFile($r);
        try {
            $this->db->transaction(function () use ($m, $data, $userId, $photo): void {
                $this->members->update((int) $m['id'], $data, $userId);
                if ($photo !== null) {
                    $this->setPhoto((int) $m['id'], $photo, $userId);
                }
            });
        } catch (DomainException $e) {
            return $this->form($r, $m, $data, $e->getMessage());
        } catch (UploadError $e) {
            return $this->form($r, $m, $data, null, ['photo' => $e->getMessage()]);
        }
        $this->session->flash('status', 'Modifications enregistrées.');
        return Response::redirect('/admin/adherents/' . $m['id']);
    }

    public function addBeneficiary(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id);
        $values = [];
        foreach (['last_name', 'first_names', 'relationship', 'birth_date', 'phone'] as $k) {
            $v = $r->post($k);
            $values[$k] = is_string($v) ? $v : '';
        }
        $v = Validator::make($values, ['last_name' => 'required|string|max:120', 'first_names' => 'required|string|max:160', 'relationship' => 'required|string|max:60',
            'birth_date' => 'nullable|date|before_today', 'phone' => 'nullable|string|max:30'],
            ['last_name' => 'nom', 'first_names' => 'prénoms', 'relationship' => 'lien de parenté', 'birth_date' => 'date de naissance', 'phone' => 'téléphone']);
        $errors = $v->errors();
        $row = $v->validated();
        $phone = MemberService::normalizePhone($values['phone']);
        if (trim($values['phone']) !== '' && ($phone === null || !preg_match('/^\+?\d{6,15}$/', $phone))) {
            $errors['phone'] = 'Téléphone invalide : saisissez des chiffres, avec « + » en tête pour un numéro international.';
        }
        if ($errors !== []) {
            return $this->showPage($r, ['bError' => implode(' ', $errors), 'bValues' => $values], 422, 'ayants-droit');
        }
        $row['phone'] = $phone;
        $this->db->transaction(function () use ($id, $row, $userId): void {
            $bid = $this->db->insert('member_beneficiaries', ['member_id' => $id] + $row);
            $this->audit->log($userId, 'member.beneficiary_added', 'member', $id, null, ['beneficiary_id' => $bid] + $row);
        });
        $this->session->flash('status', 'Ayant droit ajouté.');
        return Response::redirect("/admin/adherents/$id?onglet=ayants-droit");
    }

    public function deleteBeneficiary(Request $r): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess($userId, $id);
        $bid = (int) $r->attribute('bid');
        $this->db->transaction(function () use ($id, $bid, $userId): void {
            // Suppression définitive (le schéma n'a ni deleted_at ni historique pour les ayants droit) ; l'audit garde l'état supprimé.
            $b = $this->db->fetch('SELECT id, last_name, first_names, relationship, birth_date, phone FROM member_beneficiaries WHERE id = ? AND member_id = ? FOR UPDATE', [$bid, $id])
                ?? throw new \App\Core\NotFound("Ayant droit $bid introuvable.");
            $this->db->query('DELETE FROM member_beneficiaries WHERE id = ?', [$bid]);
            $this->audit->log($userId, 'member.beneficiary_removed', 'member', $id, $b, null);
        });
        $this->session->flash('status', 'Ayant droit supprimé.');
        return Response::redirect("/admin/adherents/$id?onglet=ayants-droit");
    }

    /** Adhérent à modifier (404 hors périmètre ou inconnu). @return array<string,mixed> */
    private function editable(Request $r): array
    {
        $id = (int) $r->attribute('id');
        $this->scope->assertMayAccess((int) $r->attribute('user')['id'], $id);
        return $this->db->fetch('SELECT * FROM members WHERE id = ?', [$id]) ?? throw new \App\Core\NotFound("Adhérent $id introuvable.");
    }

    /**
     * Valeurs du formulaire. Le statut n'en fait pas partie (members.status, tâche suivante). La zone d'un compte sans members.scope.all est
     * imposée par le serveur ; sans members.documents.view, les champs pièce/livret ne sont ni lus ni modifiés.
     * @param array<string,mixed>|null $existing @return array<string,mixed>
     */
    private function posted(Request $r, int $userId, ?array $existing): array
    {
        $data = [];
        foreach (self::FIELDS as $k) {
            $v = $r->post($k);
            $data[$k] = is_string($v) ? $v : '';
        }
        foreach (self::DOC_FIELDS as $k) {
            $v = $r->post($k);
            $data[$k] = $this->gate->allows($userId, 'members.documents.view') ? (is_string($v) ? $v : '') : ($existing[$k] ?? null);
        }
        $zone = $this->scope->forUser($userId);
        if ($zone !== null) {
            $data['zone_id'] = (string) $zone;
        }
        return $data;
    }

    /** @return array<string,mixed>|null null si aucun fichier choisi */
    private function photoFile(Request $r): ?array
    {
        $f = $r->file('photo');
        return $f === null || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? null : $f;
    }

    /** Photo sur le disque privé (la carte la lira plus tard) ; servie seulement par DocumentController::photo. @param array<string,mixed> $file */
    private function setPhoto(int $id, array $file, int $userId): void
    {
        $fileId = $this->uploads->store($file, 'image', false, $userId);
        $this->db->update('members', ['photo_file_id' => $fileId], ['id' => $id]);
        $this->audit->log($userId, 'member.photo_set', 'member', $id, null, ['file_id' => $fileId]);
    }

    private function noZone(Request $r): Response
    {
        return new Response($this->view->render('errors/403', ['title' => 'Accès refusé']), 403);
    }

    /**
     * @param array<string,mixed>|null $member null : création @param array<string,mixed> $values
     * @param array<string,string> $errors erreurs par champ (photo)
     */
    private function form(Request $r, ?array $member, array $values, ?string $error = null, array $errors = []): Response
    {
        $userId = (int) $r->attribute('user')['id'];
        $db = $this->db;
        $opts = static fn (string $table, mixed $current, string $first) => ['' => $first] + array_column(
            $db->fetchAll("SELECT id, name FROM $table WHERE is_active = 1 OR id = ? ORDER BY name", [(int) $current]), 'name', 'id');
        $scopeAll = $this->scope->forUser($userId) === null;
        $title = $member === null ? 'Nouvel adhérent' : 'Modifier : ' . $member['last_name'] . ' ' . $member['first_names'];
        return $this->page($r, 'admin/members/form', $title, [
            'member' => $member,
            'values' => array_map(static fn ($v) => is_string($v) ? $v : '', $values),
            'error' => $error,
            'errors' => $errors,
            'docs' => $this->gate->allows($userId, 'members.documents.view'),
            'scopeAll' => $scopeAll,
            'zones' => $opts('zones', $values['zone_id'] ?? 0, 'Aucune'),
            'numberPending' => !(new \App\Core\Settings($this->db))->isValidated('members', 'number_format'),
            'zoneName' => (string) $this->db->fetchValue('SELECT name FROM zones WHERE id = ?', [(int) ($values['zone_id'] ?? 0)]),
            'categories' => $opts('member_categories', $values['category_id'] ?? 0, 'Aucune'),
            'professions' => $opts('maritime_professions', $values['profession_id'] ?? 0, 'Aucune'),
            'specialties' => $opts('maritime_specialties', $values['specialty_id'] ?? 0, 'Aucune'),
            'idTypes' => ['' => 'Non renseigné'] + array_column($this->db->fetchAll('SELECT code, label FROM member_document_types ORDER BY label'), 'label', 'code'),
        ], $error === null && $errors === [] ? 200 : 422);
    }

    /**
     * Page intermédiaire : seuls les homonymes du périmètre sont détaillés, les autres sont comptés sans rien révéler.
     * @param array<string,mixed> $data @param array{visible: list<array<string,mixed>>, hidden_count: int} $found
     */
    private function duplicates(Request $r, array $data, array $found, bool $hadPhoto): Response
    {
        return $this->page($r, 'admin/members/duplicates', 'Homonymes possibles', [
            'data' => $data, 'found' => $found, 'hadPhoto' => $hadPhoto,
            'zones' => array_column($this->db->fetchAll('SELECT id, name FROM zones'), 'name', 'id'),
        ]);
    }

    /** @param array<string,mixed> $data */
    private function page(Request $r, string $view, string $title, array $data, int $status = 200): Response
    {
        $user = $r->attribute('user');
        $error = $this->session->pullFlash('error');
        return new Response($this->view->render($view, [
            'title' => $title,
            'user' => $user,
            'can' => fn (string $p): bool => $this->gate->allows((int) $user['id'], $p),
            'csrfToken' => $this->csrf->token(),
            'flash' => $error ?? $this->session->pullFlash('status'),
            'flashKind' => $error !== null ? 'danger' : 'ok',
        ] + $data, 'layouts/admin'), $status);
    }
}
