# 06 — Plan de développement par phases et tâches

> Statut de toutes les tâches : **À faire** (en attente de validation de la phase 1). Suivi courant dans [`/TASKS.md`](../../TASKS.md).
> Priorités : **P0** critique (bloque d'autres lots ou sécurité/finances) · **P1** essentiel V1 · **P2** utile V1 · **P3** finition.
> Chaque tâche suit le cycle Superpowers : plan → tests d'abord (TDD) quand la logique est non triviale → implémentation → vérification → revue.
> Décisions du 13/11/2026 intégrées : socle PHP natif sans framework (D1), règles = cahier des charges seul (D2), pas encore d'hébergement (D4), skill Taste pour l'interface.

## Vue d'ensemble des lots

| Lot | Contenu | Dépend de | Prio. | Lien plan d'action CDC |
|---|---|---|---|---|
| L0 | Socle technique PHP natif (16 tâches) | — | P0 | — |
| L1 | Authentification, RBAC, audit | L0 | P0 | Fichier sécurisé (prio. 2) |
| L2 | Design system et gabarits d'interface | L0 | P0 | — |
| L3 | CMS | L1, L2 | P1 | Communication |
| L4 | Site public + adhésion en ligne | L3 | P1 | Recensement (prio. 1) |
| L5 | Adhérents, demandes, cartes | L1, L2 | P0 | Prio. 1–2 |
| L6 | Cotisations, finances, double validation, fonds de solidarité | L5 | P0 | Prio. 4 |
| L7 | Demandes d'assistance sociale | L5, L6 | P1 | Art. 9–10 |
| L8 | Emploi, formation, anciens marins | L5, L9 (partiel) | P1 | Prio. 6 |
| L9 | Partenariats | L1 | P1 | Prio. 3 |
| L10 | Gouvernance, délégués, points focaux, mobilisation | L5 | P1 | Prio. 5 |
| L11 | Rapports, indicateurs, tableau de bord | L5–L10 | P1 | Art. 19–20 |
| L12 | Discipline | L10 | P2 | Art. 16–18 |
| L13 | Installation cPanel, sauvegardes, conservation des données, documentation | L0–L12 | P0 | — |
| L15 | Espace adhérent (compte personnel) | L5, L6, L7 | P1 | Fichier des membres (prio. 2) ; demande du commanditaire |
| L14 | Recette : E2E Playwright, sécurité, accessibilité, performance | tous | P0 | — |

Chemin critique : **L0 → L1 → L5 → L6 → L7 → L11 → L13 → L14**.
Livraisons intermédiaires proposées : **V0.1** = L0–L2 + L5 (fichier central utilisable pour le recensement) · **V0.2** = + L3–L4 (site en ligne) · **V0.3** = + L6–L7 · **V0.4** = + L15 (Espace adhérent) · **V1.0** = tout.

---

## L0 — Socle technique (PHP natif, sans framework)

| ID | Tâche | Description | Dép. | Prio. | Fichiers | Critères d'acceptation | Tests |
|---|---|---|---|---|---|---|---|
| T0.1 | Squelette et configuration | Arborescence B3, autoload PSR-4 maison, `config.php` lisant `.env`, fuseau Africa/Abidjan, locale fr, gestion d'erreurs (journal + page générique), `.htaccess` | — | P0 | `app/Core`, `public/`, `config/` | Page d'accueil « socle OK » ; aucun détail d'erreur affiché en production | Test de démarrage |
| T0.2 | Environnement de test | MariaDB locale, PHPUnit `.phar`, script `bin/test`, base de test recréée par les migrations | T0.1 | P0 | `tests/`, `phpunit.xml` | Suite vide verte sur MySQL | — |
| T0.3 | Routeur, requête, réponse, middleware | Routes nommées, paramètres typés, groupes avec middleware, 404/405/403 propres | T0.1 | P0 | `Core/Router.php`… | Route inconnue → 404 ; méthode interdite → 405 | Unitaires |
| T0.4 | Vues et échappement | Gabarits PHP, mises en page, partiels, `e()` par défaut, messages flash | T0.3 | P0 | `Core/View.php` | Une chaîne `<script>` affichée en texte | Unitaire XSS |
| T0.5 | Base de données et migrations | `Core\Database` (PDO préparé seulement, transactions, `FOR UPDATE`), `Migrator` (fichiers `.sql` numérotés, sommes de contrôle, exécution depuis le web) | T0.1 | P0 | `Core/Database.php`, `Core/Migrator.php` | Migration appliquée une seule fois ; fichier modifié après application détecté | Unitaires |
| T0.6 | Validation | Règles (requis, longueur, e-mail, téléphone CI, date, montant, fichier, unique…), messages en français | T0.3 | P0 | `Core/Validator.php` | Messages qui disent le problème et la correction | Unitaires |
| T0.7 | Session et CSRF | Cookies `Secure`/`HttpOnly`/`SameSite`, régénération, expiration d'inactivité, jeton CSRF, jeton à usage unique pour formulaires sensibles | T0.3 | P0 | `Core/Session.php`, `Core/Csrf.php` | POST sans jeton → 419 ; rejeu d'un jeton à usage unique refusé | Unitaires + fonctionnels |
| T0.8 | En-têtes de sécurité et HTTPS | CSP sans script en ligne, HSTS, nosniff, frame, referrer, redirection HTTPS | T0.3 | P0 | middleware | En-têtes présents partout | Fonctionnel |
| T0.9 | Fichiers privés/publics | Table `files`, liste blanche extension + MIME (`finfo`) + taille, nom aléatoire, SHA-256, ré-encodage GD des images, téléchargement contrôlé | T0.5 | P0 | `Core/Uploads.php` | `.php`, double extension, SVG, faux MIME refusés ; fichier privé inaccessible par URL | Unitaires + fonctionnels |
| T0.10 | Montants et références | `Money` (BCMath, XOF), `sequences` + générateur de références en transaction | T0.5 | P0 | `Core/Money.php` | Aucun calcul en flottant ; références uniques | Unitaires |
| T0.11 | Paramètres administrables | Table `settings` avec statut `non_configure`/`valide` et référence de décision | T0.5 | P0 | `Core/Settings.php` | Paramètre non validé inutilisable, bandeau affiché | Unitaires |
| T0.12 | Audit | `Core\Audit` en ajout seul, chaîne de hachage, commande de vérification d'intégrité | T0.5 | P0 | `Core/Audit.php` | Altération d'une ligne détectée | Unitaires |
| T0.13 | E-mail | `mail()` par défaut, SMTP minimal en option, gabarits texte | T0.1 | P1 | `Core/Mailer.php` | Envoi testé sur un serveur SMTP de test | Fonctionnel |
| T0.14 | Cron | `bin/cron.php`, tâches enregistrées, verrou anti-chevauchement (`scheduled_runs`) | T0.5 | P1 | `bin/` | Deux exécutions simultanées → une seule travaille | Unitaire |
| T0.15 | Utilitaires d'export | CSV UTF-8 BOM, XLSX via `ZipArchive`, pages imprimables A4/CR80, graphiques SVG | T0.4 | P1 | `Core/Export/` | Fichier ouvert sans erreur par Excel/LibreOffice ; impression nette | Fonctionnels |
| T0.16 | Nettoyage HTML et éditeur | Liste blanche `DOMDocument` ; éditeur minimal `contenteditable` | T0.4 | P0 | `Core/HtmlSanitizer.php`, `public/assets/js/editor.js` | Scripts, `on*=`, `javascript:` supprimés | Unitaires XSS |

## L1 — Authentification, RBAC, audit

| ID | Tâche | Description | Dép. | Prio. | Fichiers | Critères | Tests |
|---|---|---|---|---|---|---|---|
| T1.1 | Connexion / déconnexion / mot de passe oublié | Argon2id, régénération de session, expiration d'inactivité, e-mail de réinitialisation | L0 | P0 | `Auth/` | Session régénérée ; message d'erreur neutre | Fonctionnels + E2E connexion |
| T1.2 | Limitation des tentatives | 5 essais / 15 min par e-mail+IP, journal `login_attempts` | T1.1 | P0 | middleware | 6ᵉ tentative bloquée et journalisée | Fonctionnel |
| T1.3 | Rôles et permissions | Tables propres + `Gate`, données initiales des 15+5 rôles proposés et permissions `module.action` (sans approbateur de décaissement par défaut), écran d'administration | T1.1 | P0 | `database/seeders`, `Admin/Roles` | Rôles modifiables ; aucune permission déduite d'un titre | Unitaires + E2E rôles |
| T1.4 | Contrôle d'accès serveur | Middleware de permission sur chaque route admin + `Gate` dans les services ; filtrage par périmètre (zone, commission) | T1.3 | P0 | `Core/Gate.php` | Test automatique : toute route admin sans permission → 403 | Test « balayage des routes » |
| T1.5 | Gestion des utilisateurs | CRUD, activation, rôles multiples, lien éventuel membre/délégué | T1.3 | P0 | `Admin/Users` | Un utilisateur ne peut pas s'attribuer de rôle | Fonctionnels |
| T1.6 | Consultation du journal d'audit | Écran filtrable en lecture seule, branchement de `Core\Audit` (T0.12) sur toutes les écritures | T1.1 | P0 | `app/Audit` | Aucune route d'édition ; altération détectée par la commande | Unitaires (chaîne) |
| T1.7 | Journal des consultations sensibles | Pièces d'identité, données médicales | T1.6 | P1 | `app/Audit` | Chaque ouverture journalisée | Fonctionnel |
| T1.8 | 2FA TOTP (si validée) | Pour rôles financiers et super admin | T1.1, D | P2 | `Auth/` | Accès refusé sans code | Fonctionnel |

## L2 — Design system et gabarits

| ID | Tâche | Description | Dép. | Prio. | Critères | Tests |
|---|---|---|---|---|---|---|
| T2.1 | Jetons et CSS de base | Variables CSS, polices auto-hébergées, surfaces navigateur ; style d'application tiré avec Taste (`draw.mjs --app --keep palette,type`) | L0 | P0 | Conforme à `05-design-system.md` | `inspect.mjs --app` sans FAIL |
| T2.2 | Composants (partiels PHP) | Boutons, champs, alertes, badges, tableaux, panneaux, vide, pagination, téléversement, modale de confirmation | T2.1 | P0 | États complets ; clavier | axe-core sur page de démonstration |
| T2.3 | Gabarit public | En-tête (logo), menu mobile, pied de page, filet national | T2.2 | P0 | 360 px sans débordement | E2E responsive |
| T2.4 | Gabarit d'administration | Menu latéral par permissions, barre supérieure, recherche globale, notifications | T2.2, T1.4 | P0 | Menu filtré selon droits | E2E par rôle |
| T2.5 | Gabarits imprimables | Reçu, carte CR80, PV, rapport (`@media print`, « Enregistrer en PDF » du navigateur) | T2.1 | P1 | Lisibles en impression N&B | Rendu PDF Playwright vérifié |

## L3 — CMS

| ID | Tâche | Critères d'acceptation | Prio. | Tests |
|---|---|---|---|---|
| T3.1 | Pages (CRUD, blocs, brouillon, publication, programmation, slug, SEO, image, ordre, hiérarchie) | Rédacteur : brouillon + soumission seulement | P1 | E2E création → publication |
| T3.2 | Menus et sous-menus (glisser-déposer accessible + boutons monter/descendre) | Modification visible côté public | P1 | E2E |
| T3.3 | Articles, catégories, tags, auteur, archivage, partage réseaux sociaux | Article programmé publié par le cron | P1 | Fonctionnel + E2E |
| T3.4 | Médiathèque (dossiers, recherche, réutilisation, alt obligatoire pour images) | Upload refusé hors liste blanche | P1 | Sécurité upload |
| T3.5 | Événements, documents officiels, galerie, bannières, témoignages (désactivés par défaut, consentement requis) | Chaque type éditable et affiché | P2 | Fonctionnels |
| T3.6 | Paramètres du site (coordonnées, réseaux, pied de page) | Modifiables sans code | P1 | Fonctionnel |
| T3.7 | Formulaires reçus (contact, information, partenariat) : statuts, affectation, historique, notification interne | Message traité de bout en bout | P1 | E2E |
| T3.8 | SEO : sitemap.xml, robots.txt, canonique, Open Graph | Sitemap valide, pages non publiées absentes | P1 | Fonctionnel |
| T3.9 | Intégration de l'éditeur et du nettoyage (T0.16) dans pages et articles | Script injecté neutralisé | P0 | Test XSS |

## L4 — Site public et adhésion en ligne

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T4.0 | Direction artistique Taste « La page est l'objet » (voir `05-design-system.md` §7) : inventaire, texte de chaque page, images libres de droits créditées, chose interactive | Aucun FAIL `inspect.mjs` à 1440/768/390 ; critique écrite | P1 | `inspect.mjs`, `lint.mjs` |
| T4.1 | 18 pages et gabarits (accueil, présentation, historique, missions, gouvernance, actions sociales, fonds, adhésion, emploi, formation, partenaires, actualités, événements, documents, galerie, contact, mentions, confidentialité) | Toutes alimentées par le CMS ; états vides si pas de contenu | P1 | E2E navigation |
| T4.2 | Accueil (héro, slogan, missions, adhésion, actualités, actions, partenaires, contacts) | Sections pilotées par le CMS | P1 | E2E |
| T4.3 | Formulaire d'adhésion multi-étapes avec pièces (stockage privé), consentement RGPD/loi 2013-450, anti-spam (pot de miel + limite) | Crée une `membership_application` « reçue », jamais un membre ; accusé avec référence | P0 | E2E + upload |
| T4.4 | Formulaires contact / partenariat | Arrivent dans T3.7 | P1 | E2E |
| T4.5 | Page de vérification de carte (QR) | N'affiche que nom, n°, statut, validité ; jeton révoqué → « carte non valide » | P1 | Fonctionnel |
| T4.6 | Performance mobile | Page d'accueil < 200 Ko hors photos, images responsives | P1 | Mesure Playwright |

## L5 — Adhérents

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T5.1 | Référentiels (catégories art. 5, professions, spécialités, zones, statuts VAL) | Administrables | P0 | Fonctionnels |
| T5.2 | Fiche adhérent (onglets identité, profession, ayants droit, documents, cotisations, demandes, historique) | Champs de § 9.1 ; pièces en privé | P0 | E2E création/modification |
| T5.3 | Instruction des demandes d'adhésion (vérification, compléments, validation, rejet motivé) | Validation → membre + n° + historique ; rejet sans motif impossible | P0 | E2E workflow |
| T5.4 | Changements de statut (suspension, réactivation, réadhésion, décès, ancien marin) avec motif et référence | Historique complet | P0 | Unitaires |
| T5.5 | Recherche multicritère, filtres, tri, pagination | < 1 s sur 2 000 membres | P0 | Perf |
| T5.6 | Exports CSV/XLSX/PDF, impression | Réservés à `members.export`, journalisés | P1 | Fonctionnel 403 |
| T5.7 | Carte de membre PDF + QR + révocation | Conditions de délivrance VAL respectées si configurées | P1 | Fonctionnel |
| T5.8 | Saisie terrain par délégué (recensement) | Le délégué ne voit que sa zone | P1 | Fonctionnel périmètre |
| T5.9 | Détection de doublons (nom + date de naissance + téléphone) | Alerte avant création | P2 | Unitaire |

## L6 — Cotisations, finances, double validation, fonds de solidarité

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T6.1 | Types de cotisations (montant NULL tant que non validé) | Aucun appel automatique sans montant validé | P0 | Unitaire |
| T6.2 | Appels de cotisations (individuels et par lot via cron) | Pas de doublon d'appel (contrainte UNIQUE) | P1 | Unitaire |
| T6.3 | Encaissements + reçus numérotés + annulation par contre-passation | Idempotence ; reçu PDF | P0 | Unitaires + E2E |
| T6.4 | Impayés et situation « à jour » (règle VAL) | Liste exacte sur jeu de test | P1 | Unitaire |
| T6.5 | Comptes, catégories, transactions immuables, autres ressources (dons, legs, subventions, partenaires, contributions autorisées avec référence) | Solde recalculable | P0 | Unitaires |
| T6.6 | **Circuit de décaissement** (brouillon, soumission, approbations, rejet, révision, paiement unique, justificatifs) | Toutes les règles de `03-architecture.md` A5.3 | P0 | **Suite TDD dédiée** + E2E double validation |
| T6.7 | Règles d'approbation par tranche (VAL V14) | Jamais < 2 | P1 | Unitaire |
| T6.8 | Fonds de solidarité (compte dédié, tableau de bord, rapports) | Solde = Σ entrées − Σ sorties | P0 | Unitaire |
| T6.9 | Budgets et engagements | État budget / réalisé | P2 | Fonctionnel |
| T6.10 | Écran de contrôle (organe de contrôle) | Lecture seule complète + exports | P1 | Fonctionnel |

## L7 — Demandes d'assistance sociale

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T7.1 | Types de demandes (critères, plafond, carence, organe : NULL par défaut) | Paramètres VAL affichés comme « non configurés » | P1 | Unitaire |
| T7.2 | Dossier (référence, priorité, responsable, pièces, données médicales protégées) | Accès médical journalisé | P0 | Fonctionnel |
| T7.3 | Instruction, avis, décision par l'organe, motif obligatoire | Pas de décision sans avis | P0 | Unitaire |
| T7.4 | Lien décision → décaissement (pré-rempli, soumis au circuit L6) | Jamais de paiement direct | P0 | E2E |
| T7.5 | Clôture et archivage, historique | Cycle complet | P1 | E2E |

## L8 — Emploi, formation, anciens marins

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T8.1 | Offres (saisie, vérification, publication, expiration auto, archivage) | Publiée seulement après vérification | P1 | E2E |
| T8.2 | Candidatures / demandes de mise en relation, suivi des placements | Statuts tracés | P1 | Fonctionnel |
| T8.3 | Centres et opportunités de formation, demandes d'orientation | Publiables | P2 | Fonctionnel |
| T8.4 | Suivi anciens marins (parcours, situation, accompagnement) | Aucune prestation financière propre | P2 | Fonctionnel |
| T8.5 | Statistiques emploi/formation | Données réelles | P2 | Unitaire |

## L9 — Partenariats

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T9.1 | Fiches partenaires (catégories art. 12), affichage public optionnel | Champs § 15 | P1 | Fonctionnel |
| T9.2 | Conventions (objet, engagements, durée, suivi, fin, PDF signé) | Champs obligatoires art. 12 | P1 | Fonctionnel |
| T9.3 | Activités et échanges, alertes de renouvellement (cron) | Alerte 60 j avant échéance (paramétrable) | P2 | Unitaire |

## L10 — Gouvernance, délégués, mobilisation

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T10.1 | Organes, fonctions, mandats, commissions | Fonctions sans pouvoir implicite | P1 | Fonctionnel |
| T10.2 | Réunions : convocation (e-mail + PDF), ordre du jour, présences, PV | PV PDF archivé | P1 | E2E |
| T10.3 | Décisions et suivi d'exécution | Tableau des décisions en retard | P1 | Fonctionnel |
| T10.4 | Délégués et points focaux, zones, fiches de mission | — | P1 | Fonctionnel |
| T10.5 | Rapports périodiques des délégués (mobile, brouillon) | Envoi depuis smartphone 360 px | P1 | E2E mobile |
| T10.6 | Activités de mobilisation et campagnes de communication | Comptées dans les indicateurs | P2 | Unitaire |

## L11 — Rapports et indicateurs

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T11.1 | Requêtes d'indicateurs (7 domaines de l'art. 20) | Vérifiées sur jeu de données connu | P0 | Unitaires (valeurs attendues) |
| T11.2 | Tableau de bord par rôle | Base vide → 0 / états vides | P1 | E2E |
| T11.3 | Rapports mensuel / trimestriel (BE, financier, par commission, par délégation), comparaisons de périodes | PDF + CSV/XLSX + impression | P1 | Fonctionnel |
| T11.4 | Graphiques SVG serveur accessibles (tableau de données associé) | Lisibles sans couleur | P2 | axe-core |

