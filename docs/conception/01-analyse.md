# 01 — Analyse fonctionnelle (Phase 1)

> Statut : **proposition soumise à validation** — 13/11/2026
> Référence : *Cahier des charges — MMMGCI — Document cadre institutionnel 2026–2027* (23 articles, 10 chapitres), lu intégralement. Copie : `cahier-des-charges-2026-2027.docx` / `.txt`.

Légende des catégories utilisée dans tous les livrables :

| Code | Catégorie |
|---|---|
| **CDC** | Exigence issue du cahier des charges (article cité) |
| **CMD** | Exigence complémentaire du commanditaire (prompt maître, § cité) |
| **TECH** | Proposition technique |
| **VAL** | Paramètre ou règle à valider par les instances dirigeantes |

---

## 1. Vérification des skills demandés

| Skill demandé | Présent ? | Emplacement / équivalent | Usage prévu |
|---|---|---|---|
| **Superpowers** | Oui | plugin `superpowers` (brainstorming, writing-plans, test-driven-development, systematic-debugging, requesting-code-review, verification-before-completion…) | Brainstorming utilisé pour cette phase ; `writing-plans` après validation ; TDD, débogage et revue en phase 6–7. |
| **Taste skill** (remplace « Task Skill », décision du commanditaire du 13/11/2026) | Oui | skill `design-taste-frontend` (tasteskill v2) | Direction artistique du site public tirée par `draw.mjs` (voir `05-design-system.md` §7) ; règles maison et contrôle `inspect.mjs` / `lint.mjs` sur chaque page ; chapitre « app screens » pour le CMS et la gestion. Le suivi des tâches reste tenu dans `06-plan-taches.md` et `TASKS.md`. |
| **Impeccable** | Oui | plugin `impeccable` v4.9.0 | `context` exécuté ; `PRODUCT.md` rédigé (étape *init*) ; *craft-floor* appliqué aux maquettes ; `shape`/`audit`/`polish` aux phases 4 et 7. |
| **Playwright** | Oui | skill `playwright-cli` + Chromium/Playwright préinstallés dans l'environnement | Tests E2E (phase 7) et contrôle visuel des maquettes. Outil de développement uniquement, jamais requis en production. |

Environnement de développement constaté : PHP 8.3.6 CLI, Node 22 (dev seulement). MySQL/MariaDB à installer pour les tests.

---

## 2. Synthèse du projet

La MMMGCI est une **organisation de solidarité** (Préambule, art. 1) pour les marins marchands, gens de mer et leurs familles. Le cahier des charges **complète les statuts et le règlement intérieur sans s'y substituer** (Préambule, art. 23) ; il est lui-même « soumis à l'appréciation des instances dirigeantes » et n'est donc pas encore adopté.

La plateforme demandée regroupe trois espaces partageant une base et un système de droits uniques :

1. **Site public** administrable (présentation, actions, adhésion en ligne, emploi, formation, actualités, documents).
2. **CMS** (pages, menus, articles, médias, formulaires, SEO, utilisateurs éditoriaux).
3. **Espace de gestion** (adhérents, cotisations, finances, fonds de solidarité à double validation, demandes sociales, emploi/formation, partenariats, gouvernance, délégués, discipline, rapports).

Ce que le CDC **ne prévoit pas** et que la plateforme ne fera pas sans décision ultérieure : remboursement d'assurance maladie, épargne, crédit aux membres, régime de retraite, sanctions automatiques. Le **compte personnel adhérent** n'est pas dans le CDC mais il est demandé par le commanditaire (14/11/2026) : catégorie CMD.

---

## 3. Cartographie des acteurs

