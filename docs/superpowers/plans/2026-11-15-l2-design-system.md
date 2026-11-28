# Lot L2 — Design system et gabarits d'interface — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Donner à la plateforme son identité visuelle et ses écrans de base : jetons de design, kit de composants, gabarit d'administration (téléphone d'abord), gabarit public, gabarit d'impression ; puis habiller tous les écrans existants (connexion, utilisateurs, rôles, journal d'audit) sans changer leur comportement.

**Architecture:** CSS écrit à la main, sans étape de compilation, servi par `public/assets/` sous une politique CSP stricte (aucun style ni script en ligne). Les composants sont des gabarits PHP `app/views/components/*.php` appelés par un helper `ui()`. Trois gabarits de page (`layouts/admin.php`, `layouts/public.php`, `layouts/print.php`) partagent les mêmes jetons. Contrôle visuel par les scripts du skill Taste (`lint.mjs`, `inspect.mjs --app`) et par l'outil de détection d'Impeccable.

**Tech Stack:** PHP 8.2 (socle L0/L1), CSS3 avec variables, JavaScript natif minimal (un seul fichier), SVG, Chrome headless (contrôle, développement seulement).

**Spec:** `docs/conception/05-design-system.md` (jetons, composants, §7 direction du site public), `docs/conception/maquettes/design-system.html`, `PRODUCT.md`, décisions L1 : `docs/superpowers/plans/2026-11-15-l1-decisions-et-reports.md`.

## Direction visuelle (décisions de conception)

| Surface | Mode | Direction retenue |
|---|---|---|
| Gestion et CMS (`/admin`) | Opérer | **Style « feuille encadrée » du skill Taste** (`draw.mjs --app --kind smb-admin`, graine 34426040) : un cadre teinté en L (barre latérale + barre du haut, sans filet entre eux) et une feuille blanche, bord 1 px, angles de 16 px, sans ombre. Couleur sous 3 % : la marque se réduit à l'emblème et au soulignement de l'onglet actif ; ce sont les données qui portent la couleur. Thème clair (usage de jour, au siège et en extérieur sur téléphone). |
| Site public (`/`) | Persuader | **« La page est l'objet »** (direction Taste du 14/11, graine 878571445) : cartes imprimées à bande de cordage, filet national fin, héro marine. Le gabarit seul est livré en L2 ; les 18 pages sont au lot L4. |
| Documents imprimés | Lire | Feuille A4 sobre et carte au format CR80, en noir et blanc lisible. |

