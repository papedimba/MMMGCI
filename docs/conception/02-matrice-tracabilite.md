# 02 — Matrice de traçabilité

> Chaque exigence est reliée à sa source, à sa catégorie (CDC / CMD / TECH / VAL), au module qui la réalise, au lot de développement (`06-plan-taches.md`) et au test d'acceptation prévu.
> Modules : **SOC** socle · **AUTH** authentification/RBAC · **CMS** · **PUB** site public · **ADH** adhérents · **COT** cotisations · **FIN** finances · **FDS** fonds de solidarité · **SOC-AS** demandes sociales · **EMP** emploi · **FOR** formation & anciens marins · **PART** partenariats · **GOV** gouvernance · **DEL** délégués · **DISC** discipline · **RAP** rapports.

## 1. Exigences issues du cahier des charges

| ID | Exigence | Source | Cat. | Module | Lot | Test d'acceptation |
|---|---|---|---|---|---|---|
| E01 | Présenter objectif général et objectifs spécifiques | Art. 1–2 | CDC | PUB/CMS | L4 | Pages « Missions » éditables et publiées |
| E02 | Représenter les organes (AG, CA, BE, contrôle, commissions, délégations) | Art. 3 | CDC | GOV | L10 | Création d'un organe et de ses membres |
| E03 | Responsabilités PCA, 1er VP, SG, TG, Communication, délégués | Art. 4 | CDC | AUTH/GOV | L1, L10 | Rôles proposés présents et modifiables |
| E04 | Convocations, ordres du jour, PV, archives par le SG | Art. 4 | CDC | GOV | L10 | Réunion complète → PV PDF archivé |
| E05 | Suivi de l'exécution des décisions | Art. 4 (VP, SG) | CDC | GOV | L10 | Décision → suivi → statut « exécutée » |
| E06 | Rapports périodiques des délégués au BE | Art. 4, 11 | CDC | DEL | L10 | Délégué dépose un rapport depuis un smartphone |
| E07 | Catégories admissibles (marins, gens de mer, navigants, anciens marins, autres selon statuts) | Art. 5 | CDC + VAL | ADH | L5 | Liste paramétrable ; catégories art. 5 pré-chargées |
| E08 | Admission selon procédure interne | Art. 5 | CDC | ADH | L5 | Demande ≠ membre tant que non validée |
| E09 | Dossier : fiche, pièce d'identité, livret maritime, photo, contacts, ayants droit | Art. 6 | CDC + VAL | ADH/PUB | L4, L5 | Pièces téléversées en stockage privé ; obligatoires paramétrables |
| E10 | Carte de membre : identification, vérification statut, suivi cotisations, accès prestations | Art. 7 | CDC | ADH | L5 | PDF carte + QR → page de vérification minimale |
| E11 | Conditions de délivrance / renouvellement / remplacement fixées par les instances | Art. 7 | VAL | ADH | L5 | Paramètres présents, non configurés |
| E12 | Fichier centralisé sécurisé (identité, profession, situation, coordonnées, date, cotisations, ayants droit, situation administrative) | Art. 8 | CDC | ADH | L5 | Fiche complète, historique |
| E13 | Accès limité aux personnes habilitées, usage légitime | Art. 8 | CDC | AUTH/ADH | L1, L5 | Un rôle sans `members.view` reçoit 403 |
| E14 | Domaines : santé, décès, maladie, famille, emploi, formation, retraite, urgences | Art. 9 | CDC | SOC-AS/EMP/FOR | L7, L8 | Types de demandes pré-chargés, désactivables |
| E15 | Fonds de solidarité distinctement suivi | Art. 10 | CDC | FDS | L6 | Compte dédié, solde séparé |
| E16 | Ressources du fonds : cotisations affectées, contributions exceptionnelles autorisées, dons, legs, partenariats, subventions | Art. 10 | CDC | FDS/COT | L6 | Entrée typée avec justificatif |
| E17 | Toute aide : critères écrits, examen, validation par l'organe compétent | Art. 10 | CDC + VAL | SOC-AS | L7 | Impossible de décider sans avis d'instruction et organe désigné |
| E18 | Décisions et décaissements documentés et justifiés | Art. 10, 14 | CDC | FIN/FDS | L6 | Paiement refusé sans pièce justificative |
| E19 | Campagne de mobilisation : rencontres, sensibilisation, points focaux, communication, réseau de correspondants | Art. 11 | CDC | DEL/CMS | L10 | Activité de mobilisation enregistrée et comptée |
| E20 | Partenariats écrits : objet, engagements, durée, suivi, fin | Art. 12 | CDC | PART | L9 | Convention avec champs obligatoires + PDF joint |
| E21 | Ressources : droits d'adhésion, cotisations, contributions, dons, legs, subventions, partenaires, produits d'activités | Art. 13 | CDC | COT/FIN | L6 | Catégories de recettes pré-chargées |
| E22 | Transparence, traçabilité, justification, contrôle interne | Art. 14 | CDC | FIN/AUTH | L6 | Journal d'audit non modifiable |
| E23 | Séparation engagement / paiement / contrôle | Art. 14 | CDC | FIN | L6 | Permissions distinctes, testées |
| E24 | Aucune dépense hors autorisations | Art. 14 | CDC | FIN | L6 | Paiement impossible sans 2 approbations |
| E25 | État périodique recettes, dépenses, soldes, engagements, pièces | Art. 15 | CDC | RAP/FIN | L11 | Rapport PDF sur période choisie |
| E26 | Obligations des dirigeants et adhérents ; procédure équitable des manquements | Art. 16–18 | CDC | DISC | L12 | Décision impossible sans notification et délai d'observations |
| E27 | Aucune sanction créée hors textes | Art. 18 | CDC + VAL | DISC | L12 | Liste des mesures vide par défaut |
| E28 | Rapport périodique BE (adhésions, cotisations, actions sociales, partenariats, commissions/délégués, difficultés) | Art. 19 | CDC | RAP | L11 | Rapport généré à partir des données |
| E29 | Indicateurs : adhésions, cotisations, partenariats, actions sociales, gouvernance, communication, transparence | Art. 20 | CDC | RAP | L11 | 7 indicateurs calculés et vérifiés sur jeu de test |
| E30 | Plan d'action prioritaire (recensement, fichier central, conventions, fonds, délégués, emploi) | Chap. X | CDC | Tous | Ordre des lots | Ordre des lots respecté |

