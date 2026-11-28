# Lot L5 — Adhérents — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fichier central des adhérents utilisable pour le recensement : référentiels, fiche adhérent, instruction des demandes d'adhésion saisies, changements de statut tracés, recherche, exports, carte de membre avec QR et page de vérification, périmètre « délégué ». Toute règle que le cahier des charges ne fixe pas reste « non configuré ».

**Architecture:** Un module `app/Modules/Members/` (services + contrôleurs, même découpage que `Users`), migrations 0007 (tables) et 0008 (permissions, zone du délégué), vues `app/views/admin/members/*` bâties sur le kit `ui()` du lot L2. Règles paramétrables lues dans `Settings` (statut `non_configure` tant que les instances n'ont pas validé). Le périmètre d'un délégué est un filtre serveur appliqué dans une seule classe (`MemberScope`), jamais dans les vues.

**Tech Stack:** PHP 8.2 sans framework, MariaDB/MySQL (PDO), PHPUnit ; seul fichier tiers : `qrcode-generator` (MIT, un fichier JS ou PHP) pour le QR, copié dans le projet.

**Spec:** `docs/conception/{01-analyse,03-architecture,04-base-de-donnees}.md` (§ A5.1, A6, 3.3), `docs/conception/06-plan-taches.md` (lot L5, T5.1–T5.9), cahier des charges art. 5–8. Décisions L1/L2 : `docs/superpowers/plans/2026-11-15-l1-decisions-et-reports.md`, `…-l2-decisions-et-reports.md`.

## Global Constraints

- PHP ≥ 8.2, `declare(strict_types=1)`, aucune dépendance Composer en production ; requêtes préparées PDO ; migrations versionnées (0007, 0008 ; ne jamais modifier une migration appliquée).
- Africa/Abidjan, XOF, interface en français ; aucun style ni script en ligne (CSP) ; kit `ui()` et jetons du lot L2 ; une seule `h1` par page ; cibles tactiles ≥ 44 px au toucher ; téléphone d'abord.
- **Jamais de règle métier inventée** : toute valeur non fixée par le cahier des charges (format du numéro, statuts, conditions de la carte, durée de validité, pièces exigées pour valider, durée de conservation) est un paramètre `settings` en `non_configure`, ou un référentiel administrable marqué « proposé », avec le bandeau « paramètre en attente de validation par les instances ». Rien n'est bloqué par un paramètre non configuré sauf mention contraire.
- Chaque écriture est auditée (`Audit`) ; la consultation des pièces d'identité et du livret maritime est journalisée (`SensitiveAccess`) et exige `members.documents.view`.
- Permissions existantes : `members.view|create|update|validate|export|documents.view`. Nouvelles (migration 0008) : `members.status` (changer le statut), `members.scope.all` (voir tous les adhérents ; sans elle, accès limité à la zone de l'utilisateur).
- Pas de module cotisations, demandes d'assistance ni Espace adhérent ici (lots L6, L7, L15) : les onglets correspondants de la fiche affichent un état vide honnête.
- Tout route `/admin` suit `[$requireAuth, $permission('x')]` (le test de balayage `AdminRouteSweepTest` doit rester vert).

## Review Focus

1. **Accès hors périmètre par identifiant deviné** : un délégué ouvre `/admin/adherents/{id}`, une pièce ou une carte d'un membre d'une autre zone → 404 (pas 403, pour ne pas révéler l'existence).
2. **Validation simultanée** : deux gestionnaires valident la même demande en même temps → un seul membre, un seul numéro ; numéros jamais dupliqués sous concurrence.
3. **Injection dans les exports** : un nom commençant par `=`, `+`, `-`, `@` ne doit pas devenir une formule dans CSV/XLSX.
4. **Saisies hostiles ou atypiques** : noms avec accents/casse différente (Éric / ERIC / Eric) comptés comme doublons ; apostrophes, très longs noms, HTML ; adhérent sans téléphone ni e-mail ; date de naissance future ou absurde.
5. **Carte et QR** : jeton inconnu, révoqué ou d'un membre radié → « carte non valide » sans fuite d'information ; la page publique n'affiche que nom, n°, statut, validité.

---

## Fichiers

| Fichier | Responsabilité |
|---|---|
| `database/migrations/0007_members.sql` | tables du § 3.3 (référentiels, `members`, ayants droit, pièces, historique, demandes, cartes) |
| `database/migrations/0008_members_permissions.sql` | permissions `members.status`, `members.scope.all` + attribution ; `users.zone_id` |
| `app/Modules/Members/MemberScope.php` | filtre de périmètre (zone) appliqué à toute requête et à tout accès par id |
| `app/Modules/Members/Referentials.php` + `ReferentialController.php` | CRUD des référentiels |
| `app/Modules/Members/MemberService.php` | création/modification, numérotation, doublons, historique |
| `app/Modules/Members/MemberSearch.php` | recherche multicritère, tri, pagination |
| `app/Modules/Members/MemberController.php` | liste, fiche à onglets, formulaires |
| `app/Modules/Members/DocumentController.php` | ayants droit, pièces (stockage privé) |
| `app/Modules/Members/ApplicationService.php` + `ApplicationController.php` | demandes d'adhésion (saisie/délégué), instruction |
| `app/Modules/Members/StatusService.php` | changements de statut |
| `app/Modules/Members/MemberExport.php` | CSV/XLSX |
| `app/Modules/Members/CardService.php` + `CardController.php` + `VerifyController.php` | carte, QR, révocation, page publique |
| `app/views/admin/members/*`, `public/assets/js/qrcode.js` (tiers) | vues et QR |

---

### Task 1: Schéma, référentiels de base et périmètre

**Files:** Create `database/migrations/0007_members.sql`, `0008_members_permissions.sql`, `app/Modules/Members/MemberScope.php`, `app/Modules/Members/Referentials.php`, `app/Modules/Members/ReferentialController.php`, `app/views/admin/members/referentials.php` ; Modify `routes/admin.php`, `app/views/admin/_nav.php`, `app/Modules/Roles/ModuleLabels.php` (déjà « Adhérents »), `app/Modules/Users/UserController.php` + vue utilisateur (champ « Zone de rattachement ») ; Test `tests/Feature/MembersSchemaTest.php`, `tests/Feature/ReferentialsTest.php`, `tests/Unit/MemberScopeTest.php`

**Interfaces:**
- Produces:
  - Tables exactes du § 3.3 de `04-base-de-donnees.md` + `member_statuses(code PK, label, is_active, is_proposed)`, `member_document_types(code, label)` ; `users.zone_id BIGINT UNSIGNED NULL`.
  - Graines : catégories « Marins de la marine marchande », « Gens de mer », « Personnels navigants et assimilés », « Anciens marins » (art. 5) ; statuts `actif`, `suspendu`, `radie`, `decede`, `ancien_marin` avec `is_proposed = 1` ; types de pièces (art. 6) `identite`, `livret_maritime`, `photo`, `autre`. Aucune profession ni zone n'est semée.
  - `Settings->define('members','number_format','MMMGCI-{YYYY}-{NNNNN}')`, `('members','card_validity_months',null)`, `('members','card_conditions',null)`, `('members','retention_months',null)`.
  - `MemberScope::__construct(Database $db, Gate $gate)` ; `forUser(int $userId): ?int` (null = périmètre complet ; sinon id de zone) ; `where(int $userId, string $alias = 'm'): array{string,array}` ; `assertMayAccess(int $userId, int $memberId): void` lève `NotFound` si hors périmètre ; un utilisateur sans `members.scope.all` et sans zone voit **zéro** membre.
  - `Referentials` : `list(string $table)`, `save(string $table, ?int $id, array $data, int $actor)`, `setActive(...)` pour `member_categories`, `maritime_professions`, `maritime_specialties`, `zones` ; permission de gestion : `settings.manage`.
- Consumes: `Database`, `Audit`, `Gate`, `Settings`.

- [ ] **Step 1: Tests** — `MembersSchemaTest` : migrations appliquées, graines présentes, index `FULLTEXT(last_name, first_names)` ; `MemberScopeTest::test_user_without_scope_all_and_without_zone_sees_nobody`, `test_zone_user_sees_only_his_zone`, `test_scope_all_sees_everyone`, `test_out_of_scope_member_is_not_found` ; `ReferentialsTest` : création, désactivation (jamais de suppression d'un référentiel utilisé), 403 sans `settings.manage`, échappement d'un libellé hostile.
- [ ] **Step 2: FAIL**, puis migrations 0007/0008 (0008 attribue `members.scope.all` à tous les rôles qui détiennent `members.view` **sauf** `delegue` et `point_focal`, et `members.status` aux rôles qui détiennent `members.validate`).
- [ ] **Step 3: Implémenter** `MemberScope`, `Referentials`, contrôleur et écran `/admin/adherents/referentiels` (onglets Catégories / Professions / Spécialités / Zones, avec `tabs`), bandeau « proposé » pour les statuts ; champ « Zone de rattachement » dans le formulaire utilisateur (liste des zones, optionnel).
- [ ] **Step 4: PASS** (suite complète) ; **Step 5: Commit** `L5: schéma des adhérents, référentiels et périmètre`.

### Task 2: Service adhérent, numérotation, doublons, recherche

**Files:** Create `app/Modules/Members/MemberService.php`, `MemberSearch.php` ; Test `tests/Feature/MemberServiceTest.php`, `tests/Unit/DuplicateDetectionTest.php`, `tests/Feature/MemberSearchTest.php`

**Interfaces:**
- Produces:
  - `MemberService::create(array $data, int $actor): int` ; `update(int $id, array $data, int $actor): void` ; `findDuplicates(array $data, ?int $exceptId = null): array` (même `last_name` + `first_names` normalisés sans accents ni casse **et** même `birth_date`, ou même téléphone normalisé ; retourne les membres trouvés) ; `create` ne bloque pas un doublon, c'est le contrôleur qui exige une confirmation explicite (`confirm_duplicate=1`).
  - Numérotation : `Sequence::next('member', …)` ; format lu dans `Settings` (`members.number_format`, tokens `{YYYY}` et `{NNNNN}` uniquement) ; numéro attribué à la création, jamais modifiable.
  - Validation (via `Validator`) : nom et prénoms obligatoires (≤ 100), date de naissance passée et ≥ 1900-01-01, téléphone normalisé (chiffres, `+` initial), e-mail valide si présent, `category_id`, `zone_id`, `status` doivent exister ; une demande n'exige ni téléphone ni e-mail.
  - `MemberSearch::run(int $userId, array $criteria, string $sort, int $page): array{rows, total, pages}` ; critères : `q` (nom, prénoms, n°, téléphone), `status`, `category_id`, `zone_id`, `profession_id`, `joined_from`, `joined_to` ; 50 par page ; périmètre appliqué via `MemberScope::where`.
- [ ] **Step 1: Tests** (noms exacts) : `test_member_number_is_sequential_and_follows_the_configured_format`, `test_number_format_not_validated_still_uses_the_technical_default`, `test_duplicate_detected_ignoring_case_and_accents`, `test_duplicate_by_phone_with_different_formatting`, `test_future_birth_date_is_refused`, `test_member_without_phone_or_email_can_be_created`, `test_search_is_limited_to_the_users_zone`, `test_search_under_one_second_on_2000_members` (jeu de 2 000 générés ; seuil large 1 s).
- [ ] **Steps 2–4:** FAIL → implémenter → PASS ; **Step 5: Commit** `L5: service adhérent, numérotation, doublons et recherche`.

### Task 3: Liste et fiche adhérent (lecture)

**Files:** Create `app/Modules/Members/MemberController.php` (méthodes `index`, `show`), `app/views/admin/members/{index,show}.php`, onglets partiels `app/views/admin/members/tabs/*.php` ; Modify `routes/admin.php`, `app/views/admin/_nav.php` ; Test `tests/Feature/MembersListTest.php`

**Interfaces:**
- Routes : `GET /admin/adherents` et `GET /admin/adherents/{id}` avec `members.view` ; menu « Adhérents » visible si `members.view`.
- Liste : barre d'outils (recherche + bouton « Nouvel adhérent » si `members.create`), filtres repliables (statut, catégorie, zone, profession), tableau (nom + n°, catégorie, zone, statut en `badge` + texte, adhésion) ; sur téléphone liste à deux lignes ; compteur « N adhérents » ; état vide « Aucun adhérent pour le moment » avec lien de création.
- Fiche : en-tête (nom, n°, statut), onglets *Identité*, *Profession*, *Ayants droit*, *Pièces* (visible avec `members.documents.view`), *Cotisations*, *Demandes*, *Historique* ; les onglets *Cotisations* et *Demandes* affichent « Disponible avec le lot suivant » honnêtement (états vides) ; l'onglet *Historique* liste `member_status_history`.
- Un id hors périmètre ou inconnu → page 404.
- [ ] **Step 1: Tests** : `test_list_requires_members_view`, `test_list_shows_only_scoped_members_for_a_delegate`, `test_show_out_of_scope_member_is_404`, `test_hostile_names_are_escaped_in_list_and_show`, `test_documents_tab_hidden_without_permission`, `test_empty_list_shows_empty_state`.
- [ ] **Steps 2–4**, **Step 5: Commit** `L5: liste et fiche adhérent`.

### Task 4: Création, modification, ayants droit et pièces

**Files:** Modify `MemberController.php` (`createForm`, `store`, `editForm`, `update`) ; Create `app/Modules/Members/DocumentController.php`, vues `app/views/admin/members/{form,beneficiaries,documents}.php` ; Test `tests/Feature/MemberFormTest.php`, `tests/Feature/MemberDocumentsTest.php`

**Interfaces:**
- Routes : `GET|POST /admin/adherents/nouveau`, `GET|POST /admin/adherents/{id}/modifier` (`members.create` / `members.update`) ; `POST /admin/adherents/{id}/ayants-droit` et `…/{bid}/supprimer` ; `POST /admin/adherents/{id}/pieces`, `GET /admin/adherents/{id}/pieces/{fid}` (`members.documents.view`, journalisé par `SensitiveAccess`).
- Formulaire en deux colonnes de `field` (identité, coordonnées, profession/spécialité, situation professionnelle, livret maritime n° et échéance, zone, catégorie, photo optionnelle) ; si doublon détecté, page intermédiaire listant les homonymes avec « Créer quand même » (`confirm_duplicate`) ; un délégué crée dans **sa** zone uniquement (zone forcée côté serveur, le champ est ignoré).
- Pièces : profil `Uploads` « document » (PDF/JPG/PNG), stockage privé, type parmi `member_document_types` ; photo : profil « image ».
- [ ] **Step 1: Tests** : `test_create_member_requires_csrf_and_permission`, `test_duplicate_requires_explicit_confirmation`, `test_delegate_creation_forces_his_zone`, `test_update_is_audited_with_before_after`, `test_beneficiary_added_and_removed`, `test_document_download_is_logged_and_needs_documents_view`, `test_document_of_out_of_scope_member_is_404`, `test_document_of_another_member_is_not_reachable_by_guessing_file_id`.
- [ ] **Steps 2–4**, **Step 5: Commit** `L5: création, modification, ayants droit et pièces`.

### Task 5: Demandes d'adhésion et instruction

**Files:** Create `app/Modules/Members/ApplicationService.php`, `ApplicationController.php`, vues `app/views/admin/members/applications/*.php` ; Test `tests/Feature/ApplicationWorkflowTest.php`

**Interfaces:**
- États (`membership_applications.status`) : `recue`, `complements_demandes`, `en_verification`, `validee`, `rejetee` (diagramme A5.1). Source : `saisie` (gestionnaire) ou `delegue` ; `en_ligne` viendra du lot L4 et utilise le même service.
- `ApplicationService::submit(array $payload, string $source, ?int $actor, ?string $ip): int` (référence `AD-AAAA-NNNN` via `Sequence`) ; `requestComplements(int $id, string $note, int $actor)` ; `markVerifying(...)` ; `validate(int $id, int $actor): int` (crée le membre via `MemberService::create`, lie `member_id`, ajoute l'entrée d'historique, **verrouille la ligne** `FOR UPDATE` et refuse si l'état n'est plus `en_verification`/`recue`) ; `reject(int $id, string $reason, int $actor)` (motif obligatoire).
- Routes sous `/admin/adherents/demandes` : liste (filtre par état), saisie, fiche de demande avec le composant `validation-steps`, actions ; `members.create` pour saisir, `members.validate` pour valider/rejeter ; périmètre délégué : voit ses propres saisies.
- Pas de règle de « dossier complet » inventée : la fiche de demande affiche la liste des pièces de l'art. 6 comme **aide**, sans bloquer la validation.
- [ ] **Step 1: Tests** : `test_validation_creates_exactly_one_member_and_links_it`, `test_double_validation_creates_one_member_only` (deux appels successifs, le second est refusé), `test_reject_without_reason_is_impossible`, `test_validated_application_cannot_be_rejected`, `test_delegate_sees_only_his_applications`, `test_validation_requires_members_validate`.
- [ ] **Steps 2–4**, **Step 5: Commit** `L5: instruction des demandes d'adhésion`.

### Task 6: Changements de statut

**Files:** Create `app/Modules/Members/StatusService.php`, vue `app/views/admin/members/status.php` ; Modify `MemberController.php`, `tabs/history.php` ; Test `tests/Feature/StatusChangeTest.php`

**Interfaces:**
- `StatusService::change(int $memberId, string $toStatus, string $reason, string $decisionRef, int $actor): void` : `members.status` requis ; motif **et** référence de décision obligatoires ; le statut cible doit exister et être actif dans `member_statuses` ; écrit `member_status_history` (ajout seul), `members.status`, `status_changed_at`, audit ; aucune règle de transition inventée (toute transition vers un statut existant est possible), mais un changement identique est refusé.
- Route `POST /admin/adherents/{id}/statut` ; modale `confirm-dialog` avec champs motif et référence ; l'onglet Historique affiche tout.
- [ ] **Step 1: Tests** : `test_status_change_requires_reason_and_decision_reference`, `test_history_is_append_only_and_complete`, `test_same_status_is_refused`, `test_unknown_or_inactive_status_is_refused`, `test_requires_members_status_permission`, `test_out_of_scope_member_is_404`.
- [ ] **Steps 2–4**, **Step 5: Commit** `L5: changements de statut tracés`.

### Task 7: Exports

**Files:** Create `app/Modules/Members/MemberExport.php` ; Modify `MemberController.php`, vue liste (bouton « Exporter ») ; Test `tests/Feature/MemberExportTest.php`

**Interfaces:**
- `GET /admin/adherents/export.csv` et `export.xlsx` avec `members.export`, mêmes critères que la liste, périmètre appliqué ; chaque export est audité (nombre de lignes, critères) ; colonnes : n°, nom, prénoms, catégorie, profession, zone, statut, date d'adhésion, téléphone, e-mail (aucune pièce, aucune photo).
- Anti-injection : toute cellule commençant par `=`, `+`, `-`, `@`, tabulation ou retour chariot est préfixée d'une apostrophe dans `Csv` et `Xlsx` (corriger `app/Core/Export/*` si absent, avec test).
- Impression : bouton « Imprimer la liste » via `layouts/print` (gabarit L2).
- [ ] **Step 1: Tests** : `test_export_requires_members_export_and_is_403_otherwise`, `test_export_is_audited`, `test_formula_like_cells_are_neutralised_in_csv_and_xlsx`, `test_export_respects_delegate_scope`.
- [ ] **Steps 2–4**, **Step 5: Commit** `L5: exports sécurisés des adhérents`.

### Task 8: Carte de membre, QR et vérification publique

**Files:** Create `app/Modules/Members/CardService.php`, `CardController.php`, `VerifyController.php`, `public/assets/js/qrcode.js` (tiers, licence MIT copiée en tête), `app/views/admin/members/card.php`, `app/views/public/verify.php` ; Modify `routes/web.php`, `routes/admin.php` ; Test `tests/Feature/MemberCardTest.php`

**Interfaces:**
- `CardService::issue(int $memberId, int $actor): array` (jeton aléatoire 32 octets hex dans `verify_token`, `card_number`, `expires_at` = `issued_at + members.card_validity_months` **seulement si ce paramètre est validé**, sinon `NULL` affiché « validité non définie ») ; si `members.card_conditions` est validé et non satisfait, la délivrance est refusée avec le texte de la condition ; sinon délivrance manuelle par `members.update` avec bandeau « conditions de délivrance en attente de validation par les instances » ; `revoke(int $cardId, string $reason, int $actor)` ; une seule carte active par membre (la nouvelle révoque l'ancienne).
- Carte imprimable : gabarit `.card-cr80` du lot L2 (nom, n°, photo si présente, QR vers `/verifier/{jeton}`), `layouts/print` ; le QR est dessiné côté navigateur par `qrcode.js` (fichier externe, CSP respectée), sans le jeton dans les journaux d'accès applicatifs autres que l'URL publique.
- `GET /verifier/{jeton}` (publique, sans session ni cookie) : affiche **uniquement** nom, n°, statut, validité ; jeton inconnu, révoqué, expiré ou membre au statut non actif (`radie`, `decede`) → page neutre « Carte non valide » avec le même code HTTP 200 et les mêmes éléments de page (pas de fuite) ; limitation de débit par IP (réutiliser `LoginThrottle` ou un compteur simple).
- [ ] **Step 1: Tests** : `test_issue_creates_unguessable_token_and_revokes_the_previous_card`, `test_validity_is_null_when_setting_not_validated`, `test_verify_shows_only_name_number_status_validity`, `test_unknown_revoked_and_struck_off_cards_look_identical`, `test_verify_page_sets_no_cookie_and_starts_no_session`, `test_card_print_view_has_qr_container_and_no_inline_script`.
- [ ] **Steps 2–4** ; contrôle visuel de la carte imprimée (PDF de contrôle, non commité) ; **Step 5: Commit** `L5: carte de membre, QR et vérification publique`.

### Task 9: Contrôle de qualité, périmètre de bout en bout et documentation

**Files:** Modify selon les constats ; Test `tests/Feature/MembersEndToEndTest.php` ; Modify `docs/conception/05-design-system.md` (si composant ajouté), `README.md`, `TASKS.md`

- [ ] **Step 1:** parcours de bout en bout en test : délégué saisit une demande → gestionnaire valide → membre visible dans la liste du bon périmètre, invisible d'un autre délégué → statut changé → export → carte émise → vérification publique.
- [ ] **Step 2:** `lint.mjs` / `inspect.mjs --app` (Taste) sur liste, fiche, formulaire, demandes, référentiels, carte à 1440/768/390 ; 0 FAIL ; `impeccable detect` ; 360 px sans défilement horizontal ; `bin/check-contrast.php` vert.
- [ ] **Step 3:** mesure de performance de la recherche sur 2 000 membres (rapportée) ; vérifier que `AdminRouteSweepTest` couvre toutes les nouvelles routes.
- [ ] **Step 4:** documentation en français : README (§ Adhérents), TASKS.md (T5.1–T5.9, reports : instruction en ligne → L4, Espace adhérent → L15, anonymisation → après validation de la durée de conservation) ; liste des paramètres « non configuré » à faire valider.
- [ ] **Step 5:** suite complète verte ; commit `L5: contrôle de qualité et documentation`.

---

## Rulings de conception (à confirmer par les instances si besoin)

- **Numéro de membre** : le cahier des charges ne fixe pas le format (V11). Le format technique `MMMGCI-AAAA-NNNNN` est utilisé par défaut, affiché « paramètre à valider » ; coût si les instances en choisissent un autre : renumérotation à prévoir (non incluse).
- **Statuts** : liste proposée `actif/suspendu/radie/decede/ancien_marin` marquée « proposée » (V8) ; aucune règle de transition ni de conséquence automatique n'est inventée.
- **Validité et conditions de carte** : non configurées → carte sans date d'expiration, délivrance manuelle (V5).
- **Dossier complet** : non défini → la liste des pièces est une aide, pas un blocage.
- **Périmètre délégué** : par zone de rattachement de l'utilisateur ; sans `members.scope.all` ni zone, on ne voit personne (sûr par défaut).
- **Conservation / anonymisation** : colonne prévue, aucune purge tant que `members.retention_months` n'est pas validé.