Choix propres au produit (exigés par le skill pour éviter un rendu « kit générique ») :
- **Teinte du cadre :** un bleu-gris très pâle tiré du marine du logo (`--frame: #F1F4F9`), la feuille reste blanche.
- **Accent unique :** marine `#12306B` ; l'or `#D2AE55` est réservé au site public et à l'état « en attente de validation ». Aucune autre couleur d'accent.
- **Polices :** Marcellus (titres, comme le lettrage du logo) et Source Sans 3 (texte, formulaires, tableaux, chiffres tabulaires), auto-hébergées (OFL). Pas de police système en titre.
- **L'élément propre à la Mutuelle :** le **circuit de validation** d'un décaissement (étapes initiée → 1ʳᵉ approbation → 2ᵉ approbation → paiement), composant `validation-steps` construit ici et utilisé à partir du lot L6.
- **Mots du métier** dans les libellés et états vides : « Aucune demande d'assistance pour le moment », « Aucune cotisation à encaisser », jamais « Éléments » ou « Enregistrements ».
- **Logo :** logo complet (obligatoire) à 104 px de haut sur ordinateur dans l'en-tête public ; **emblème seul** dans la barre latérale, sur mobile et dans l'administration. Version vectorielle ou PNG transparent à fournir par la Mutuelle (le fichier actuel est un JPEG sur fond blanc : l'emblème est détouré en cercle pour ce lot).

## Global Constraints

- Contraintes L0/L1 inchangées : `declare(strict_types=1)` ; aucune dépendance PHP tierce ; échappement `e()` de toute valeur ; formulaires avec jeton CSRF ; messages en français disant le problème **et** la correction.
- **CSP inchangée** (`default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:`) : **aucun** attribut `style=`, aucun `<style>`, aucun `<script>` en ligne, aucun gestionnaire `onclick=` dans les vues ; polices et images servies depuis `public/assets/`.
- **Aucun outil de compilation** : CSS et JS écrits à la main, servis tels quels ; au plus un fichier JS (`public/assets/js/app.js`, < 8 Ko), sans bibliothèque.
- Téléphone d'abord : tout écran utilisable à **360 px** de large, sans défilement horizontal de la page ; cibles tactiles ≥ 44 px ; mise en page de tableau → liste à deux lignes sous 640 px.
- Accessibilité WCAG 2.2 AA : contraste ≥ 4,5:1 (texte) et 3:1 (grands textes, bordures de champs), focus visible sur tout contrôle, utilisable au clavier seul, `lang="fr"`, un seul `h1` par page, libellé associé à chaque champ, erreurs liées par `aria-describedby`, `prefers-reduced-motion` respecté.
- Règles maison Taste pour l'application : couleur sur les données, pas sur le cadre ; KPI en texte (jamais d'icône dans un carré teinté ni de sparkline) ; surfaces plates, un seul pas de ton entre cadre, feuille et carte ; pas de dégradé, d'ombre au repos, de verre, d'émoji ; icônes dessinées d'un seul trait (1,75 px) ; chiffres tabulaires dans les tableaux ; zéros affichés comme zéros.
- Les valeurs de démonstration n'existent que dans la galerie de composants et les tests : jamais de chiffre fictif dans un écran de production.
- Montants affichés avec `Money::format` ; dates en `JJ/MM/AAAA`, heure d'Africa/Abidjan.

## Review Focus