## 2. Exigences complémentaires du commanditaire

| ID | Exigence | Source | Cat. | Module | Lot | Test |
|---|---|---|---|---|---|---|
| C01 | 18 pages publiques administrables | § 6.1 | CMD | PUB/CMS | L4 | Navigation E2E sur les 18 pages |
| C02 | Accueil : héro, slogan, missions, adhésion, actus, actions, partenaires, contacts | § 6.2 | CMD | PUB | L4 | Sections pilotées par le CMS |
| C03 | Adhésion en ligne avec pièces ; pas de création automatique de membre | § 6.3 | CMD | PUB/ADH | L4 | Soumission → demande « reçue » |
| C04 | Pages : CRUD, brouillon, publication, slug, SEO, image, ordre, dates | § 7.1 | CMD | CMS | L3 | E2E création/publication |
| C05 | Menus multiples, sous-menus, ordre, activation | § 7.2 | CMD | CMS | L3 | Menu modifié visible en public |
| C06 | Articles : catégories, tags, auteur, brouillon, programmation, archivage, SEO, partage | § 7.3 | CMD | CMS | L3 | Article programmé publié par cron |
| C07 | Médiathèque : contrôle extension/taille/MIME, non-exécution | § 7.4 | CMD | CMS | L3 | Upload `.php` renommé refusé |
| C08 | Événements, documents, partenaires, galerie, bannières, témoignages, coordonnées, réseaux, pied de page | § 7.5 | CMD | CMS | L3 | Chaque type éditable |
| C09 | Traitement des formulaires publics avec statuts et historique | § 7.6 | CMD | CMS | L3 | Message contact → « traité » |
| C10 | SEO : titre, description, OG, sitemap, robots, canonique | § 7.7 | CMD | CMS | L3 | `/sitemap.xml` valide |
| C11 | Rôles éditoriaux (super admin, admin site, éditeur, rédacteur, médias) | § 7.8 | CMD | AUTH | L1 | Rédacteur ne peut pas publier ni gérer comptes |
| C12 | Tableau de bord sur données réelles | § 8.1 | CMD | RAP | L11 | Base vide → indicateurs à 0, jamais fictifs |
| C13 | Fiche adhérent complète, historique | § 9.1 | CMD | ADH | L5 | — |
| C14 | Adhésions : manuelle, en ligne, validation, rejet motivé, réactivation, suspension, historique | § 9.2 | CMD | ADH | L5 | Workflow complet E2E |
| C15 | Carte imprimable avec QR minimal | § 9.3 | CMD | ADH | L5 | QR ne révèle que nom, n°, statut, validité |
| C16 | Recherche multicritère, export CSV/Excel/PDF, statistiques | § 9.4 | CMD | ADH | L5 | Export refusé sans `members.export` |
| C17 | Cotisations : types, appels, paiements, impayés, reçus | § 10 | CMD | COT | L6 | Reçu PDF numéroté |
| C18 | Montants en DECIMAL, jamais en flottant | § 10 | CMD | COT/FIN | L6 | Test unitaire sur arrondis |
| C19 | Fonds : entrées, sorties, solde, engagements, rapports | § 11 | CMD | FDS | L6 | Solde = Σ entrées − Σ sorties |
| C20 | Demande sociale : référence, priorité, responsable, historique, décision, montant, clôture | § 12 | CMD | SOC-AS | L7 | Cycle complet E2E |
| C21 | Emploi : offres, armements, candidatures, mises en relation, archivage, stats | § 13 | CMD | EMP | L8 | Offre expirée archivée par cron |
| C22 | Formation : centres, opportunités, demandes d'orientation | § 14 | CMD | FOR | L8 | — |
| C23 | Anciens marins : suivi spécifique, sans régime de retraite | § 14 | CMD | FOR/ADH | L8 | — |
| C24 | Partenaires : fiche complète, conventions, exécution | § 15 | CMD | PART | L9 | — |
| C25 | Gouvernance : mandats, commissions, délégués, missions, réunions, décisions | § 16 | CMD | GOV | L10 | — |
| C26 | Délégués : zone, membres rattachés, rapports, difficultés | § 17 | CMD | DEL | L10 | — |
| C27 | Double validation : initiateur ≠ approbateurs, 2 distincts, rejet bloquant, invalidation sur modification, anti-double paiement | § 18.2 | CMD | FIN | L6 | Suite de tests dédiée (unitaires + E2E) |
| C28 | Journal d'audit non modifiable | § 18.2 | CMD | AUTH | L1 | Aucune route ni permission de modification/suppression |
| C29 | Discipline : signalement → décision, procédure équitable | § 19 | CMD | DISC | L12 | — |
| C30 | Rapports mensuels/trimestriels, graphiques, comparaisons, exports | § 20 | CMD | RAP | L11 | — |
| C31 | RBAC granulaire vérifié côté serveur | § 21 | CMD | AUTH | L1 | Requête HTTP directe sans droit → 403 |
| C32 | Sécurité (PDO, CSRF, XSS, sessions, anti-bruteforce, uploads, journalisation, transactions, HTTPS) | § 22 | CMD | SOC | L0–L1 | Checklist sécurité § 03 |
| C33 | Conservation, anonymisation, traçabilité des consultations, sauvegarde/restauration | § 22 | CMD + VAL | SOC/ADH | L13 | Anonymisation testée |
| C34 | Installation cPanel, cron, sauvegarde, mise à jour, sans SSH | § 3.2, § 26 ph. 8 | CMD | SOC | L13 | Installation sur cPanel de recette |
| C35 | Tests Playwright des parcours listés | § 4.4 | CMD | Tous | L14 | Suite E2E verte |

