# Lot L3 (CMS) — décisions et points reportés

Commits d9d1d55 (base de la tâche 9) et suivants ; lot livré en 9 tâches. Suite complète verte, `bin/check-contrast.php` : toutes les paires conformes.

## Décisions prises pendant l'exécution
1. Pas de constructeur visuel : blocs d'un registre fixe (12 types) et éditeur riche limité ; pas de glisser-déposer (Monter/Descendre).
2. Rédiger et publier sont séparés : `cms.pages.manage` crée, modifie et soumet ; `cms.publish` publie, programme, archive, retire. Un rédacteur ne publie jamais ; chaque transition est auditée.
3. **Version relue** : publier ou programmer exige la version (`content_version`) que le publicateur a lue ; sinon refus. Un enregistrement sans changement de fond incrémente quand même la version (sans risque : au pire une relecture de plus).
4. **Version en ligne conservée** (pages, articles) : modifier un contenu publié le repasse en brouillon sans retirer la version servie ; évènements et contenus simples n'ont pas de version conservée (un évènement en ligne n'est modifié que par un publicateur).
5. Champs structurels d'un article (adresse, catégorie, auteur affiché, étiquettes) : seul un publicateur les change une fois l'article publié ; l'exemption « ne renvoie pas en brouillon » est réservée aux publicateurs.
6. Menus : enregistrement publié immédiatement, donc `cms.pages.manage` **et** `cms.publish` (route et service) ; suppression refusée pour un élément de premier niveau qui a des sous-éléments.
7. Médias : liste blanche (JPG, PNG, WebP, PDF), pas de SVG, texte alternatif obligatoire, nom de fichier jamais conservé ; suppression refusée tant que le média est référencé (clés `media_id`, `media_ids`, `file_id` et images `/media/` du texte riche).
8. Témoignages désactivés par défaut ; publication seulement avec réglage validé et pièce de consentement jointe, pièce sur le disque privé.
9. Réglages `site.*` : vides à l'installation, saisis par `cms.settings.manage`, validés par `settings.validate` avec référence de décision ; non utilisés publiquement tant que non validés. Facebook : hôtes `facebook.com`, `fb.com` (avec `www.` ou `m.`) en https seulement.
10. Formulaires reçus : texte brut, caractères de contrôle et invisibles (bidi, largeur nulle ; la jonction U+200D des émojis est conservée) retirés ; audit et notifications sans donnée personnelle.
11. Schéma d'URL public défini dans `PublishedContent` (pages `/{slug}`, `/actualites/{slug}`, `/evenements/{slug}`) ; la forme `/parent/enfant` redirige vers l'adresse plate et n'est confirmée que si **tous les parents sont en ligne**.
12. Cron : tâche `cms.publish_scheduled` prévue toutes les minutes ; une entrée cPanel toutes les 5 minutes donne jusqu'à 5 minutes de retard.

## Corrigé à la tâche 9 (mineurs parqués des revues)
- Texte riche : un `href` ne garde jamais d'espace ni de caractère de contrôle brut (NUL remplacé par une espace avant l'analyse). Le premier passage (`HtmlSanitizer`) encode déjà l'espace en `%20` ; son comportement est inchangé (test existant).
- Médiathèque : tabulation et retour à la ligne deviennent des espaces avant le retrait des caractères de contrôle ; la jonction U+200D est conservée (émojis) ; échec de suppression de fichier après validation journalisé (`error_log`, sans contenu) ; `]` échappé dans l'expression régulière des usages (`[^\]]`).
- Menus : double droit exigé sur la route ; suppression d'un élément parent refusée.
- Articles : exemption structurelle réservée aux publicateurs ; tests « liste publique = ensemble des contenus ayant une version en ligne » (articles, évènements).
- `PublishedContent::page` : chaîne `/parent/enfant` avec parents en ligne seulement ; boîte de réception : caractères invisibles retirés, champs `to`/`note` non textuels sans « Array » ni avertissement, historique lisible (états et noms) ; liens de référence et de titre à 44 px au pointeur tactile ; « Nouvelle bannière ».
- Réglage Facebook restreint (voir décision 9).

## Points reportés / limites assumées
- SEO avancé (T3.8) → lot L4 ; rendu public, plan du site publié, adhésion en ligne → L4.
- Barre oblique finale ou doublée, `/Statuts` (404 au lieu de 301), requête avant `page()` : à traiter au rendu public (L4).
- Une page enfant d'un parent hors ligne est publique à son adresse plate (voir décision 11) ; à documenter côté L4.
- Images du texte riche non verrouillées dans tous les cas ; course enregistrement de page / suppression de média non couverte ; liste des usages en lecture seule.
- Enregistrement d'un menu sans contrôle de version (le dernier gagne) ; déplacements Monter/Descendre ne contrôlent pas les sous-éléments.
- Saisie et validation d'un même réglage par la même personne possible si elle cumule les droits : à interdire ou non par les instances.
- Anti-spam des formulaires : compteur par IP sans verrou (course possible), partagé derrière un NAT.
- Politique de conservation des formulaires et des pièces de consentement non définie : aucune purge automatique.
- Listes d'administration des contenus simples plafonnées à 200 lignes ; `sitemapUrls()` fait une requête par article (borné à 5 000, voir `shortcut:`).
- Taste : le sélecteur de médias est un fragment de dialogue (pas de titre ni de feuille de style propres) ; ses 2 « échecs » en page seule sont sans objet. Avertissements `hero-void`/`panel-void` des listes à peu de lignes : la feuille blanche du gabarit d'administration occupe la hauteur de l'écran, justifié.
- Base de développement : chaîne d'audit rompue (antérieure, à ignorer). Les données jetables du contrôle visuel (préfixe ZZQC) ont été retirées ; le compte jetable utilisé est désactivé (non supprimé : des lignes d'audit le référencent).

