# Lot L4 — Site public et adhésion en ligne — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remplacer la page d'attente par le vrai site : rendu public des contenus du CMS (L3), accueil et pages construits selon la direction « La page est l'objet », adhésion en ligne qui alimente le circuit des demandes (L5), formulaires de contact et de partenariat, SEO et performance mobile.

**Architecture:** Un contrôleur public `app/Modules/Site/` qui résout une URL en page CMS **publiée** via `PublishedContent` (L3), rend les blocs par des gabarits PHP (`app/views/public/blocks/*.php`) dans `layouts/public`, et gère les formulaires publics (adhésion en étapes, contact, partenariat) en appelant `ApplicationService::submit` et `FormInbox::submit`. CSS écrit à la main (`public.css`, jetons L2), un seul JavaScript facultatif. Aucun contenu n'est inventé : le seul texte semé vient du cahier des charges et reste **en brouillon**.

**Tech Stack:** PHP 8.2 sans framework, MariaDB/MySQL, PHPUnit, Playwright (contrôle), scripts Taste (`lint.mjs`, `inspect.mjs`), GD (variantes d'images), aucune dépendance.

**Spec:** `docs/superpowers/specs/2026-11-16-site-public-cms-design.md` (choix par défaut), `docs/conception/05-design-system.md` §7 (direction Taste), `taste/direction-site-public.json`, `03-architecture.md` (A2 arborescence, parcours adhésion), cahier des charges art. 1–13, 17, chapitre X ; plan L3 : `2026-11-16-l3-cms.md`.

## Global Constraints

- PHP ≥ 8.2, strict_types, aucune dépendance Composer en production ; CSP stricte (`script-src 'self'`, `style-src 'self'`, aucun style/script en ligne) ; français ; Africa/Abidjan ; une `h1` par page ; cibles ≥ 44 px au toucher ; téléphone d'abord ; contraste ≥ 4,5:1 (texte).
- **Jamais de contenu inventé** : textes seulement issus du cahier des charges (en brouillon) ou saisis par la Mutuelle ; aucun chiffre, nom, adresse, téléphone ni courriel ; aucune photo non fournie ; un dessin d'illustration n'est jamais présenté comme photo de la Mutuelle ; page sans source = absente ou masquée avec état vide honnête.
- **Seul du contenu publié est public** (`PublishedContent`) ; aucune page, aucun lien de menu, aucun élément de sitemap ne révèle un brouillon, un archivé ou un programmé futur ; les aperçus exigent une session et sont `noindex`.
- **Adhésion** : une demande jamais un membre ; ouverte seulement quand `site.adhesion_open` ET `site.consent_text` sont **validés** ; texte de consentement fourni par la Mutuelle, jamais écrit par le code ; données privées (pièces) sur le disque privé ; anti-spam (champ piège + limite par IP) sans service tiers ; accusé de réception avec référence `AD-AAAA-NNNN`.
- **Direction Taste « La page est l'objet »** : carte imprimée à bandes (cordage CSS/SVG), filet national fin, titres sur deux lignes au plus, aucun petit label au-dessus d'un titre, aucun dégradé, aucune ombre au repos, un seul rayon d'angle, deux polices (Marcellus, Source Sans 3), `lint.mjs` et `inspect.mjs` à 1440/768/390 sans FAIL.
- **Performance** : page d'accueil < 200 Ko hors photos (HTML+CSS+JS+polices), images responsives (`srcset`, `loading="lazy"`, dimensions déclarées), compression et cache d'assets via `.htaccess` ; contenu et adhésion fonctionnels sans JavaScript.
- Suite existante (640 tests + L3) verte, aucune assertion affaiblie ; `AdminRouteSweepTest` vert ; chaque écriture publique sans donnée personnelle dans le journal d'audit.

## Review Focus

1. **Surface publique** : XSS réfléchi (chemin dans la page 404, paramètres), traversée de chemin (`/../`, `//`, `%2e%2e`, barres obliques finales), redirection ouverte via anciens slugs, mise en cache de pages personnalisées, cookie/session posés sur des pages qui n'en ont pas besoin.
2. **Formulaires publics** : spam et automatisation (champ piège contournable, rotation d'IP), pièces jointes hostiles (polyglotte, bombe de décompression, 500 Mo), double envoi, injection d'en-têtes dans l'e-mail d'accusé (adresse fournie par le visiteur), données personnelles dans les journaux, énumération d'adhérents existants (« ce numéro de téléphone existe déjà »).
3. **Fuite de brouillon** par un lien direct, le sitemap, `robots.txt`, un flux, l'aperçu, les images d'un brouillon (médias publics référencés seulement par un brouillon).
4. **Adhésion fermée** : l'URL de soumission refuse toute écriture tant que les deux paramètres ne sont pas validés (pas seulement le bouton masqué) ; un consentement absent bloque l'envoi côté serveur.
5. **Accessibilité mobile** : formulaire en 5 étapes utilisable au pouce, erreurs liées aux champs, aucune perte de saisie au retour arrière, aucune dépendance au JavaScript.

---

## Fichiers

| Fichier | Responsabilité |
|---|---|
| `app/Modules/Site/SiteController.php`, `PageRenderer.php` | résolution d'URL, rendu des pages et des listes |
| `app/views/public/blocks/*.php`, `app/views/public/pages/*.php` | gabarits des blocs et des pages |
| `app/Modules/Site/{ApplyController,ApplyFlow}.php`, `app/views/public/apply/*.php` | adhésion en ligne |
| `app/Modules/Site/ContactController.php` | formulaires contact et partenariat |
| `app/Modules/Site/SeoController.php` | `sitemap.xml`, `robots.txt` |
| `app/Modules/Cms/MediaVariants.php` | variantes d'images responsives |
| `bin/seed-site.php` | création **en brouillon** des pages issues du cahier des charges |
| `public/assets/css/public.css`, `public/assets/js/site.js`, `public/assets/img/art/*.svg` | style, confort, dessins du métier |

---

### Task 1: Résolution d'URL et rendu des blocs

**Files:** Create `app/Modules/Site/{SiteController,PageRenderer}.php`, `app/views/public/pages/{standard,accueil,liste,not-found}.php`, `app/views/public/blocks/{texte,intro,faits,domaines,liste,appel,image,documents,articles,evenements,formulaire,titre}.php` ; Modify `routes/web.php` (route finale `GET /{chemin}` après toutes les routes précises), `app/views/layouts/public.php` (menu et pied de page lus depuis `PublishedContent`/`SiteSettings` validés), `app/views/public/_menu.php` (supprimé) ; Test `tests/Feature/PublicPagesTest.php`

**Interfaces:**
- `SiteController::show(Request): Response` : normalise le chemin (minuscules, sans barre finale, refus `//`, `..`, caractères hors `[a-z0-9/-]` → 404), résout via `PublishedContent::page` ; ancien slug → **301** vers l'URL actuelle (jamais vers un hôte extérieur) ; sinon 404 de page publique (sans écho du chemin) ; en-têtes : `Cache-Control: public, max-age=300`, `ETag` du contenu, aucun cookie ni session (ajouter `/` et les chemins publics aux exceptions de `StartSession` : **préfixe**, comme `/verifier/`, avec les routes d'adhésion exclues car elles ont besoin de session).
- `PageRenderer::render(array $page): string` : parcourt les blocs dans l'ordre, délègue à `public/blocks/<type>.php` ; chaque valeur est échappée sauf le HTML du bloc `texte` déjà nettoyé (second passage `RichText::sanitize` défensif) ; bloc inconnu = ignoré avec journal d'avertissement (jamais d'erreur 500).
- Blocs dynamiques : `articles`, `evenements`, `documents` lisent `PublishedContent` ; un état vide honnête s'affiche si rien n'est publié (« Aucun document publié pour le moment »).
- Menu principal et pied de page viennent de `PublishedContent::menu('header'|'footer')` ; **sans menu publié**, le gabarit n'affiche que le logo et « Adhésion » si la page publiée existe ; coordonnées et réseaux seulement si **validés**.
- [ ] **Step 1: Tests** : `test_published_page_is_served_with_cache_headers_and_no_cookie`, `test_draft_archived_and_future_scheduled_pages_return_404`, `test_path_normalisation_rejects_traversal_and_double_slashes`, `test_404_page_does_not_echo_the_path`, `test_old_slug_redirects_301_within_the_site_only`, `test_hostile_block_text_is_escaped_and_html_is_sanitised`, `test_unknown_block_type_is_ignored`, `test_dynamic_blocks_show_empty_states`, `test_menu_and_footer_hide_unvalidated_contacts`, `test_specific_routes_still_win_over_the_catch_all` (`/admin`, `/verifier/x`, `/sante`), `test_public_layout_obeys_csp_policy_rules` (ViewsPolicyTest étendu à tous les gabarits publics).
- [ ] **Step 2–4:** FAIL → implémenter → PASS (suite complète) ; **Step 5: Commit** `L4: résolution d'URL et rendu des blocs`.

### Task 2: Direction visuelle et gabarits (T4.0)

**Files:** Modify `public/assets/css/public.css`, `app/views/public/pages/accueil.php` et les blocs ; Create `public/assets/img/art/{livret,ancre,cordage}.svg` (dessins originaux), `public/assets/js/site.js` (menu, « Préparer mon dossier ») ; Create `app/views/public/blocks/preparer-dossier.php` (type de bloc `dossier`, ajouté au registre de L3) ; Test `tests/Feature/PublicDesignTest.php` ; livrable : `docs/conception/taste/critique-site-public.md`

**Interfaces:**
- **Accueil** (gabarit `accueil`) : héro = carte imprimée à bandes de cordage (logo, « Un nouveau jour se lève », une phrase venant du CMS, bouton principal « Demander mon adhésion », secondaire « Voir les aides »), à droite un **emplacement d'image facultatif** (média réel choisi par le CMS ; **à défaut un dessin SVG du métier** étiqueté comme illustration) ; section « Ce que fait la Mutuelle » = bloc `domaines` rendu comme **carte de menu** (colonne de gauche le domaine, colonne de droite la nature de l'aide et sa réserve, pointillés légers) ; section « Comment adhérer » en trois étapes lue du bloc `liste` ; actualités et offres seulement si publiées ; pied de page « dos de la carte ».
- **Page intérieure** (`standard`/`liste`) : titre, introduction, blocs, fil d'Ariane (pas de petit label au-dessus du titre), colonne de lecture 68 caractères.
- **« Préparer mon dossier »** (bloc `dossier`) : le visiteur coche sa situation (marin en activité, ancien marin, livret maritime disponible, ayants droit) et la liste des pièces (lue des éléments du bloc, saisis depuis l'art. 6) se met à jour ; **sans JavaScript** toute la liste reste affichée avec la mention « selon votre situation » ; aucune donnée n'est envoyée ni stockée.
- Dessins SVG : originaux, simples, `role="img"` + `aria-label` ou `aria-hidden`, poids < 6 Ko chacun, **servis comme fichiers** (pas d'incrustation).
- Boucle Taste : `inspect.mjs` (page) et critique écrite à 1440/768/390 ; ≤ 3 tours ; 0 FAIL.
- [ ] **Step 1: Tests** : `test_home_has_one_h1_and_primary_adhesion_action_first`, `test_menu_card_renders_domains_with_their_reservations`, `test_dossier_list_is_fully_visible_without_js`, `test_svg_art_is_a_static_file_under_6_kb_and_has_no_script`, `test_image_slot_absent_when_no_media_and_never_a_fake_photo`, `test_no_inline_styles_scripts_or_handlers_in_public_views`, `test_contrast_pairs_for_public_tokens` (`ContrastChecker::PAIRS` étendu : texte sur papier `--paper`, sur marine), `test_home_page_weight_budget` (HTML + CSS + JS + polices < 200 Ko, mesuré en test sur les fichiers).
- [ ] **Steps 2–4**, contrôle visuel + critique ; **Step 5: Commit** `L4: direction visuelle, accueil et gabarits`.

### Task 3: Contenu issu du cahier des charges, en brouillon

**Files:** Create `bin/seed-site.php`, `app/Modules/Site/ContentSeed.php` ; Test `tests/Feature/ContentSeedTest.php`

**Interfaces:**
- `ContentSeed::run(int $actor): array{created:int, skipped:int}` : **idempotent** (par slug), crée **en brouillon** (jamais publié) les pages dont la source est le cahier des charges, avec leurs blocs, et les menus `header` (La Mutuelle, Adhésion, Actions sociales, Emploi, Formation, Actualités, Contact) et `footer` (Documents, Partenaires, Mentions légales si elle existe) ; chaque bloc cite sa source dans un champ interne `source` (ex. « CDC art. 9 ») visible dans l'administration, jamais en public.
- Pages créées (texte du cahier des charges, reformulé le moins possible, aucune donnée inventée) : `accueil`, `la-mutuelle` (art. 1–2 et préambule), `la-mutuelle/missions` (art. 2), `la-mutuelle/gouvernance` (art. 3, organes), `actions-sociales` (art. 9 : huit domaines + réserves « sous réserve des ressources… »), `actions-sociales/fonds-de-solidarite` (art. 10 sans promesse automatique), `adhesion` (art. 5, 6, 7 : conditions, pièces, carte ; bloc `formulaire` adhésion ; bloc `dossier`), `emploi`, `formation`, `partenaires` (art. 12 : types de partenaires cités, sans nom), `actualites`, `evenements`, `documents`, `galerie`, `contact`.
- **Pages non créées faute de source** : mot du Président, historique, mentions légales, confidentialité : l'administration affiche une liste « À fournir par la Mutuelle » (tableau de bord du site) avec ces rubriques ; un test vérifie qu'aucune n'existe.
- Le script refuse de s'exécuter avec `--publish` : **la publication reste une action humaine**.
- [ ] **Step 1: Tests** : `test_seed_creates_only_drafts_and_never_publishes`, `test_seed_is_idempotent_and_never_overwrites_edited_pages`, `test_no_text_outside_the_charter_sources` (chaque bloc texte a un `source` non vide), `test_pages_without_source_are_not_created_and_listed_as_to_provide`, `test_menu_items_point_to_drafts_and_are_hidden_publicly`, `test_seeded_public_pages_404_until_published`, `test_domains_block_keeps_the_charter_reservations`.
- [ ] **Steps 2–4** (relecture du texte semé par un humain avant toute publication : l'indiquer dans le rapport et le README) ; **Step 5: Commit** `L4: contenu du cahier des charges semé en brouillon`.

### Task 4: Adhésion en ligne

**Files:** Create `app/Modules/Site/{ApplyController,ApplyFlow}.php`, `app/views/public/apply/{step1..step5,done,closed}.php`, tests `tests/Feature/OnlineApplicationTest.php`

**Interfaces:**
- Parcours `/adhesion-en-ligne` (slug réservé) en **5 étapes servies par le serveur** (une URL par étape, retour arrière possible, rien perdu) : 1 Situation (catégorie art. 5, livret disponible, ayants droit) ; 2 Identité (nom, prénoms, date de naissance, sexe, nationalité) ; 3 Coordonnées et profession (téléphone, e-mail facultatif, zone, profession, spécialité) ; 4 Pièces (pièce d'identité, livret si disponible, photo si requise : art. 6) avec `Uploads` privé ; 5 Consentement (texte **lu de `site.consent_text`**) et récapitulatif ; envoi.
- `ApplyFlow` garde l'état en session (cookie de session, **uniquement sur ces routes**) ; chaque étape valide ses champs (mêmes règles que `MemberService`/`ApplicationService::submit` : réutiliser la validation) ; les pièces sont téléversées à l'étape 4 dans un **dossier temporaire privé lié à la session** et rattachées à la demande à l'envoi ; nettoyage des orphelins par le cron.
- **Garde d'ouverture** : toute route du parcours (GET et POST) répond « adhésions en ligne bientôt ouvertes » (page `closed`, 200, avec la liste des pièces et le contact validé s'il existe) tant que `site.adhesion_open` ET `site.consent_text` ne sont pas validés (statut `valide`, `adhesion_open === true` strict) ; **le POST est refusé côté serveur** (pas seulement masqué).
- Envoi : `ApplicationService::submit($payload, 'en_ligne', null, $ip)` crée `membership_applications` « reçue », rattache les pièces, répond par la page `done` avec la **référence** ; **jamais** « vous êtes membre » ; si un e-mail valide est fourni et le courrier configuré, un accusé est envoyé en tâche différée (adresse validée, aucun en-tête injectable) ; aucun doublon n'est révélé au visiteur (la détection reste interne : « votre demande a été reçue »).
- Anti-spam : champ piège (nom de champ non évident, test de présence), **limite par IP** (réutiliser `LoginThrottle` comme `/verifier`), délai minimal de remplissage (jeton horodaté signé), taille/type des pièces contrôlés, CSRF.
- [ ] **Step 1: Tests** : `test_closed_until_both_settings_are_validated_for_get_and_post`, `test_five_steps_keep_state_and_allow_going_back`, `test_submission_creates_an_application_never_a_member`, `test_files_are_private_and_bound_to_the_application`, `test_consent_is_required_server_side_and_text_comes_from_settings`, `test_honeypot_and_minimum_fill_time_reject_bots`, `test_ip_throttle_returns_429`, `test_duplicate_applicant_gets_same_acknowledgement_no_oracle`, `test_hostile_files_are_refused` (php, svg, polyglotte, énorme), `test_confirmation_email_has_no_header_injection`, `test_no_personal_data_in_audit_log`, `test_works_without_javascript`, `test_session_cookie_only_on_apply_routes`.
- [ ] **Steps 2–4** ; contrôle visuel du parcours à 1440/768/390 avec erreurs ; **Step 5: Commit** `L4: adhésion en ligne en cinq étapes`.

### Task 5: Contact, partenariat, SEO et performance

**Files:** Create `app/Modules/Site/{ContactController,SeoController}.php`, `app/Modules/Cms/MediaVariants.php`, `app/views/public/contact/*.php` ; Modify `public/.htaccess` (compression, cache des assets, en-têtes), `routes/web.php` ; Test `tests/Feature/ContactSeoTest.php`, `tests/Unit/MediaVariantsTest.php`

**Interfaces:**
- Formulaires `contact`, `information`, `partenariat` : champs validés par clé, `FormInbox::submit` (limite par IP, champ piège, CSRF), accusé de réception sans donnée personnelle affichée, pas de session persistante au-delà du jeton CSRF ; la page de contact affiche coordonnées **validées** seulement.
- `SeoController` : `GET /sitemap.xml` (uniquement pages/articles/événements/documents publiés, URL absolues depuis `APP_URL`, `lastmod`), `GET /robots.txt` (`Disallow: /admin`, `/verifier/`, `/adhesion-en-ligne`, `Sitemap:` si `APP_URL`) ; balises `title`, `description`, `canonical`, Open Graph et Twitter depuis les champs SEO ; `noindex` sur aperçus, 404 et pages `closed`.
- `MediaVariants::generate(int $mediaId): array` (GD, largeurs 480 et 960, WebP/JPEG selon source, créées à l'ajout d'un média et à la demande) ; le rendu utilise `srcset` + `sizes` + `loading="lazy"` + `width`/`height`.
- `.htaccess` : `mod_deflate` pour HTML/CSS/JS/SVG, `Cache-Control` long (immuable) pour `/assets/` versionnés par `?v=<hash du fichier>` (helper `asset_url()`), court pour les pages ; en-têtes sans casser la CSP.
- [ ] **Step 1: Tests** : `test_sitemap_contains_only_published_content_with_absolute_urls`, `test_robots_blocks_admin_verifier_and_apply`, `test_seo_tags_use_page_fields_and_canonical`, `test_noindex_on_preview_404_and_closed_apply`, `test_contact_form_throttle_honeypot_and_csrf`, `test_contact_submission_lands_in_inbox_and_notifies`, `test_unvalidated_contact_details_are_not_shown`, `test_variants_are_generated_and_srcset_rendered`, `test_asset_url_adds_content_hash`, `test_htaccess_enables_compression_and_asset_caching` (lecture du fichier).
- [ ] **Steps 2–4**, **Step 5: Commit** `L4: contact, partenariat, SEO et images responsives`.

### Task 6: Contrôle de qualité, parcours de bout en bout et documentation

**Files:** Modify selon les constats ; Test `tests/Feature/SiteEndToEndTest.php` ; `README.md` (§ Site public), `TASKS.md`, `docs/superpowers/plans/2026-11-16-l4-decisions-et-reports.md`, `docs/conception/etude-sites-comparables.md` (état honnête conservé)

- [ ] **Step 1:** bout en bout : semis en brouillon → 404 public → publication par un publicateur → page servie, présente au menu et au sitemap → adhésion fermée → paramètres validés → dépôt d'une demande en cinq étapes avec pièces → la demande apparaît dans `/admin/adherents/demandes` avec sa source `en_ligne` → contact reçu dans la boîte → cron de publication programmée.
- [ ] **Step 2:** Taste `lint.mjs` / `inspect.mjs` (page) à 1440/768/390 sur accueil, page intérieure, adhésion (5 étapes), fermée, contact, 404 ; critique écrite (note + 3 défauts) ; `impeccable detect` ; 360 px sans défilement horizontal ; `bin/check-contrast.php` vert ; Lighthouse-like : mesures Playwright (poids, requêtes, temps de chargement en 3G simulée).
- [ ] **Step 3:** sécurité publique : balayage des routes publiques (aucune ne pose de cookie sauf adhésion ; en-têtes CSP/nosniff ; méthodes non permises → 405).
- [ ] **Step 4:** documentation en français : README § Site public (ce qui est publié, comment publier, **semis en brouillon à relire**, pages « à fournir », ouverture de l'adhésion en ligne = deux paramètres à valider, sauvegarde des pièces), TASKS.md (T4.0–T4.6 ; T3.8 SEO fait ici), limites (pas d'étude de sites comparables faute d'accès réseau ; photos à fournir ; texte de consentement à fournir).
- [ ] **Step 5:** suite complète verte ; commit `L4: contrôle de qualité et documentation`.

---

## Rulings de conception (à confirmer par la Mutuelle)

- L'adhésion en ligne est **fermée par défaut** ; elle s'ouvre quand les instances valident le texte de consentement et l'ouverture.
- Le texte du site vient du cahier des charges, non adopté à ce jour : tout est semé en brouillon.
- Pas de photo : dessins originaux du métier signalés comme illustrations ; les vraies photos arrivent par la médiathèque.
- État de la session limité aux routes d'adhésion ; le reste du site n'envoie aucun cookie.
- Aucun CAPTCHA tiers : champ piège, délai minimal, limite par IP.
