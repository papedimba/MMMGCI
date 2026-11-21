# 05 — Design system et interfaces principales

> Maquette visuelle : [`maquettes/design-system.html`](maquettes/design-system.html) (à ouvrir dans un navigateur ; testée à 360 px et 1280 px, sans défilement horizontal).
> Démarche Impeccable : `context` exécuté, `PRODUCT.md` rédigé, règles *craft-floor* appliquées, détecteur passé (voir § 6).

## 1. Direction

| Surface | Mode | Intention |
|---|---|---|
| Site public | Persuader / Lire | Institutionnel et chaleureux : la mer de nuit qui s'éclaire (« Un nouveau jour se lève »), l'or du bandeau comme accent, beaucoup d'air, typographie classique. |
| CMS et gestion | Opérer | Sobre, dense mais lisible, sans décoration : la marque vit dans les détails (marine, or discret, titres Marcellus). Thème clair : usage de jour au siège et sur smartphone en extérieur. |

Ce qui est volontairement évité : grilles de cartes identiques icône + titre + texte, chiffres-vedettes décoratifs, dégradés de texte, effets de verre, tableaux de bord surchargés, émojis en guise d'icônes.

## 2. Jetons

| Groupe | Valeurs |
|---|---|
| Marine | 950 `#071631` · 900 `#0B2350` · 800 `#12306B` (action principale) · 700 `#1A3F86` |
| Océan | 600 `#1F5AA6` (liens, focus) · 100 `#E3ECF8` · 50 `#F1F5FB` |
| Or | 400 `#D2AE55` (accent, bouton d'adhésion sur fond sombre) · 600 `#A47C24` · 700 `#7E5F17` (seul or autorisé pour du texte sur fond clair) · 100 `#F6EDD6` |
| Neutres | Ivoire `#FAF8F3` (fond public) · Blanc · Encre `#122038` · Encre 2 `#3E4C66` · Encre 3 `#5C6A82` · Filets `#DCE2EC` / `#B9C3D3` |
| États | Succès `#1C7A4A` / `#E5F3EB` · Attention `#8F5600` / `#FBF0DD` · Erreur `#B42318` / `#FCE9E7` · Info = Océan |
| National | Orange `#F77F00`, blanc, vert `#009E60` — uniquement en filet fin (comme sur le logo) |
| Typo | Titres **Marcellus** (OFL) · Texte **Source Sans 3** (OFL), chiffres tabulaires dans les tableaux et montants. Auto-hébergées en production. |
| Échelle typo | 56 / 36 / 25 / 20 / 16 / 14 px ; interlignage 1,55 pour le texte ; largeur de lecture 65–75 caractères |
| Espacements | 4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96 px ; gouttière mobile 16 px |
| Rayons | 6 px contrôles · 10 px panneaux · 16 px cadres |
| Ombres | Douces, décalées (`0 1px 2px` / `0 4px 12px`) ; jamais de halo coloré |
| Cibles tactiles | ≥ 44 px |
| Focus | Anneau Océan 2 px avec liseré blanc, toujours visible au clavier |

## 3. Composants (spécifiés dans la maquette)

Boutons (principal, secondaire, or, danger, désactivé, chargement) · champs, listes, zones de texte avec aide et erreur explicite (le message dit le problème **et** la correction) · badges de statut à point coloré · alertes (succès, info, attention, erreur) · tableaux (en-tête collant, montants alignés à droite, survol, défilement horizontal contenu dans le tableau sur mobile) · panneaux · état vide avec action · squelettes de chargement · menu latéral de gestion avec compteurs « à traiter » · barre supérieure avec recherche globale · fil du circuit de validation (étapes faites / en cours / à venir).

À détailler en phase 4 : modale de confirmation (réservée aux actions irréversibles : rejet, paiement, suppression), téléversement de fichiers avec aperçu, formulaire d'adhésion multi-étapes, pagination, filtres, onglets de fiche adhérent, carte de membre (format CR80), gabarits PDF (reçu, carte, PV, rapports).

## 4. Interfaces principales présentées

1. **Accueil public** : en-tête blanc avec logo complet, filet national, héro marine avec slogan et appel « Demander mon adhésion », missions en liste éditoriale, bandeau d'appel final.
2. **Tableau de bord de gestion** : bande de 4 indicateurs, liste « À traiter », graphique appelé/encaissé. Les chiffres de la maquette sont fictifs ; en production, base vide = 0 ou état vide.
3. **Double validation d'un décaissement** : montant en évidence, faits vérifiables, justificatifs, circuit visible, boutons selon les droits, avertissement d'invalidation ; version smartphone.

## 5. Point d'attention sur le logo

Le logo complet imposé est **vertical** (emblème au-dessus du bandeau MOHS). Dans un en-tête de site ou une barre d'application, le bandeau « Maritime Oceans and Hospitality Services » devient illisible en dessous d'environ 100 px de haut (voir la vue mobile de la maquette). Propositions, à valider :

- fournir ou faire produire une **déclinaison horizontale** (emblème à gauche, textes à droite) et une version **vectorielle (SVG) ou PNG transparente** ;
- à défaut : logo complet à 104 px en en-tête sur ordinateur, emblème seul dans l'en-tête mobile et la barre de gestion, logo complet dans le pied de page, la carte de membre et les documents PDF.

## 6. Contrôles effectués

- Rendu Playwright à 360 et 1280 px : aucun défilement horizontal (un débordement de 73 px des tableaux sur mobile a été corrigé).
- Détecteur Impeccable : placeholder trop clair corrigé (#5C6A82) ; alerte de contraste sur le héro **ignorée pour ce seul fichier** (faux positif : le détecteur fait la moyenne du léger halo bleu, le texte repose en réalité sur un marine ≥ 12:1) ; alertes « carte dans une carte » (panneaux présentés dans un cadre de maquette), fond ivoire et lignes de houle du héro conservés comme choix délibérés.

## 7. Direction artistique du site public (skill Taste)

Le commanditaire a demandé le **skill Taste** (`design-taste-frontend`) à la place de « Task Skill ». Il a été appliqué ainsi :

**Tirage.** `draw.mjs --sector nonprofit --keep palette,type` (couleurs et polices imposées par le logo) a proposé trois directions : *Colour chapters*, *Dressed as something else* et **The page is the object**. Retenue : **« La page est l'objet »** (graine 878571445, fiche complète dans `taste/direction-site-public.json`). C'est la seule qui peut porter un objet que seuls les marins possèdent.

**L'idée.** *Sur ce site, le livret maritime du marin, et la carte de membre qui en découle, devient l'objet imprimé qui porte tout le contenu.* Test du concurrent : une mutuelle d'enseignants ou de transporteurs n'a pas de livret maritime, donc l'idée appartient bien à la MMMGCI.

**La chose interactive.** « Préparer mon dossier » : le visiteur indique sa situation (marin en activité, ancien marin, livret maritime disponible, ayants droit). La page affiche la liste exacte des pièces prévues par l'article 6 du cahier des charges et un aperçu de sa future carte de membre à son nom. Elle fonctionne au pouce sur un téléphone ; sans JavaScript, la liste complète reste affichée.

**Les sections tirées**, adaptées au contenu réel :

| Rôle | Gabarit tiré | Contenu MMMGCI |
|---|---|---|
| Héro | Carte imprimée à bandes, à côté d'une grande photo | Carte au format du livret : bande de cordage tressé en haut (motif du logo), logo, « Un nouveau jour se lève », une phrase, bouton « Demander mon adhésion » ; à droite une photo réelle du port d'Abidjan |
| Introduction | Titre, note, rangée de faits | Ce qu'est la Mutuelle ; faits tirés du cahier des charges uniquement (période 2026–2027, Abidjan, catégories admises de l'art. 5) — aucun chiffre inventé |
| Missions | Capacités présentées comme une carte de menu | Les 8 domaines d'intervention de l'art. 9, la colonne de droite indiquant la nature de l'aide (information, orientation, après examen du dossier…) |
| Vitrine | Fiches imprimées le long du bord | Les offres d'emploi et d'embarquement réelles, chacune sur une fiche ; état vide honnête tant qu'il n'y en a pas |
| Liste | La feuille imprimée | Documents officiels et pièces du dossier d'adhésion |
| À propos | La lettre | Mot du Président : **texte à fournir par la Mutuelle** (aucun texte ne sera inventé) ; section masquée tant qu'il manque |
| Appel | La carte-réponse | Bulletin d'adhésion |
| Pied de page | Le dos de la carte | Coordonnées, logo complet MMMGCI + MOHS, crédits des images |

**Images.** La Mutuelle n'a encore aucune photo à elle. Ordre de recours : photos libres de droits du monde maritime ivoirien (port d'Abidjan, San-Pedro, navires, gens de mer), trouvées avec `assets.mjs`, téléchargées et créditées en pied de page ; à défaut, dessins en SVG des objets du métier (livret, ancre, cordage). Une image trouvée n'est jamais présentée comme une photo de la Mutuelle. Les photos d'architecture génériques proposées par le tirage sont écartées : elles ne disent rien du sujet.