| Acteur | Source | Rôle dans la plateforme |
|---|---|---|
| Assemblée générale | CDC art. 3 | Organe représenté (réunions, décisions) ; pas d'utilisateur direct. |
| Conseil d'administration / PCA | CDC art. 3–4 | Orientation, supervision ; consultation des tableaux de bord, approbateur potentiel (VAL). |
| Premier Vice-président | CDC art. 4, art. X (prio. 5) | Suivi des décisions, intérim PCA, déploiement des délégués avec le SG. |
| Bureau exécutif | CDC art. 3, 11, 19 | Campagne de mobilisation, rapports périodiques. |
| Secrétaire général | CDC art. 4, X (prio. 2) | Fichier central, convocations, ordres du jour, PV, archives, suivi administratif des décisions. |
| Trésorier général | CDC art. 4, 15 | Recettes/dépenses, comptabilité, rapports financiers, budgets, pièces justificatives. |
| Responsable communication | CDC art. 4 | Contenus publics validés, campagnes. → rôle CMS. |
| Organe de contrôle | CDC art. 3, 14–15 | Contrôle interne, lecture des journaux et états financiers. |
| Commissions techniques | CDC art. 3, X | Mobilisation, partenariats, emploi… ; suivi par commission. |
| Délégués et points focaux | CDC art. 4, 11, X (prio. 5) | Terrain, smartphone ; recensement, relais, rapports périodiques. |
| Adhérent | CDC art. 5–8, 17 ; CMD 14/11 | Objet de la gestion **et utilisateur** de l'Espace adhérent : consulte sa fiche, ses cotisations, sa carte, suit ses demandes d'assistance. |
| Ayant droit | CDC art. 6, 9 | Rattaché à un adhérent ; bénéficiaire possible d'un accompagnement. |
| Ancien marin / retraité | CDC art. 2, 5, 9 | Catégorie d'adhérent avec suivi spécifique. |
| Partenaire | CDC art. 12 | Armements, pêche industrielle, offshore, syndicats, établissements sanitaires, centres de formation, institutions, ONG. |
| Visiteur public | CMD § 6 | Consulte le site, soumet adhésion / contact / partenariat. |
| Administrateur technique | CMD § 21 | Installation, paramètres, comptes, sauvegardes. |

---

## 4. Processus métier identifiés