1. **Injection par un composant** : `<script>` ou `"><img onerror>` passé comme libellé, valeur, titre ou message d'erreur d'un composant doit sortir échappé ; seul le paramètre `slot` (HTML déjà rendu par une vue de confiance) est injecté tel quel. → Task 2.
2. **Style ou script en ligne glissé dans une vue** : un test parcourt `app/views/**` et échoue sur `style=`, `<style`, `<script`, `onclick=`, `onchange=`, `javascript:`. → Task 2.
3. **Téléphone** : un tableau de 8 colonnes, un formulaire long et un nom de 60 caractères sans espace ne doivent produire aucun défilement horizontal de la page à 360 px (le défilement interne d'un tableau est toléré seulement pour les journaux). → Tasks 3, 4, 7.
4. **Clavier et lecteur d'écran** : menu mobile ouvrable et fermable au clavier (Échap), focus piégé dans la boîte de confirmation, page de connexion avec erreur annoncée (`role="alert"`) et champ fautif lié. → Tasks 2, 3, 4.
5. **Police ou image manquante** : si une police ne charge pas, le texte reste lisible avec la police de repli (métriques voisines), et l'emblème a un texte alternatif. → Tasks 1, 7.

---

## File Structure

```
public/assets/
  css/  tokens.css  base.css  components.css  admin.css  public.css  print.css
  js/   app.js
  fonts/  marcellus-400.woff2  source-sans-3-{400,600,700}.woff2   (+ LICENSE-OFL.txt)
  img/  emblem-{40,80,160,320}.png  logo-complet.jpg  logo-complet@2x.jpg  icons.svg  favicon.png
app/helpers.php                       # + ui() , ui_icon()
app/Core/Ui.php                       # résolution et rendu des composants (échappement, slot)
app/views/components/                 # button field alert badge panel page-header tabs table pagination
                                      # empty-state confirm-dialog validation-steps breadcrumbs file-input
app/views/layouts/{admin,public,print}.php
app/views/admin/design/index.php      # galerie (hors production)
app/Modules/Roles/ModuleLabels.php    # noms français des modules de permissions
bin/make-emblem.sh                    # détourage de l'emblème depuis le logo (reproductible)
tests/Unit/UiTest.php  tests/Feature/{ViewsPolicyTest,DesignGalleryTest}.php
docs/conception/05-design-system.md   # §8 « Implémenté en L2 »
```

---

### Task 1: Ressources et jetons (polices, emblème, icônes, CSS de base)

**Files:** Create `public/assets/**` (voir plus haut sauf composants), `bin/make-emblem.sh` · Test `tests/Unit/AssetsTest.php`

**Interfaces:**
- Produces:
  - `tokens.css` : variables CSS sur `:root` — couleurs (`--navy-950/900/800/700`, `--ocean-600/100/50`, `--gold-700/400/100`, `--ink`, `--ink-2`, `--ink-3`, `--line`, `--line-strong`, `--frame: #F1F4F9`, `--sheet: #fff`, états `--ok/--ok-bg/--warn/--warn-bg/--danger/--danger-bg/--info/--info-bg`, `--ci-orange/--ci-green`), typographie (`--font-display`, `--font-body`, échelle `--text-xs…--text-3xl`, interlignes), espacements `--s1…--s9` (4, 8, 12, 16, 24, 32, 48, 64, 96 px), rayons `--r-sm 6px / --r-md 10px / --r-lg 16px`, `--control-h: 44px`, `--focus-ring`, durées de mouvement. **Valeurs exactes de la maquette** `docs/conception/maquettes/design-system.html` (bloc `:root`) pour les couleurs, corrigées uniquement si un contraste mesuré < 4,5:1 l'exige (dans ce cas, noter l'écart dans le rapport).
  - `base.css` : remise à zéro, `@font-face` (`font-display: swap`, polices de repli aux métriques proches via `size-adjust`), échelle typographique, liens soulignés avec décalage, `:focus-visible`, `::selection` aux couleurs de marque, barres de défilement thématisées, `font-variant-numeric: tabular-nums` sur `.num`, `@media (prefers-reduced-motion: reduce)`, classe `.visually-hidden`.
  - Polices : Marcellus Regular et Source Sans 3 (400/600/700) en `woff2` latin + latin-étendu minimal pour le français (U+0000–00FF, U+0131, U+0152–0153, U+02C6, U+02DA, U+02DC, U+2000–206F, U+20AC, U+2122) ; obtenues depuis les sources OFL (dépôt `google/fonts` sur GitHub ou API Google Fonts) et converties si besoin ; `LICENSE-OFL.txt` joint. **Si aucune source n'est joignable depuis l'environnement, utiliser la pile de repli, le signaler en tête du rapport et consigner le téléchargement manuel dans le README** (la tâche reste valide : les tests ne dépendent pas des fichiers de police).
  - Emblème : `bin/make-emblem.sh` rogne le cercle du logo (`docs/conception/maquettes/logo-mmmgci-mohs.jpg`) avec `ffmpeg` (masque circulaire alpha) et produit `emblem-40/80/160/320.png` ; le logo complet est copié en `logo-complet.jpg` (104 px de haut) et `@2x`. Script reproductible, sortie commitée.
  - `icons.svg` : sprite de `<symbol id="…">` au trait unique de 1,75 px, 24×24, `currentColor` : `home users user-plus inbox coins shield heart anchor handshake landmark chart file search check x info alert menu plus chevron-down chevron-right logout lock key eye download print upload calendar clock`. Dessinées à la main (pas d'emoji ni de police d'icônes).

- [ ] **Step 1: Tests**

```php
// AssetsTest
public function test_all_css_files_exist_and_forbid_external_urls(): void       // aucune url(http…) ni @import distant
public function test_tokens_define_every_variable_used_by_other_css_files(): void  // var(--x) utilisé ⊂ défini
public function test_icon_sprite_has_every_icon_listed_in_the_plan(): void
public function test_emblem_pngs_exist_with_transparency_and_expected_sizes(): void   // getimagesize + pixel (0,0) alpha=127
public function test_font_face_declarations_point_to_existing_files_or_are_absent(): void
```

- [ ] **Steps 2–4:** FAIL → créer les ressources → PASS. Vérification visuelle : page `file://` ou `php -S` qui affiche les jetons (palette, échelle typo, espacements) — capture jointe au rapport.
- [ ] **Step 5: Commit** `L2: jetons, polices, emblème et icônes`

