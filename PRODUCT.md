# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

PHP 8.2+ / MySQL-MariaDB, hébergement mutualisé cPanel, sans Node.js ni processus permanent en production (exigence du commanditaire). Choix du framework : à valider en phase 1 (recommandation : Laravel 12, voir `docs/conception/03-architecture.md`).

## Users

- **Responsables de la Mutuelle** (Bureau exécutif, Secrétariat général, Trésorerie, Communication, organe de contrôle) : gèrent adhérents, cotisations, demandes sociales, finances et gouvernance, principalement sur ordinateur au siège.
- **Délégués et points focaux** : sur le terrain (ports, lieux de rassemblement), surtout sur smartphone avec connexion mobile parfois faible ; recensent, relaient, déposent des rapports.
- **Visiteurs publics** : marins marchands, gens de mer, anciens marins, familles, partenaires (armements, centres de formation, institutions) qui découvrent la Mutuelle, demandent leur adhésion ou consultent les offres.
- Les adhérents n'ont **pas** de compte personnel en V1 (décision du commanditaire, 2026-11-13).

## Product Purpose

Système d'information de la Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire (MMMGCI) : site institutionnel administrable, fichier central sécurisé des adhérents, suivi traçable des cotisations et du fonds de solidarité, instruction des demandes d'assistance, double validation des décaissements, gouvernance et reporting à partir de données réelles.

## Positioning

Outil de transparence d'une mutuelle de solidarité : chaque aide et chaque décaissement suit un circuit écrit, vérifiable et à double validation, conformément au cahier des charges 2026–2027 (art. 10, 14, 15).

## Operating Context

Abidjan, fuseau Africa/Abidjan, devise XOF (sans décimales en pratique), langue française. 500 à 2 000 adhérents visés. Les règles métier (montants, éligibilité, sanctions) relèvent des statuts et du règlement intérieur, non fournis à ce jour.

## Capabilities and Constraints

- Pas de régime d'assurance maladie à remboursement, ni épargne, ni crédit.
- Aucun montant, plafond, délai de carence ou sanction ne doit être inventé : paramètres administrables, non configurés par défaut.
- Données personnelles et sociales confidentielles ; fichiers privés hors de la racine web.

## Brand Commitments

- Nom : Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire (MMMGCI).
- Slogan : « Un nouveau jour se lève ».
- Logo complet obligatoire (décision du commanditaire, 2026-11-13) : emblème circulaire MMMGCI (cordage, carte d'Afrique avec la Côte d'Ivoire aux couleurs nationales, ancre, mer) **et** bandeau « Maritime Oceans and Hospitality Services — The soul sings only in the sea ». Fichier source : `docs/conception/maquettes/logo-mmmgci-mohs.jpg` (JPEG sur fond blanc ; une version vectorielle ou PNG transparente reste à fournir).

## Evidence on Hand

- Cahier des charges institutionnel 2026–2027 (`docs/conception/cahier-des-charges-2026-2027.docx`).
- Aucun chiffre, témoignage, photo d'activité, liste de partenaires ou nom de responsable n'est fourni : ne rien fabriquer.

## Product Principles

1. Les textes de la Mutuelle priment : le logiciel paramètre, il ne décide pas.
2. Tout mouvement d'argent est traçable, justifié et validé par deux personnes distinctes.
3. Les données des marins et de leurs familles sont confidentielles par défaut.
4. Utilisable sur un smartphone modeste avec une connexion faible.
5. Administrable sans toucher au code.

## Accessibility & Inclusion

Cible WCAG 2.2 AA. Utilisateurs parfois peu familiers de l'informatique : libellés explicites, formulaires courts, confirmations avant les actions sensibles.
