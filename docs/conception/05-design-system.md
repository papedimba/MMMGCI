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