### Task 2: Kit de composants et galerie

**Files:** Create `app/Core/Ui.php`, `app/views/components/*.php`, `public/assets/css/components.css`, `public/assets/js/app.js`, `app/views/admin/design/index.php` · Modify `app/helpers.php`, `routes/admin.php` · Test `tests/Unit/UiTest.php`, `tests/Feature/ViewsPolicyTest.php`, `tests/Feature/DesignGalleryTest.php`

**Interfaces:**
- Produces:
  - `ui(string $component, array $props = [], string $slot = ''): string` — rend `app/views/components/<component>.php` avec `$props` ; **toute** valeur de `$props` est échappée par le composant via `e()` ; `$slot` est du HTML déjà rendu et de confiance (jamais une saisie utilisateur). `ui_icon(string $name, string $label = ''): string` — `<svg class="icon" aria-hidden="true"|role="img" aria-label=…><use href="/assets/img/icons.svg#name"/></svg>` ; nom d'icône hors liste blanche → exception.
  - Composants et leurs `props` :
    | composant | props |
    |---|---|
    | `button` | `label`, `variant` (`primary`,`secondary`,`danger`,`ghost`), `type` (`button`,`submit`), `href` (→ `<a>`), `icon`, `disabled`, `busy`, `size` |
    | `field` | `name`, `label`, `type`, `value`, `error`, `hint`, `required`, `autocomplete`, `inputmode`, `options` (select) ; génère `id`, `for`, `aria-describedby`, `aria-invalid`, message d'erreur avec `role` approprié |
    | `alert` | `kind` (`info`,`ok`,`warn`,`danger`), `title`, `message` ; `danger` → `role="alert"`, autres → `role="status"` |
    | `badge` | `label`, `kind` (`ok`,`warn`,`danger`,`info`,`neutral`) ; toujours un libellé texte (jamais la couleur seule) |
    | `panel` | `title`, `actions` (slot) |
    | `page-header` | `title` (le seul `h1`), `subtitle`, `breadcrumbs` (liste `[label, href?]`), slot = actions (un seul bouton primaire) |
    | `tabs` | `items` (`[label, href, current]`), `label` (nom accessible) — navigation par liens, `aria-current="page"` |
    | `table` | `columns` (`[key, label, align, numeric]`), `rows`, `empty` (props de `empty-state`), `caption` ; en téléphone devient une liste ; chiffres alignés à droite en `.num` ; `row_actions` (slot par ligne optionnel) |
    | `pagination` | `page`, `pages`, `base` (URL), `query` ; liens précédent/suivant et numéros, `aria-label="Pagination"` |
    | `empty-state` | `title`, `message`, `action` (props de `button`) |
    | `confirm-dialog` | `id`, `title`, `message`, `confirm_label`, `form_action` ; `<dialog>` natif, ouvert par `data-confirm-open="<id>"`, piège le focus, Échap ferme |
    | `validation-steps` | `steps` : liste de `{label, who, when, state}` avec `state ∈ done|current|todo|rejected` ; composant signature (circuit de validation) |
    | `file-input` | `name`, `label`, `accept`, `hint`, `error`, `max_mb` ; aperçu du nom et de la taille sans script d'envoi |
    | `breadcrumbs` | `items` |
  - `app.js` : (1) ouverture/fermeture du menu mobile (`data-menu-toggle`, Échap, retour du focus) ; (2) boîtes de confirmation (`data-confirm-open`) ; (3) double-soumission : désactive le bouton `type=submit` et ajoute `aria-busy` à l'envoi d'un formulaire ; (4) affichage du nom du fichier choisi ; (5) rien d'autre. Fonctionne sans bibliothèque, dégrade proprement sans JS (le menu reste un lien « Menu » vers une ancre ; les boutons de confirmation restent des formulaires).
  - Galerie `GET /admin/composants` (`[$requireAuth, $permission('users.manage')]`, répond **404 si `APP_ENV` = `production`**) : tous les composants dans tous leurs états (normal, survol non applicable, désactivé, chargement, erreur, vide), données de démonstration locales à la vue. Sert aux tests, au contrôle visuel et de référence pour les lots suivants. Mise à jour du test de balayage si nécessaire pour qu'il reste vert.