| # | Processus | Source | Étapes clés |
|---|---|---|---|
| P1 | Adhésion | CDC art. 5–6 ; CMD § 6.3, 9.2 | Demande (en ligne ou saisie) → vérification des pièces → validation ou rejet motivé → attribution du numéro → carte. |
| P2 | Réadhésion / recensement | CDC art. 11, X (prio. 1) | Recherche de l'ancien membre → mise à jour → réactivation tracée. |
| P3 | Carte de membre | CDC art. 7 | Génération PDF imprimable + QR de vérification minimal. Conditions de délivrance : **VAL**. |
| P4 | Appel et encaissement des cotisations | CDC art. 13, 17, 20 | Paramétrage type → appel (échéance) → paiement → reçu → impayés. Montants : **VAL**. |
| P5 | Autres ressources | CDC art. 10, 13 | Dons, legs, subventions, contributions partenaires, contributions exceptionnelles *autorisées* (référence à la décision d'autorisation). |
| P6 | Demande d'assistance sociale | CDC art. 9–10 ; CMD § 12 | Réception → instruction → avis → décision de l'organe compétent → (si aide financière) décaissement → clôture → archivage. |
| P7 | Décaissement à double validation | CDC art. 14 ; CMD § 18.2 | Initiation → approbation 1 → approbation 2 (distincte) → paiement unique → justificatif. |
| P8 | Fonds de solidarité | CDC art. 10 | Entrées affectées, sorties via P7, solde, rapports ; compte distinct. |
| P9 | Offres d'emploi / embarquement | CDC art. 9, X (prio. 6) | Saisie → vérification → publication → mises en relation → archivage à expiration. |
| P10 | Formation | CDC art. 2, 9 | Référencement centres et opportunités → demandes d'orientation. |
| P11 | Partenariat | CDC art. 12, X (prio. 3) | Prospection → convention écrite (objet, engagements, durée, suivi, fin) → activités → renouvellement/fin. |
| P12 | Gouvernance | CDC art. 3–4 | Convocation → ordre du jour → réunion → PV → décisions → suivi d'exécution. |
| P13 | Délégués / points focaux | CDC art. 4, 11, X (prio. 5) | Affectation zone → fiche de mission → rapports périodiques. |
| P14 | Manquement | CDC art. 16–18 | Signalement → information de la personne → observations → examen → décision de l'organe (mesure issue des textes) → archivage. |
| P15 | Reporting | CDC art. 15, 19–20 | Rapports mensuels/trimestriels calculés sur les données réelles. |
| P17 | Activation et usage du compte adhérent | CMD 14/11/2026 | Validation de l'adhésion → code d'activation (e-mail ou lettre remise) → création du mot de passe → consultation, demandes de correction, suivi des demandes. |
| P16 | Publication de contenus | CDC art. 4 (communication) ; CMD § 7 | Rédaction → relecture → publication (« informations officielles validées »). |

---

## 5. Contraintes techniques retenues

PHP ≥ 8.2, MySQL/MariaDB, PDO préparé, hébergement mutualisé cPanel, pas de Docker/VPS/Node/Redis/processus permanent/root en production, tâches planifiées par Cron cPanel, déploiement sans SSH obligatoire, seul `public/` exposé, fuseau Africa/Abidjan, XOF, français, 500–2 000 adhérents (CMD § 3).

---

## 6. Ambiguïtés et points à valider

Classés par impact sur le fonctionnement. **Aucun ne bloque la phase de développement du socle** ; les paramètres concernés seront livrés « non configurés ».

### 6.0 Réponses du commanditaire (13/11/2026)

| # | Décision | Conséquence dans les livrables |
|---|---|---|
| D1 | **Pas de Laravel** (« trop de dépendances ») | Socle PHP maison sans framework ni Composer en production (`03-architecture.md` B1) ; lot L0 agrandi. |
| D2 | **Les règles = le cahier des charges** (pas d'autres textes à intégrer) | Les listes de référence sont pré-remplies avec ce que le CDC énumère (art. 3, 5, 6, 9, 13, 20) ; tout ce que le CDC ne fixe pas reste un paramètre « non configuré » (§6.2). |
| D3 | Pas de réponse | Aucun rôle ne reçoit la permission d'approuver un décaissement à l'installation : le super administrateur l'attribue explicitement à au moins deux personnes. |
| D4 | Pas encore d'hébergement | Conception pour un cPanel générique ; recette locale (Apache + PHP-FPM + MariaDB) ; procédure d'installation testée dès qu'un hébergement existe. |
| Logo | Pas de réponse | Solution de repli retenue : logo complet sur ordinateur, pied de page, carte et documents ; emblème seul en en-tête mobile et dans la gestion. Une version horizontale/SVG reste souhaitable. |
| Skills | « Taste skill » à la place de « Task Skill » | Voir §1. |

### 6.1 Décisions structurantes (historique de la question posée)

| # | Question | Pourquoi c'est bloquant | Proposition neutre |
|---|---|---|---|
| D1 | Framework PHP : Laravel 12 ou micro-socle maison ? | Conditionne tout le code. | **Tranché : socle maison** (voir 6.0). |
| D2 | Les statuts et le règlement intérieur peuvent-ils être fournis ? | Ils définissent organes, pouvoirs, catégories de membres, sanctions, éligibilité. | **Tranché : on s'en tient au cahier des charges** (voir 6.0). |
| D3 | Qui sont les approbateurs des décaissements (fonctions) et faut-il un ordre imposé (ex. Trésorier puis PCA) ? | Circuit P7. | Permission `disbursement.approve` attribuable par rôle ; 2 approbateurs distincts, ordre libre, configurable. |
| D4 | Nom de domaine, hébergeur, adresse e-mail d'envoi (SMTP). | Déploiement, notifications. | — |

### 6.2 Paramètres métier (VAL) — livrés non configurés

| # | Paramètre | Source du manque |
|---|---|---|
| V1 | Montant des droits d'adhésion | CDC art. 13 ne fixe aucun montant |
| V2 | Montants et périodicité des cotisations | CDC art. 13, 17 |
| V3 | Catégories admissibles au-delà de la liste de l'art. 5 (« toute autre catégorie admise par les statuts ») | Statuts |
| V4 | Pièces obligatoires du dossier (photo « si requise », livret « lorsque pertinent ») | CDC art. 6 |
| V5 | Conditions de délivrance, renouvellement, remplacement et durée de validité de la carte | CDC art. 7 |
| V6 | Critères écrits d'éligibilité aux aides, plafonds, délais de carence | CDC art. 9–10 |
| V7 | Organe compétent pour décider de chaque type d'aide | CDC art. 10 |
| V8 | Statuts administratifs d'un membre (actif, suspendu, radié, décédé…) et motifs | CDC art. 8, 18 |
| V9 | Mesures disciplinaires disponibles | CDC art. 18 (renvoi aux statuts) |
| V10 | Règle de « membre à jour » (combien d'impayés) | CDC art. 17 |
| V11 | Format du numéro de membre (ex. `MMMGCI-2026-00001`) | Non défini — proposition technique |
| V12 | Calendrier des rapports (mensuel/trimestriel) | CDC art. 19 « selon le calendrier adopté » |
| V13 | Durées de conservation des données et pièces (membres radiés, dossiers sociaux, finances) | Non défini ; loi ivoirienne n° 2013-450 sur la protection des données à vérifier par la Mutuelle |
| V14 | Seuil éventuel au-delà duquel une 3ᵉ validation ou une validation d'organe est requise | CMD § 18.2 « paramétrable » |
| V15 | Exercice comptable et plan de catégories financières | CDC art. 15 |

### 6.3 Points de cohérence relevés

1. **Logo double.** Le commanditaire impose le logo complet (emblème MMMGCI + bandeau « Maritime Oceans and Hospitality Services »). La relation juridique entre la Mutuelle et MOHS n'est pas décrite : à préciser pour les mentions légales (éditeur du site, hébergeur, responsable de publication). Une version vectorielle ou PNG transparente du logo est nécessaire (le fichier fourni est un JPEG sur fond blanc).
2. **Pages « Historique et vision » et « Organisation »** : aucun contenu historique ni nom de responsable fourni. Pages créées vides avec gabarit ; à rédiger par la Mutuelle.
3. **Témoignages** (CMD § 7.5) : module livré désactivé ; ne publier que des témoignages réels et consentis.
4. **Galerie / partenaires publics** : aucune photo ni partenaire fourni ; états vides prévus.
5. **Données de santé** dans les demandes « maladie » : données sensibles ; accès restreint au gestionnaire social et à l'organe décisionnaire, consultation journalisée.
6. **Paiement en ligne (Mobile Money)** : non demandé ; cotisations saisies manuellement par la Trésorerie en V1. Extension possible plus tard.
7. **SMS** : non demandé ; notifications internes et e-mail seulement en V1.

---

## 7. Registre des risques

| # | Risque | Prob. | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Règles métier non adoptées → modules inutilisables au lancement | Haute | Moyen | Paramètres administrables ; modules fonctionnels sans montants (saisie manuelle justifiée). |
| R2 | Fuite de données personnelles / sociales | Moyenne | Très élevé | Stockage privé hors web, RBAC serveur, journal de consultation, exports restreints, HTTPS, sauvegardes chiffrées. |
| R3 | Fraude ou erreur sur les décaissements | Moyenne | Très élevé | Double validation imposée en base + service, invalidation sur modification, clé d'idempotence, audit en ajout seul. |
| R4 | Limites de l'hébergement mutualisé (mémoire, durée d'exécution, cron ≥ 5 min, pas de SSH) | Haute | Moyen | Exports et PDF par lots, pas de tâche longue, installateur web, migrations lançables depuis l'interface d'administration. |
| R5 | Perte de données (pas de sauvegarde testée) | Moyenne | Très élevé | Sauvegarde quotidienne cPanel + export applicatif chiffré, procédure de restauration testée. |
| R6 | Faible appropriation par des utilisateurs peu familiers de l'informatique | Moyenne | Élevé | Interface sobre, mobile, formations, guide utilisateur illustré. |
| R7 | Connexion mobile faible des délégués | Haute | Moyen | Pages légères (< 200 Ko hors images), pas de framework JS lourd, formulaires tolérants (brouillon). |
| R8 | Dépendance à un seul administrateur technique ; socle maison moins connu qu'un framework | Moyenne | Élevé | Socle volontairement petit et lisible (PHP natif, aucune magie), conventions documentées, tests automatisés couvrant le socle, documentation développeur. |
| R11 | Failles dans le socle de sécurité écrit sur mesure (CSRF, sessions, uploads) | Moyenne | Très élevé | Fonctions natives de PHP uniquement (`password_hash`, `random_bytes`, `hash_equals`, PDO préparé, `finfo`), tests dédiés à chaque protection, revue de sécurité au lot L14. |
| R9 | Usurpation de comptes à privilèges | Moyenne | Très élevé | Limitation des tentatives, mots de passe robustes, 2FA TOTP proposée pour les rôles financiers (TECH, à valider). |
| R10 | Le CDC n'est pas encore adopté et peut changer | Moyenne | Moyen | Traçabilité CDC ↔ fonctionnalités ; paramétrage plutôt que code. |
| R12 | Un adhérent voit les données d'un autre (accès par identifiant deviné) | Moyenne | Très élevé | Toute requête de l'Espace adhérent filtrée par le membre de la session, jamais par un identifiant de l'URL ; tests d'accès croisé automatiques. |
| R13 | Charge de support (mots de passe oubliés, adhérents sans e-mail) | Haute | Moyen | Connexion par numéro de membre ou téléphone ; réinitialisation par le gestionnaire ou le délégué via un nouveau code ; guide illustré d'une page. |
| R14 | Prise de contrôle d'un compte adhérent | Moyenne | Élevé | Mot de passe ≥ 10 caractères, limitation des tentatives, code d'activation à usage unique et à durée limitée, alerte au gestionnaire après verrouillage. |

---

## 8. Fonctionnalités prioritaires

Alignées sur le plan d'action prioritaire 2026–2027 (CDC chap. X) :

1. **Fichier central des membres + recensement / réadhésion** (prio. 1–2) → socle, RBAC, adhérents.
2. **Site public + adhésion en ligne** → alimente le recensement.
3. **Fonds de solidarité + double validation** (prio. 4).
4. **Partenariats** (prio. 3).
5. **Délégués et points focaux** (prio. 5).
6. **Emploi et embarquement** (prio. 6).
