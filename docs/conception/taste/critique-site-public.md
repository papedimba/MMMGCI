# Critique Taste — site public (L4, tâche 2)

Direction : « La page est l'objet » (graine 878571445, `direction-site-public.json`, § 7 de `05-design-system.md`), palette et polices imposées par le logo (`--keep palette,type`).
Idée : *sur ce site, le livret maritime du marin devient l'objet imprimé qui porte tout le contenu* — cartes en papier à bande de cordage tressé, posées sur le marine.
Chose vivante : « Préparer mon dossier » (bloc `dossier`) — le visiteur coche sa situation, la liste des pièces de l'article 6 se filtre ; sans JavaScript la liste entière reste lisible.

Contenu d'essai : pages jetables publiées dans la base de développement avec les seuls textes du cahier des charges (art. 1, 2, 5, 6, 9, 21–23), retirées ensuite. Aucune photo : l'emplacement d'image reçoit le dessin `art/livret.svg`, légendé « Illustration ».

Pages contrôlées (`inspect.mjs`, mode page, 1440/768/390) : accueil, page intérieure (« La Mutuelle », « Demander mon adhésion »), actualités (liste et article), documents, galeries, 404.

## Tour 1

```
ROUND 1
Seen in one second: une carte en papier sur fond marine avec un grand titre, un dessin de livret à droite.
Idea (one sentence): le livret maritime comme objet imprimé (bande de cordage, coin coupé, carte de menu).
Live thing: « Préparer mon dossier » (cases de situation, liste filtrée).
Layout words by section: hero=carte + dessin / intro=titre et note / domaines=carte de menu / faits=rangée / étapes=feuille / dossier=carte à deux colonnes / actualités=fiche / appel=carte-réponse / pied=dos de la carte
Scores: design 6 · usability 7 · creativity 7 · content 7 → overall 6.6.
Caps applied: FAIL focal-point (titre 4,4 vw) et contraste du titre « Ce que fait la Mutuelle » (marine sur marine) → plafond 6.0.
Top three problems (most visible first), each with the screenshot it shows in and the fix:
1. Titre d'intro illisible (marine sur marine, 1,2:1) — home/desktop-page-01 : sélecteur `:is(... .pub-intro h2)` invalide, remplacé par deux sélecteurs explicites.
2. Pas d'élément dominant au premier écran (titre 64 px, dessin à 12 % de l'écran) — home/desktop-fold : titre porté à clamp(…, 5,5rem) (88 px à 1440), colonnes 1fr/1fr.
3. Pages intérieures : titre et fil d'Ariane désalignés, colonne de 68 caractères qui flotte, marges inégales (en-tête à 32 px, contenu à 160 px) — la-mutuelle/desktop-fold : en-tête, bandeau et colonne calés sur la même largeur (`--wrap`), colonne centrée et titre sur son bord gauche.
```

## Tour 2

```
ROUND 2
Seen in one second: « Un nouveau jour se lève » en grand sur la carte du livret, bouton « Demander mon adhésion », dessin du livret tamponné.
Idea (one sentence): inchangée ; portée par toutes les sections (cartes, cordage, coin coupé, pied « dos de la carte »).
Live thing: inchangée ; vérifiée à 390 px (case « Livret maritime disponible » : 5 pièces, compteur annoncé).
Layout words by section: inchangés ; intro en deux colonnes à 1440, étapes en trois colonnes de texte, appel bouton à droite.
Scores: design 7 · usability 8 · creativity 8 · content 7 → overall 7.5.
Caps applied: moins de quatre vraies images (photos interdites par le contrat, voir plus bas) → design ≤ 6,5 au sens strict ; overall retenu 7.1 avec ce plafond.
Top three problems (most visible first), each with the screenshot it shows in and the fix:
1. La bande « cordage » lisait comme un ruban de chantier (hachures) — home/desktop-fold : redessinée en torons torsadés papier/ombre sur marine (art/cordage.svg, 427 octets).
2. Pied de page maigre (logo et devise seuls) — 404/desktop-fold : le nom complet de la Mutuelle revient en grand (Marcellus), devise dorée, logo à droite.
3. Carte de menu au téléphone : la nature de l'aide en colonne étroite sur 4 lignes — home/mobile-page-01 : sous 600 px la nature passe sous le domaine, alignée à droite, les pointillés vont jusqu'au bord.
```

## Tour 3 (vérification)

```
ROUND 3
Seen in one second: idem tour 2 ; à 768 la carte prend toute la largeur, le dessin suit.
Scores: design 7 · usability 8 · creativity 8 · content 7 → overall 7.5 (7.1 avec le plafond « images »).
Top three problems restants:
1. Aucune photographie réelle : la Mutuelle n'en a pas et le contrat interdit d'en inventer ; l'emplacement d'image accepte l'image à la une choisie dans le CMS dès qu'elle existe.
2. Pages intérieures courtes (documents, galeries vides) : beaucoup de papier vide sous l'état vide honnête ; se remplira avec le contenu de la Mutuelle.
3. Le fil d'Ariane d'une page de premier niveau se réduit à « Accueil » au-dessus du titre ; accepté (navigation, pas un label).
```

## Mesures finales

`inspect.mjs` (`--allow few-pictures,no-real-pictures,no-footage`, voir justification) : **0 FAIL** sur les 8 pages, aux trois largeurs.

| Page | WARN restants | Justification |
|---|---|---|
| toutes | `palette-cream` | `--paper` #FAF8F3 est le papier de la maquette approuvée et des jetons L2 (§ 7 : « cartes papier #FAF8F3 sur fond marine ») ; ce n'est pas un fond par défaut. |
| la-mutuelle, actualités, documents, galeries, 404 | `name-only-title` | Le titre est le nom de la page saisi dans le CMS ou le nom de la liste ; écrire une phrase d'accroche serait du contenu inventé. |
| actualités, documents, galeries, 404 | `still-page` (avertissement, 12–14 éléments animés) | Pages courtes : filet national tracé, menu, titre et blocs apparaissent ; pas de mouvement ajouté sans contenu. |

Règles autorisées (`--allow`) : `few-pictures`, `no-real-pictures`, `no-footage` — contrainte du lot (aucune photo, aucune vidéo, rien d'inventé ; les dessins ne comptent pas). La recherche dans les collections libres (`assets.mjs`) n'a pas été utilisée : le contrat n'admet que les médias fournis par la Mutuelle.

`lint.mjs` (public.css, site.js, HTML rendu des 8 pages) : 0 FAIL, 2 WARN — `outlined-box` ×2 sur `.preview-facts` / `.preview-cta` (aperçu d'administration L3, hors site public, laissé tel quel).

Détecteur Impeccable (sources et pages à 1280 et 390) : `border-accent-on-rounded` corrigé (bande pleine de la carte-réponse retirée) ; « veuves » corrigées (`text-wrap: pretty`, mention insécable) ; reste `cream background` (même justification que `palette-cream`).

360 px : aucun défilement horizontal (accueil, page, article, 404, documents) ; cibles ≥ 44 px (les cases à cocher de 22 px sont dans une étiquette de 44 px).