- [ ] **Step 1: Tests**

```php
// UiTest
public function test_every_prop_is_escaped(): void                 // boucle sur tous les composants avec "<script>x</script>" et '"><img src=x onerror=a>' partout sauf slot (Review Focus 1)
public function test_slot_is_injected_unescaped_and_only_there(): void
public function test_unknown_component_throws_a_clear_exception(): void
public function test_icon_name_outside_whitelist_throws(): void
public function test_field_links_label_error_and_hint_with_ids(): void   // for/id, aria-describedby, aria-invalid
public function test_alert_danger_has_role_alert_and_ok_has_role_status(): void
public function test_badge_always_contains_text(): void
public function test_table_renders_empty_state_and_right_aligned_numbers(): void
public function test_pagination_hides_when_one_page_and_builds_links_with_query(): void
public function test_validation_steps_marks_current_step_and_exposes_state_as_text(): void  // pas seulement la couleur
// ViewsPolicyTest
public function test_no_inline_style_or_script_or_event_handler_in_any_view(): void   // Review Focus 2 — échoue sur app/views/** et public/assets/js
// DesignGalleryTest
public function test_gallery_is_404_in_production_and_200_for_admin_elsewhere(): void
public function test_gallery_requires_users_manage(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS ; contrôle visuel de la galerie à 1440/768/390 px (captures + `inspect.mjs --app`, voir Task 7 pour les commandes ; ici un premier passage pour corriger tôt).
- [ ] **Step 5: Commit** `L2: kit de composants et galerie`

### Task 3: Gabarit d'administration (feuille encadrée, téléphone d'abord)

**Files:** Create `public/assets/css/admin.css` ; Modify `app/views/layouts/admin.php` · Test `tests/Feature/AdminShellTest.php`

**Interfaces:**
- Consumes: composants (Task 2), `$can`, `$user`, `$flash`, `$title`.
- Produces `layouts/admin.php` :
  - Cadre teinté `--frame` ; barre latérale 240 px (ordinateur) avec l'emblème 40 px + « MMMGCI », groupes de liens **filtrés par permission** — *Tableau de bord* ; *Utilisateurs* (`users.manage`), *Rôles* (`roles.manage`), *Journal d'audit* (`audit.view`) ; les groupes des lots futurs (Membres, Finances, Accompagnement, Institution, Site web) sont définis dans une **liste de navigation unique** `app/views/admin/_nav.php` (tableau `[groupe, libellé, href, permission, icône]`) : un lien n'apparaît que si la permission est détenue **et** la route existe (`Router::routes()` ou drapeau `enabled`), pour que l'ajout d'un module n'exige qu'une ligne.
  - Barre du haut 48 px, sans filet entre elle et la barre latérale : bouton de menu (téléphone), nom de la page courante, bouton du compte (nom, « Mon mot de passe », « Se déconnecter » en formulaire POST).
  - Feuille blanche : retrait de 8 px à droite et en bas, angles 16 px, bord 1 px `--line`, sans ombre ; remplissage intérieur 24 px (16 px sur téléphone).
  - Lien d'évitement « Aller au contenu » ; `<main id="contenu">` ; zone de messages (`flash`) en `alert` avec `role="status"` ; titre de page via `page-header`.
  - **Téléphone (< 900 px)** : le cadre disparaît, la feuille occupe l'écran ; barre du haut 48 px avec menu / titre / compte ; le menu s'ouvre en panneau plein hauteur (focus piégé, Échap, retour du focus sur le bouton).
  - `Cache-Control: no-store` conservé sur les pages sensibles par leurs contrôleurs (non modifié ici).
- [ ] **Step 1: Tests**

```php
public function test_menu_shows_only_links_the_user_may_use(): void                // lecture_seule : seulement le tableau de bord ; super_admin : users, roles, journal
public function test_current_page_link_has_aria_current(): void
public function test_logout_is_a_post_form_with_csrf(): void
public function test_skip_link_and_single_h1_and_lang_fr(): void
public function test_flash_message_is_rendered_with_role_status_and_escaped(): void
public function test_menu_toggle_has_accessible_name_and_aria_expanded(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS ; contrôle visuel à 1440/768/390.
- [ ] **Step 5: Commit** `L2: gabarit d'administration`

### Task 4: Habillage des écrans existants

**Files:** Modify `app/views/auth/{login,forgot,reset}.php`, `app/views/admin/{dashboard,users/*,roles/*,audit/*,account/*}.php`, `app/views/errors/{400,403,404,405,419}.php`, `app/views/layouts/base.php` (pages publiques d'erreur et de connexion) ; Create `app/Modules/Roles/ModuleLabels.php`, `public/assets/css/auth.css` · Test : mise à jour des tests de fonctionnalités existants ; `tests/Feature/ScreensTest.php`

**Interfaces:**
- Produces:
  - **Connexion / mot de passe oublié / réinitialisation** : page centrée sur fond `--frame`, carte blanche 400 px, emblème 80 px, titre Marcellus, champs `field`, bouton principal pleine largeur, lien « Mot de passe oublié ? », erreur en `alert` `role="alert"` **et** liée au champ ; texte de la politique de mot de passe visible à côté du champ de nouveau mot de passe.
  - **Tableau de bord** : titre « Bonjour {prénom} », « À traiter » = état vide honnête (« Rien à traiter pour le moment ») tant qu'aucun module n'alimente la liste ; raccourcis (cartes plates) vers les écrans autorisés ; **aucun indicateur chiffré** (viennent au lot L11).
  - **Utilisateurs** : barre d'outils (recherche, bouton « Nouvel utilisateur »), `table` (nom + e-mail, rôles en `badge`, statut actif/inactif en `badge` + texte, dernière connexion) ; téléphone = liste à deux lignes ; formulaire en deux colonnes de `field` ; rôles en cases à cocher groupées avec le libellé du rôle ; désactivation par `confirm-dialog`.
  - **Rôles** : liste en `table`, formulaire par module avec **noms français** (`ModuleLabels::label(string $module): string` — `admin` « Administration », `cms` « Site web », `members` « Adhérents », `contributions` « Cotisations », `finance` « Finances », `solidarity` « Fonds de solidarité », `social` « Demandes d'assistance », `employment` « Emploi et formation », `partners` « Partenariats », `governance` « Gouvernance », `delegates` « Délégués et points focaux », `discipline` « Discipline », `reports` « Rapports » ; module inconnu → son code) ; **rôle protégé ou système affiché en lecture seule** pour un acteur qui n'a pas le droit de le modifier (champs désactivés, bandeau « Ce rôle est protégé : seul un super administrateur peut le modifier. », pas de bouton d'enregistrement) — l'application serveur des règles reste inchangée ; note « Réservé aux personnes désignées par les instances (double validation). » conservée.
  - **Journal d'audit** : filtres en une rangée (dates, utilisateur, action) repliable sur téléphone, `table` (date, utilisateur, action, objet, référence) avec « Détails » dépliable, onglets *Journal* / *Consultations sensibles* via `tabs`, bouton « Vérifier l'intégrité » avec résultat en `alert` ; pagination via `pagination`.
  - **Erreurs 400/403/404/405/419** : page centrée, titre, phrase disant quoi faire, lien de retour ; 500 reste autonome mais reprend jetons et emblème inline-free (CSS lié).
  - Tous les écrans : aucun style en ligne, mêmes textes qu'avant sauf ceux décrits ci-dessus ; les assertions des tests existants qui dépendaient du balisage sont mises à jour **sans affaiblir** ce qu'elles vérifient (le dire dans le rapport).
- [ ] **Step 1: Tests**

```php
// ScreensTest
public function test_login_error_is_announced_and_linked_to_the_field(): void
public function test_every_screen_has_one_h1_and_a_page_title(): void           // parcours des URL GET de /admin et /admin/connexion…
public function test_roles_form_shows_french_module_names_and_unknown_module_falls_back_to_its_code(): void
public function test_protected_role_is_read_only_for_an_actor_without_the_right_and_has_no_save_button(): void
public function test_users_list_renders_hostile_names_escaped(): void           // nom "<b>X</b>" / 60 caractères sans espace
public function test_audit_values_remain_escaped_after_restyle(): void
public function test_dashboard_has_no_numeric_indicator_and_shows_honest_empty_state(): void
```

- [ ] **Steps 2–4:** FAIL → habiller → PASS (toute la suite) ; contrôle visuel de chaque écran à 1440/768/390 (captures dans le rapport).
- [ ] **Step 5: Commit** `L2: habillage des écrans de connexion, utilisateurs, rôles et journal`

### Task 5: Gabarit public (squelette du site)

**Files:** Create `app/views/layouts/public.php`, `public/assets/css/public.css` ; Modify `app/views/home.php`, `routes/web.php` · Test `tests/Feature/PublicShellTest.php`

**Interfaces:**
- Produces `layouts/public.php` — sans les 18 pages (lot L4) :
  - En-tête blanc : logo complet (104 px de haut) sur ordinateur, **emblème seul** sur téléphone ; filet national fin (orange / blanc / vert, 4 px) sous l'en-tête ; menu principal lu depuis une liste unique `app/views/public/_menu.php` (vide d'éléments de contenu tant que le CMS n'existe pas : seulement « La Mutuelle », « Adhésion », « Contact » en liens inactifs signalés `aria-disabled` jusqu'à L4) ; bouton « Menu » accessible au téléphone.
  - Pied de page « dos de la carte » : panneau papier, bande de cordage (barres alternées marine / papier de 28 px, construites en CSS), logo complet MMMGCI + MOHS, mentions « Un nouveau jour se lève », coordonnées **absentes** (viennent des paramètres du site, L3) ; ligne de crédits pour les images (vide).
  - Composants publics : `.card-paper` (carte imprimée à bande de cordage), bouton or `ui('button', variant: 'gold')` (ajouté à `button`), titres Marcellus, largeur de texte 68 ch.
  - `home.php` : page d'attente sobre « Le site de la Mutuelle est en cours de construction » avec le slogan, le logo, un lien vers l'administration — **sans contenu inventé**.
- [ ] **Step 1: Tests**

```php
public function test_public_page_has_logo_flag_line_menu_and_footer(): void
public function test_menu_items_not_yet_available_are_not_clickable_links(): void   // aria-disabled, pas de href vide
public function test_public_home_contains_no_invented_content(): void                // pas de chiffre, pas de nom propre hors marque
public function test_public_layout_obeys_csp_policy_rules(): void                    // via ViewsPolicyTest étendu
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS ; contrôle visuel à 1440/768/390.
- [ ] **Step 5: Commit** `L2: gabarit public`

### Task 6: Gabarit d'impression

**Files:** Create `app/views/layouts/print.php`, `public/assets/css/print.css`, `app/views/components/document-header.php` · Test `tests/Feature/PrintLayoutTest.php`

**Interfaces:**
- Produces :
  - `layouts/print.php` : A4 (`@page { size: A4; margin: 18mm }`), en-tête `document-header` (logo complet en haut à gauche, titre, référence, date en Africa/Abidjan), pied de page « Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire — document généré le … », noir et blanc lisible, `break-inside: avoid` sur les lignes de tableau, bouton « Imprimer / Enregistrer en PDF » masqué à l'impression (`@media print`).
  - Carte de membre : classe `.card-cr80` (85,6 × 54 mm, `@page cr80`), emplacement du logo, nom, n° de membre, photo, QR (les données et le QR viennent du lot L5) — **squelette CSS et vue d'exemple seulement**.
  - Gabarits spécialisés (reçu, PV, rapport) : décrits dans les lots L6, L10, L11 ; ils étendent `layouts/print.php`.
- [ ] **Step 1: Tests**

```php
public function test_print_layout_declares_a4_page_and_hides_controls_in_print(): void   // lecture de print.css
public function test_document_header_escapes_title_and_reference(): void
public function test_print_page_has_no_navigation_or_menu(): void
```

- [ ] **Step 2–4:** FAIL → implémenter → PASS ; **vérification manuelle** : produire un PDF avec Chrome headless (`--headless --no-sandbox --print-to-pdf`) d'une page d'exemple (galerie, section « document ») et vérifier l'aspect (pages A4, logo, pas de menu) — le PDF de contrôle est joint au rapport, non commité.
- [ ] **Step 5: Commit** `L2: gabarit d'impression`

### Task 7: Contrôle de qualité, accessibilité et documentation

**Files:** Modify selon les constats · Create `docs/conception/captures-l2/` (captures, non commitées si > 2 Mo au total) · Modify `docs/conception/05-design-system.md` (§8), `README.md` (§ Interface), `TASKS.md`

- [ ] **Step 1 — Taste :** `node <skill>/scripts/lint.mjs app/views public/assets` puis `node <skill>/scripts/inspect.mjs --app <url>` sur : galerie `/admin/composants`, `/admin/connexion`, `/admin`, `/admin/utilisateurs`, `/admin/utilisateurs/nouveau`, `/admin/roles`, `/admin/roles/{id}`, `/admin/journal`, `/admin/mon-compte/mot-de-passe`, une page d'erreur, la page publique `/` — à 1440, 768 et 390 px, serveur `php -S 127.0.0.1:8095 -t public` avec une base de développement peuplée par `bin/create-admin.php` + utilisateurs créés pour l'occasion (à supprimer ensuite ; `audit_chain` doit rester cohérente). **0 FAIL** ; chaque WARN corrigé ou justifié en une phrase dans le rapport. Pour les écrans d'application, l'option `--app` ; pour la page publique, l'inspection « page ».
- [ ] **Step 2 — Impeccable :** `impeccable detect --json` sur les CSS et vues ; trier chaque constat (corriger / ignorer avec raison) comme en phase 1.
- [ ] **Step 3 — Critique visuelle :** ouvrir toutes les captures, rédiger la critique selon `references/critique.md` du skill Taste (note, trois défauts principaux), corriger les trois premiers, ré-inspecter. Trois tours au plus.
- [ ] **Step 4 — Téléphone :** vérification à 360 px (largeur de téléphone modeste) : aucun défilement horizontal (mesure `scrollWidth - innerWidth = 0`), cibles ≥ 44 px, menu utilisable au clavier.
- [ ] **Step 5 — Contrastes :** relevé automatique des couples texte/fond des jetons (script de contrôle commité dans `bin/check-contrast.php` : calcule le rapport WCAG pour les paires déclarées) ; toute paire < 4,5:1 (texte) ou 3:1 (grands textes, bordures de champs) corrigée.
- [ ] **Step 6 — Documentation :** `05-design-system.md` §8 « Implémenté en L2 » (fichiers, composants, règles de contribution : *comment ajouter un écran*, *comment ajouter un lien au menu*, *ne jamais utiliser de style en ligne*), mise à jour de `TASKS.md`.
- [ ] **Step 7:** suite complète verte ; commit `L2: contrôle visuel, accessibilité et documentation`.

---

## Couverture de la spec

| Exigence | Tâche |
|---|---|
| T2.1 jetons, CSS de base, polices, surfaces du navigateur (sélection, focus, défilement) | 1 |
| T2.2 composants (boutons, champs, alertes, badges, tableaux, panneaux, vide, pagination, téléversement, boîte de confirmation) | 2 |
| T2.3 gabarit public (en-tête, menu mobile, pied de page, filet national) | 5 |
| T2.4 gabarit d'administration (menu filtré par droits, barre du haut, notifications) | 3 |
| T2.5 gabarits imprimables (A4, CR80) | 6 |
| Reports L1 : noms français des modules, rôles protégés en lecture seule | 4 |
| Taste `inspect --app`, Impeccable, accessibilité, téléphone | 7 |