**Règles maison Taste adoptées** pour le site public : titres sur deux lignes au plus, aucun petit label au-dessus d'un titre, aucun dégradé, aucune ombre sur un élément au repos, aucun filet décoratif, aucun mot du titre mis en couleur, deux polices au maximum, un seul rayon d'angle, marges extérieures égales, contrôle par `lint.mjs` et `inspect.mjs` (0 FAIL à 1440, 768 et 390 px).

**Conséquence sur la maquette actuelle.** La section « Accueil public » de `maquettes/design-system.html` est **remplacée** par cette direction. Elle enfreint plusieurs règles Taste : héro en dégradé, « se lève » en or, ombres, filets entre les missions. Elle sera reconstruite selon la direction ci-dessus à la tâche T4.0. Les écrans de gestion suivront le chapitre « écrans d'application » du skill : couleur réservée aux données, indicateurs en texte et non en tuiles, surfaces plates. Ce style sera tiré à la tâche T2.1.

**Arbitrage entre les deux skills de conception.** Impeccable reste le garant de la qualité d'ensemble : accessibilité, états, audit final. Taste fixe la direction artistique et les règles maison du site public. En cas de conflit sur une page publique, ce sont les règles Taste qui s'appliquent.

## 8. Implémenté en L2

Le lot L2 a livré les jetons, le kit de composants et les trois gabarits (administration, public, impression) de la maquette approuvée (`docs/conception/maquettes/l2/`).

