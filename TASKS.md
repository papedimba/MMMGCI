# Tasks

Suivi des lots de la plateforme MMMGCI. Détail de chaque tâche (description, dépendances, fichiers, critères, tests) : `docs/conception/06-plan-taches.md`.

## Active
- [ ] **Phase 1 — Validation de la conception** - commanditaire - livrables dans `docs/conception/`

## Waiting On
- [ ] **Suites de L2** : logo PNG/SVG transparent (le JPEG laisse un cadre clair sur les fonds colorés, en-tête et pied public) ; points de conduite (« dotted leaders ») et emplacement photo des fiches pour L4 ; vraies photos et animations de la page publique (L4) ; pastilles de statut d'état `validation-steps` à relier aux circuits de L6 ; tableau de bord enrichi quand les modules existeront
- [ ] **Paramètres L5 à valider par les instances** : format du numéro de membre (V11), liste des statuts (V8), durée de validité de la carte (V5), conditions de délivrance, durée de conservation (`members.retention_months`), pièces exigées pour un dossier complet
- [ ] **Reports de L5** : instruction des demandes en ligne → L4 ; Espace adhérent → L15 ; anonymisation après la durée de conservation validée ; droits de modification des délégués à décider par les instances ; suppression de pièce ou de photo et purge des fichiers orphelins ; sous-zones dans le périmètre ; impression CR80 à vérifier sur imprimante
- [ ] **Délégués et création directe** : `delegue` et `point_focal` détiennent `members.create` et peuvent créer un adhérent actif et numéroté sans validation (`/admin/adherents/nouveau`) ; les instances doivent dire si c'est voulu (sinon ne leur laisser que la saisie de demandes)
- [ ] **D3 Approbateurs des décaissements** - instances
- [ ] **C38 Dépôt de demandes d'assistance en ligne par l'adhérent : oui/non** - commanditaire
- [ ] **D4 Domaine, hébergeur cPanel, SMTP** - commanditaire (pas encore d'hébergement)
- [ ] **Logo horizontal + version SVG/PNG transparente** - commanditaire

## Someday
- [ ] **L3 CMS** (T3.1–T3.9)
- [ ] **L4 Site public + adhésion en ligne** (T4.1–T4.6) → V0.2
- [ ] **L6 Cotisations, finances, double validation, fonds** (T6.1–T6.10)
- [ ] **L7 Demandes sociales** (T7.1–T7.5) → V0.3
- [ ] **L9 Partenariats** (T9.1–T9.3)
- [ ] **L8 Emploi, formation, anciens marins** (T8.1–T8.5)
- [ ] **L10 Gouvernance, délégués** (T10.1–T10.6)
- [ ] **L11 Rapports et indicateurs** (T11.1–T11.4)
- [ ] **L12 Discipline** (T12.1–T12.3)
- [ ] **L13 Installation, exploitation, documentation** (T13.1–T13.6)
- [ ] **L15 Espace adhérent** (T15.1–T15.11) → V0.4
- [ ] **L14 Recette** (T14.1–T14.6) → V1.0

## Done
- [x] ~~L5 Adhérents (T5.1–T5.9 : schéma et référentiels, service et doublons, liste et recherche, fiche, création/modification/ayants droit/pièces, instruction des demandes saisies, changements de statut, exports, carte et vérification publique, contrôle qualité)~~ (15/11/2026) — décisions et reports : docs/superpowers/plans/2026-11-15-l5-decisions-et-reports.md
- [x] ~~L2 Design system et gabarits (7 tâches : jetons, kit ui(), gabarit d'administration, écrans restylés, gabarit public, gabarit d'impression, contrôle qualité ; noms français des modules et rôles protégés en lecture seule inclus)~~ (15/11/2026) — documentation : docs/conception/05-design-system.md §8
- [x] ~~L1 Authentification, rôles et permissions, administration des utilisateurs et des rôles, journal d'audit (9 tâches, revue d'ensemble faite, 334 tests)~~ (15/11/2026) — décisions et reports : docs/superpowers/plans/2026-11-15-l1-decisions-et-reports.md
- [x] ~~L0 Socle technique (16 tâches, revue d'ensemble faite, 213 tests)~~ (14/11/2026) — décisions et reports : docs/superpowers/plans/2026-11-14-l0-decisions-et-reports.md
- [x] ~~D1 Socle : PHP natif sans framework~~ (13/11/2026)
- [x] ~~D2 Règles : cahier des charges seul~~ (13/11/2026)
- [x] ~~Lecture et analyse du cahier des charges~~ (13/11/2026)
- [x] ~~Livrables de conception phase 1~~ (13/11/2026)