## 3. Propositions techniques (TECH)

| ID | Proposition | Justification |
|---|---|---|
| T01 | Socle PHP 8.2+ maison, sans framework ni Composer en production | Décision du commanditaire (D1) : zéro dépendance PHP tierce ; protections écrites avec les fonctions natives de PHP |
| T02 | CSS et JS écrits à la main, servis statiques, sans étape de compilation | Aucun Node en production ni en développement pour l'application |
| T03 | Script `bin/cron.php` déclenché par une seule tâche Cron cPanel | Pas de processus permanent |
| T04 | Stockage privé `storage/app/private`, téléchargement via contrôleur autorisé | Pièces confidentielles hors web |
| T05 | Journal d'audit en ajout seul (+ chaîne de hachage) | Inaltérabilité vérifiable |
| T06 | 2FA TOTP pour les rôles financiers et super admin | Risque R9 — à valider |
| T07 | Numérotation `MMMGCI-AAAA-NNNNN` des membres, `DEC-AAAA-NNNN` des décaissements, `AS-AAAA-NNNN` des demandes sociales, `REC-AAAA-NNNNN` des reçus | Références uniques lisibles — format à valider (V11) |
| T08 | Montants XOF stockés en `DECIMAL(15,2)`, affichés sans décimales | Conforme § 10, prêt pour une autre devise |