### Fichiers

| Rôle | Fichiers |
|---|---|
| Jetons (couleurs, typographie, espacements, rayons, focus) | `public/assets/css/tokens.css` — source unique, lue aussi par `bin/check-contrast.php` |
| Base et polices | `public/assets/css/base.css`, `public/assets/fonts/` (Marcellus, Source Sans 3, auto-hébergées, licence OFL) |
| Composants | `public/assets/css/components.css`, `app/views/components/*.php`, `app/Core/Ui.php` (fonction `ui()`), `public/assets/img/icons.svg` (`ui_icon()`) |
| Gabarit d'administration | `app/views/layouts/admin.php`, `app/views/admin/_nav.php` (menu), `public/assets/css/admin.css`, `auth.css` (écrans de connexion) |
| Gabarit public | `app/views/layouts/public.php`, `public/assets/css/public.css` |
| Gabarit imprimable (A4, CR80) | `app/views/layouts/print.php`, `app/views/components/document-header.php`, `public/assets/css/print.css` |
| Comportements | `public/assets/js/app.js` (menu mobile, confirmations, double soumission), `js-flag.js` |
| Galerie de référence | `/admin/composants` et `/admin/composants/document` (introuvables en production) |
| Contrôle des contrastes | `bin/check-contrast.php`, `app/Core/ContrastChecker.php`, `tests/Unit/ContrastCheckerTest.php` |