## L12 — Discipline

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T12.1 | Mesures disponibles (liste vide par défaut, référence d'article) | Aucune mesure inventée | P2 | Unitaire |
| T12.2 | Dossier : signalement, notification, observations, examen, décision, archivage | Décision impossible sans notification et observations/délai | P2 | Unitaires |
| T12.3 | Accès restreint et journalisé | — | P2 | Fonctionnel |

## L13 — Installation, exploitation, documentation

| ID | Tâche | Critères | Prio. | Tests |
|---|---|---|---|---|
| T13.1 | Script d'empaquetage (sans `tests/`, `e2e/`, `docs/` de dev) | Archive installable | P0 | Installation sur cPanel de recette |
| T13.2 | Installateur web (prérequis, `.env`, clé, migrations, données de référence, 1ᵉʳ super admin, verrouillage) | Fonctionne sans SSH | P0 | E2E installateur |
| T13.3 | Page de mise à jour (migrations en attente) + mode maintenance | Sans SSH | P0 | Test |
| T13.4 | Sauvegardes chiffrées planifiées + rotation + téléchargement | Restauration testée | P0 | Procédure jouée |
| T13.5 | Conservation et anonymisation (durées VAL V13) | Anonymisation irréversible des données personnelles, conservation des écritures financières | P1 | Unitaire |
| T13.6 | Documentation : README, installation cPanel, sauvegarde, restauration, mise à jour, administration, guide utilisateur par rôle, mesures de protection des données | Relue par un tiers | P0 | — |

## L15 — Espace adhérent (décision du commanditaire du 14/11/2026)

| ID | Tâche | Critères d'acceptation | Prio. | Tests |
|---|---|---|---|---|
| T15.1 | Comptes adhérents, authentification séparée (`/espace`), limitation des tentatives | Une session adhérent reçoit 403/redirection sur toute URL `/admin` | P0 | Fonctionnels + E2E |
| T15.2 | Activation : code à usage unique (e-mail ou lettre imprimable), création du mot de passe | Code expiré ou déjà utilisé refusé ; activation possible sans e-mail | P0 | Unitaires + E2E |
| T15.3 | Mot de passe oublié (e-mail) et réinitialisation par un gestionnaire (journalisée) | Ancien mot de passe invalidé, sessions fermées | P0 | Fonctionnels |
| T15.4 | Accueil et « Ma fiche » (lecture, ayants droit) + demandes de correction validées côté gestion | Identité jamais modifiable directement ; correction tracée | P1 | E2E |
| T15.5 | « Mes cotisations » et reçus imprimables | Montants identiques à la gestion | P1 | Fonctionnel |
| T15.6 | « Ma carte » imprimable | Visible seulement si la carte est délivrée | P2 | Fonctionnel |
| T15.7 | « Mes demandes d'assistance » : statut simplifié, décision communiquée, dépôt de pièces | Aucune donnée interne exposée (avis, notes, noms) | P0 | Fonctionnels + E2E |
| T15.8 | Nouvelle demande d'assistance en ligne (si C38 validée) | Arrive au statut « reçue » côté gestion | P1 | E2E |
| T15.9 | Messages à l'adhérent (et e-mail si disponible) quand un dossier avance | — | P2 | Fonctionnel |
| T15.10 | Tests d'accès croisé : un adhérent ne peut lire aucune donnée d'un autre, quelle que soit l'URL | 100 % des routes `/espace` couvertes | P0 | Balayage automatique |
| T15.11 | Écrans mobile d'abord (règles Taste « app screens », thème clair) | `inspect.mjs --app` sans FAIL à 390 px | P1 | Playwright 360/390 |

## L14 — Recette

| ID | Tâche | Critères | Prio. |
|---|---|---|---|
| T14.1 | Suite Playwright : navigation publique, authentification (personnel et adhérent), Espace adhérent, rôles, adhérent, cotisation, demande d'assistance, double validation, CMS, formulaires, uploads, responsive (360/768/1280), accès aux pages privées | 100 % verte, reproductible (`npm run e2e`, données de test réinitialisées) | P0 |
| T14.2 | Revue de sécurité (CSRF, XSS, IDOR, uploads, force brute, en-têtes) | Aucun défaut ouvert | P0 |
| T14.3 | Accessibilité (axe-core + clavier) | 0 violation sérieuse | P1 |
| T14.4 | Charge (2 000 membres, 20 000 paiements) | Écrans < 1 s serveur | P1 |
| T14.5 | Audit Impeccable (`audit`, `polish`) et contrôle Taste (`lint.mjs`, `inspect.mjs`, critique) des écrans clés | 0 FAIL, corrections appliquées | P1 |
| T14.6 | Recette avec le commanditaire sur hébergement de recette | PV de recette | P0 |