## Mesures de performance (1 000 pages et 1 000 articles publiés, base de test)
`PublishedContent::page` 0,5 ms ; `menu('header')` (7 éléments) 0,6 ms ; `articles(['limit' => 20])` 4,9 ms ; `sitemapUrls()` (2 000 adresses) environ 210 à 240 ms (une requête par article : `shortcut` documenté). Mesure rejouée par `CmsEndToEndTest::test_performance_with_a_thousand_pages_and_articles`.

## Dernière vague (revue finale de la branche)
- Fichiers publics écrits en 0644 et dossiers en 0755 (disque privé inchangé : 0640/0750), indépendamment du umask.
- Formulaires : plafond global de 20 e-mails de notification par heure (les envois sont toujours enregistrés) ; compteur par IP regroupant l'IPv6 par /64 ; l'enregistrement du passage est inséré avant le comptage (refus si dépassement).
- Taxonomie : renommer ou changer l'adresse d'un terme utilisé par un article en ligne exige `cms.publish`.
- Usages des médias : les images conservées d'un article en ligne (`published_featured_file_id`, `published_og_file_id`) comptent.
- Publier ou programmer exige la version lue pour les pages, articles et évènements (comme le circuit simple) : `seenVersion` absent = refus.
- `PublishedContent` : `mediaUrl()`, `fileUrl()`, `pagePath()`, `category()` pour résoudre les identifiants renvoyés (disque public seulement ; jamais l'adresse d'un brouillon).
- **Fichiers publics par URL (I3)** : tout fichier de la médiathèque est joignable par son URL dès le téléversement, y compris pour un brouillon (nom aléatoire de 128 bits, pas de liste de répertoire). Modèle « URL capacité » accepté : **rien de confidentiel dans la médiathèque**. Disque privé jusqu'à la première publication : amélioration possible plus tard.
- **Contrat des formulaires (M4, pour L4)** : le bloc `formulaire` propose `contact`, `partenariat`, `adhesion` ; `adhesion` n'est pas un formulaire de `FormInbox` et doit être orienté vers `/adhesion-en-ligne` ; le formulaire `information` n'a aucun bloc. Les POST publics de L4 passent par le jeton CSRF global (session nécessaire).
- Texte riche : le serveur garde les titres h2 à h4 (la barre d'outils propose 2 et 3).

## Champs pour L4 : HTML ou texte brut
**HTML** (nettoyé par `RichText` à l'enregistrement ; L4 l'affiche sans `e()` et repasse `RichText::sanitize` au rendu, défense en profondeur) : `page()['blocks'][i]['html']` (bloc `texte`), `article()['body']`, `articles()[i]['body']`, `events()[i]['description']`, `galleries()[i]['description']`.

**Tout le reste est du texte brut : L4 l'échappe (`e()`, attributs entre guillemets)** :
- page : `title`, `slug`, `path`, `redirect`, `excerpt`, `seo_title`, `seo_description`, `canonical_url` (attribut), `template`, `published_at` ; ids `featured_file_id`, `og_file_id` (à résoudre par `fileUrl`).
- blocs : `titre.text` ; `intro.title`, `intro.note` (multiligne : `nl2br(e())`) ; `faits.items[].label/value` ; `domaines.items[].name/nature/description` ; `liste.items[].text` ; `appel.title`, `.text` (multiligne), `.button_label`, `.url` (attribut ; `https://x"onx=1` est accepté par `Blocks::isSafeUrl` : échapper), `.page_id` (à résoudre par `pagePath`, page en ligne seulement) ; `image.media_id` (`mediaUrl` : url + `alt` brut), `.caption` ; `documents.category` ; `articles`/`evenements` `.count` (entier) ; `formulaire.form_key` (énumération).
- menu : `label`, `href` (attribut), `target`, `rel`, `children[]`.
- article : `title`, `slug`, `excerpt`, `seo_*`, `canonical_url`, `author_name`, `category_id` (`category()`), `published_at`, ids d'images.
- évènement : `title`, `slug`, `location`, `starts_at`, `ends_at`, `published_at`, `featured_file_id`.
- documents : `title`, `category`, `document_date`, `mime`, `size`, `url`. Bannières : `text`, `link_url` (attribut), `starts_at`, `ends_at`. Galeries : `title`, `slug`, `item_count` ; `galleryItems` : `caption`, `credit`, `alt`, `url`. Témoignages : `quote` (multiligne), `author_name`, `author_role`.
- `SiteSettings::publicValue` : `contact_email`, `contact_phone`, `address`, `social_facebook` (attribut), `social_whatsapp`, `consent_text` (multiligne) ; booléens et entier.
- **`RichText::toText()`** (extraits, SEO) renvoie du **texte brut** : à échapper.