Composants disponibles (`ui('nom', [...])`) : `button`, `field` (saisie, liste, zone de texte), `file-input` (propriété `required` : attribut HTML, astérisque et mention « obligatoire » pour les lecteurs d'écran, comme `field`), `alert`, `badge`, `panel`, `page-header`, `breadcrumbs`, `tabs`, `table` (liste de cartes sur téléphone), `pagination`, `empty-state`, `confirm-dialog`, `validation-steps`, `document-header`. La galerie les montre dans tous leurs états.

### Règles de contribution

**Ne jamais utiliser de style en ligne.** La politique CSP (`style-src 'self'`, `script-src 'self'`) interdit `style="…"`, `<style>`, `<script>` en ligne et les gestionnaires `onclick=` ; le kit refuse d'ailleurs `on*`, `style` et `formaction` dans `attrs`. Toute règle va dans un fichier CSS de `public/assets/css/`, toute couleur ou mesure passe par un jeton (`var(--…)`), jamais par une valeur recopiée. Le test `ViewsPolicyTest` garde ce point.

**Comment ajouter un écran d'administration.**
1. Écrire la vue dans `app/views/admin/<module>/…php` en n'utilisant que `ui()` : `page-header` en tête, puis `panel`, `table`, `field`, `button`…
2. Dans le contrôleur, rendre avec `layouts/admin` en fournissant `title`, `user`, `can`, `csrfToken` et `flash` (voir la méthode `page()` de `UserController`).
3. Déclarer la route dans `routes/admin.php` avec `[$requireAuth, $permission('x')]` ; le test de balayage des routes la contrôle automatiquement.
4. Écrire les textes en français, vérifier l'écran à 1440, 768 et 360 px, puis ajouter un état de la galerie si un nouveau composant est créé.

**Comment ajouter un lien au menu.** Ajouter une ligne `[groupe, libellé, href, permission, icône]` dans `app/views/admin/_nav.php`. Le lien n'apparaît que si l'utilisateur détient la permission et que la route GET existe ; l'icône doit exister dans `icons.svg`.

**Comment ajouter un composant.** Créer `app/views/components/<nom>.php` (échapper toute valeur avec `$e()`), l'ajouter aux jeux hostiles de `tests/Unit/UiTest.php`, puis à la galerie.

**Couleurs.** Toute nouvelle paire texte/fond se déclare dans `ContrastChecker::PAIRS` ; `php bin/check-contrast.php` (ou `composer test`) échoue sous 4,5:1 (texte) ou 3:1 (grands textes, bordures de champs, anneaux de focus).

### Ajustements de L5

- `page-header` : les actions sont groupées (`.page-actions`, espacement `--s3`, retour à la ligne).
- `.cell-link` : lien de tableau ou de liste atteignable (44 px au pointeur tactile).
- `validation-steps` : tous les repères partagent le même fond neutre ; l'état se lit à l'icône, à la couleur et au texte (le contrôleur Taste refusait des pastilles de teintes différentes). Une étape « à venir » n'est jamais marquée courante.
- `print.php` : liste imprimable (`.list-print`, 10 pt, colonne du numéro sans retour à la ligne) ; l'ombre de la feuille sur écran est réduite à 8 %.
- Aucun nouveau composant du kit ; le rejet d'une demande utilise la case `.check` « Je confirme » (la boîte `confirm-dialog` ne peut pas porter le motif saisi).

### Contrôle de qualité (fin de L2)

- Skill Taste : `lint.mjs --app` sur vues, CSS et JS, puis `inspect.mjs --app` sur la galerie, la connexion, le tableau de bord, utilisateurs (liste, création), rôles (liste, fiche), journal, changement de mot de passe, page d'erreur, et `inspect.mjs` (page) sur `/` — 1440, 768 et 390 px. 0 échec. Le détail des avertissements justifiés figure dans le rapport de la tâche 7.
- Détecteur Impeccable, contrôle à 360 px (aucun défilement horizontal, cibles tactiles de 44 px, menu au clavier avec retour du focus), contrastes (toutes les paires déclarées conformes).
- Écarts assumés : `--control-border` (#7D8AA0) remplace `--line-strong` pour les bordures de champs (3:1 exigé) ; la page publique `/` est une page d'attente et ne porte pas encore les photos ni les animations exigées d'une vraie page (lot L4).

### Ajustements de L3 (CMS)

- **`rich-editor`** (`ui('rich-editor', [name, label, value, hint, error, required])`) : champ de texte riche limité. Sans JavaScript c'est un `<textarea>` (la valeur est échappée) qui reçoit du HTML autorisé ; avec `public/assets/js/editor.js` (externe, différé, ≤ 12 Ko, sans `innerHTML`, `eval` ni style en ligne) une barre d'outils s'ajoute : titres 2 et 3, gras, italique, listes, citation, lien, image de la médiathèque, effacer la mise en forme. Les boutons font 44 px au pointeur tactile ; le contenu collé est analysé (DOMParser) puis reconstruit ; **le serveur reste l'autorité** (`RichText::sanitize` à l'enregistrement et à l'affichage).
- `.cell-link` appliqué aux titres et références des listes d'administration (pages, articles, formulaires reçus) : cible de 44 px au pointeur tactile.
- `.block-editor` (fieldset d'un bloc de page, ancre `:target` avec anneau de focus), `.media-grid`/`.media-card` (médiathèque et sélecteur), `.history-list`, `.prewrap` (texte de visiteur ou note, retours à la ligne conservés).
- Libellés : « Nouvelle bannière » (nom féminin) ; historique des formulaires avec états en toutes lettres et noms d'utilisateur.
- Contrôle de qualité L3 : `inspect.mjs --app` sur 23 écrans à 1440/768/390 (0 échec sur les écrans ; le sélecteur de médias est un fragment de boîte de dialogue, non un écran), 360 px sans défilement horizontal, `bin/check-contrast.php` conforme.

