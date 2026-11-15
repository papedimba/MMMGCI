# MMMGCI — Plateforme numérique

Plateforme institutionnelle et de gestion de la **Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire** — « Un nouveau jour se lève ».

État : **lots L0 (socle), L1 (accès), L2 (design system) et L5 (adhérents) terminés**. La page d'accueil est une page technique (« Socle MMMGCI opérationnel »), remplacée en L4.

- Conception : [`docs/conception/`](docs/conception/README.md)
- Contexte produit : [`PRODUCT.md`](PRODUCT.md)
- Suivi des tâches : [`TASKS.md`](TASKS.md)

## Prérequis

- PHP ≥ 8.2 avec les extensions `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `intl`, `dom` (pas de `bcmath`).
- MariaDB ≥ 10.2.7 ou MySQL ≥ 8.0.16 (les contraintes `CHECK` ne sont appliquées qu'à partir de ces versions ; le type `JSON` des migrations, utilisé depuis `0001`, demande MariaDB 10.2.7). En dessous, une migration échoue en laissant des tables partielles.
- Hébergement cible : mutualisé cPanel ; seul `public/` est exposé au web.

Aucune dépendance PHP tierce en production : `vendor/` (PHPUnit) ne sert qu'aux tests et n'est jamais déployé.

## Installation de développement

```sh
bin/dev-db                      # démarre MariaDB (idempotent), crée l'utilisateur mmmgci et les bases mmmgci, mmmgci_test
cp .env.example .env            # puis renseigner les valeurs ci-dessous et APP_KEY (voir plus bas)
composer install                # PHPUnit seulement (tests)
php bin/migrate.php             # applique les migrations ; une seconde fois : « Aucune migration en attente. »
```

`APP_KEY` (obligatoire, au moins 32 octets) signe le journal d'audit. La générer une seule fois :
`php -r "echo 'base64:'.base64_encode(random_bytes(32));"`. **Ne jamais la changer ensuite** : `Audit::verify()` signalerait tout l'historique existant comme altéré.

Valeurs `.env` correspondant à `bin/dev-db` :

```
APP_ENV=local
APP_DEBUG=true
DB_HOST=127.0.0.1
DB_NAME=mmmgci
DB_USER=mmmgci
DB_PASS=mmmgci
DB_TEST_NAME=mmmgci_test
```

## Lancer les tests

```sh
composer test                               # toute la suite (unit + feature)
vendor/bin/phpunit --filter SmokeTest       # un seul test
```

Les tests de base de données recréent `DB_TEST_NAME` (doit se terminer par `_test`) à chaque classe.

## Lancer le serveur

```sh
php -S 127.0.0.1:8080 -t public
```

`GET /` : page technique. `GET /sante` : `200 ok` si la base répond, `503 indisponible` sinon (sans détail).

### Si l'hébergeur impose la racine du site sur le dossier du projet

La racine web doit pointer sur `public/`. Si l'hébergeur ne le permet pas, le `.htaccess` placé à la racine du projet sert de repli (Apache 2.4) :
il refuse les fichiers cachés (`.env`, `.git`…) et les dossiers `app/`, `bin/`, `config/`, `database/`, `storage/`, `tests/`, `vendor/`, `docs/`, `routes/`,
puis réécrit toutes les autres adresses vers `public/` (nécessite `mod_rewrite`). Après déploiement, vérifier que `https://votre-domaine/.env`
et `https://votre-domaine/storage/` répondent bien `403`.

## Structure des dossiers

```
app/            code applicatif (jamais exposé)
  Core/         socle : Config, Database, Migrator, View, Session, Csrf, Validator, Money, Audit, Mailer…
  Core/Http/    Request, Response, Router, middleware (ForceHttps, SecurityHeaders, StartSession, VerifyCsrf)
  views/        gabarits PHP (layouts/, components/, admin/, auth/, errors/)
  init.php      amorçage commun (fuseau, configuration, gestionnaire d'erreurs) des pages web et des scripts bin/
  bootstrap.php retourne l'App (init.php puis kernel.php qui l'assemble)
bin/            scripts CLI : dev-db, migrate.php, cron.php, create-admin.php, check-contrast.php
database/migrations/  fichiers NNNN_*.sql (ne jamais modifier une migration appliquée : en ajouter une nouvelle)
public/         racine web : index.php, .htaccess, media/
routes/web.php  déclaration des routes
storage/        logs/ (app-AAAA-MM-JJ.log), uploads/, cache/ — interdit au web
tests/          Unit/, Feature/, Support/
```

## Interface

Le design system (jetons, kit `ui()`, gabarits d'administration, public et impression) est décrit dans `docs/conception/05-design-system.md` §8.

- Galerie des composants (environnement non productif, connecté avec `users.manage`) : `/admin/composants` ; page d'exemple d'impression : `/admin/composants/document`.
- Aucun style ni script en ligne (CSP) : les règles vont dans `public/assets/css/`, les couleurs passent par `tokens.css`.
- Ajouter un lien au menu : une ligne dans `app/views/admin/_nav.php`.
- Contrôle des contrastes WCAG des jetons : `php bin/check-contrast.php` (code de sortie 1 sous le seuil ; également exécuté par la suite de tests).

## Cron

Une seule entrée cPanel, toutes les 5 minutes :

```
*/5 * * * * php /home/USER/mmmgci/bin/cron.php
```

Tâches enregistrées (lot L1) : `login_attempts.purge` et `password_resets.purge`, chacune toutes les 60 minutes (suppression des tentatives de connexion et des jetons de réinitialisation ou d'invitation de plus de 24 h).

## Premier administrateur

Après `php bin/migrate.php`, créer le premier compte (rôles `super_admin` et `admin_general`) :

```sh
php bin/create-admin.php admin@exemple.ci "Prénom Nom"
```

Le mot de passe (20 caractères) s'affiche **une seule fois** : le noter, puis se connecter sur `/admin` et le changer aussitôt à
`/admin/mon-compte/mot-de-passe`. La commande refuse de s'exécuter s'il existe déjà un utilisateur actif qui peut gérer les utilisateurs ;
les autres comptes se créent ensuite depuis l'écran Utilisateurs.

Sans Terminal cPanel, utiliser une tâche Cron **ponctuelle** (à régler quelques minutes plus tard) :

```
php /home/USER/mmmgci/bin/create-admin.php admin@exemple.ci "Prénom Nom"
```

Le mot de passe est alors envoyé dans l'e-mail du Cron : **supprimer la tâche dès qu'elle a tourné** et changer le mot de passe immédiatement.

## Adhérents (lot L5)

Écrans sous `/admin/adherents` (menu « Adhérents ») :

| Écran | Adresse | Permission |
|---|---|---|
| Liste, recherche (nom, numéro, téléphone), filtres, tri | `/admin/adherents` | `members.view` |
| Fiche (onglets identité, profession, ayants droit, pièces, cotisations, demandes, historique) | `/admin/adherents/{id}` | `members.view` |
| Création (avec contrôle des homonymes) et modification | `/admin/adherents/nouveau`, `/{id}/modifier` | `members.create`, `members.update` |
| Demandes d'adhésion saisies (réception, prise en charge, compléments, validation, rejet) | `/admin/adherents/demandes` | `members.view` ; saisie : `members.create` ; instruction : `members.validate` |
| Changement de statut (motif et référence de décision obligatoires, historisé) | `/{id}/statut` | `members.status` |
| Export CSV / XLSX et liste imprimable (mêmes filtres et même périmètre que la liste) | `/admin/adherents/export.csv`, `.xlsx`, `/imprimer` | `members.export` |
| Carte de membre (délivrance, révocation, impression sur A4 au format CR80) | `/{id}/carte` | `members.update` |
| Référentiels (catégories, professions, spécialités, statuts) | `/admin/adherents/referentiels` | `settings.manage` |
| Pièces d'identité et livret (consultation journalisée) | `/{id}/pieces/{fid}` | `members.documents.view` |
| Vérification publique d'une carte (QR) | `/verifier/{jeton}` | aucune (page neutre : nom, numéro, statut, validité) |

**Permissions** : `members.view|create|update|validate|export|documents.view`, plus `members.status` (changer le statut ; attribuée avec `members.validate`) et `members.scope.all` (voir les adhérents de toutes les zones). Détail dans les migrations `0005` et `0008`.

**Périmètre des délégués** : sans `members.scope.all`, un compte ne voit que les adhérents de sa **zone de rattachement** (`users.zone_id`, à régler dans l'écran Utilisateurs). Les sous-zones ne sont pas incluses. Sans zone et sans `members.scope.all`, il ne voit personne. Un adhérent, une demande, une pièce ou une carte hors périmètre répond **404** (jamais 403). Le délégué (et le point focal) détient `members.create` : il saisit des demandes (zone imposée par le serveur), ne les valide pas (`members.validate`), **mais peut aussi créer directement un adhérent actif et numéroté** via `/admin/adherents/nouveau`, sans circuit de validation. À faire trancher par les instances (rôles inchangés).

**Paramètres « non configuré »** — aucune valeur n'est inventée. Le bandeau « en attente de validation par les instances » apparaît sur l'onglet Statuts des Référentiels, sur les formulaires de création (format du numéro) et sur la page carte (conditions) ; validité de la carte et conservation n'ont pas d'écran :

| Paramètre | Effet tant qu'il n'est pas validé |
|---|---|
| `members.number_format` | format technique par défaut `MMMGCI-AAAA-NNNNN` (changer plus tard impose une renumérotation) |
| `members.card_validity_months` | carte sans date d'expiration (« Validité non définie ») |
| `members.card_conditions` | délivrance manuelle, sans case de conditions ; une valeur validée mais vide compte comme non configurée |
| `members.retention_months` | aucune purge ni anonymisation (non implémentées) |
| Statuts `actif`, `suspendu`, `radie`, `decede`, `ancien_marin` | liste **proposée** ; aucune règle de transition ni conséquence automatique |

**Production** : renseigner `APP_URL` (https://votre-domaine) dans `.env` ; le QR de la carte en est tiré. En production sans `APP_URL`, l'impression de carte est refusée avec un message (jamais construit depuis l'en-tête Host ; ce repli ne sert qu'en développement).

**Limites connues** : la vérification publique `/verifier` est limitée à 30 requêtes par minute et par `REMOTE_ADDR` (limite partagée derrière un proxy ou un CDN) ; les exports et la liste imprimable sont construits en mémoire (ordre de grandeur validé : 2 000 lignes en moins de 0,1 s ; au-delà de ~100 000 lignes, prévoir de relever `memory_limit` ou de filtrer) ; la carte s'imprime sur une page A4 (le format physique CR80 sur imprimante de cartes, page nommée `cr80`, n'est pas utilisé ni vérifié) ; pas d'anonymisation après conservation, pas de suppression de pièce ni de photo, pas d'Espace adhérent ni d'adhésion en ligne (lots L15 et L4).

## Finances (lot L6)

Cotisations, grand livre, circuit de décaissement à double validation, fonds de solidarité et contrôle. Les écrans respectent partout la même règle : **aucune règle métier dans les vues**, les services décident (transaction + verrous `FOR UPDATE`) et chaque transition est auditée.

| Écran | Adresse | Permission |
|---|---|---|
| Types de cotisation, appels (liste, individuel, en lot, annulation) | `/admin/cotisations/types`, `/appels`, `/appels/individuel`, `/appels/lot` | lecture `contributions.view` ; écriture `contributions.manage` |
| Encaissement, fiche de paiement, reçu imprimable numéroté, annulation par contre-passation | `/admin/cotisations/appels/{id}/encaisser`, `/paiements/{id}`, `/recu`, `/annuler` | `contributions.view` (lecture), `contributions.manage` (écriture) |
| Impayés et situation des cotisations, export CSV / XLSX | `/admin/cotisations/impayes` | `contributions.view` ; export `contributions.export` |
| Comptes, catégories, écritures d'un compte, ressources hors cotisation, contre-passation d'une écriture manuelle | `/admin/finances`, `/comptes/{id}`, `/ressources`, `/ecritures/{id}/contre-passation` | lecture `finance.view` ; écriture `finance.manage` |
| Décaissements : liste, demande, modification, soumission, approbation, rejet, annulation, paiement, pièces | `/admin/decaissements` | lecture : `finance.view`, `finance.control`, `disbursement.initiate` (ses demandes), `approve` ou `pay` ; `disbursement.initiate`, `disbursement.approve`, `disbursement.pay` pour agir |
| Fonds de solidarité (lecture du grand livre, export) | `/admin/solidarite` | `solidarity.view` ; création du compte : `solidarity.manage` ou `finance.manage` ; export : `solidarity.view` ET `reports.export` |
| Contrôle (écritures, décaissements, contre-passations, annulations, soldes et contrôles d'intégrité, exports) | `/admin/controle` | `finance.control` (lecture seule : aucune route d'écriture) |

**Permissions** : `finance.view|manage|control`, `contributions.view|manage|export`, `disbursement.initiate|approve|pay`, `solidarity.view|manage`, et `reports.export` pour l'export du fonds. `finance.manage` est créée par la migration `0011`.

**RAPPEL D'INSTALLATION — à faire AVANT toute utilisation du module de décaissement** : aucune permission d'approuver ni de payer n'est attribuée par défaut. Attribuer `disbursement.approve` à **au moins deux personnes distinctes** (l'initiateur n'approuve jamais sa propre demande) et `disbursement.pay` à **un payeur** (le payeur doit seulement être distinct de l'initiateur par défaut ; un approbateur peut aussi payer). Tant que moins de deux approbateurs actifs distincts existent, le module **refuse de fonctionner** : la soumission d'un brouillon reste permise, mais l'approbation, le rejet, la modification et le paiement sont bloqués (page « Circuit de décaissement inopérant »). C'est volontaire (décision de conception A6) ; les rôles de test sont créés à la main dans les tests, jamais dans le seed. **Confiance** : un super administrateur peut créer des utilisateurs et leur attribuer `approve` et `pay` (action auditée) ; le circuit suppose des administrateurs de confiance, relire régulièrement la liste des utilisateurs et de leurs rôles.

**Règles du circuit** : deux approbations distinctes au moins par révision (le seuil dépend du montant via `approval_rules`, jamais moins de 2) ; rejet définitif ; toute modification substantielle (montant, bénéficiaire, compte, objet) crée une nouvelle révision et rend les approbations caduques (l'historique est conservé) ; un seul paiement par demande, avec justificatif, qui inscrit la dépense au grand livre ; solde insuffisant : paiement bloqué par défaut ; tout **décaissement** passe par le circuit (pas de saisie directe de dépense, pas de contre-passation d'une écriture issue d'un circuit depuis l'écran du grand livre). **Attention** : l'annulation d'un paiement de cotisation (`contributions.manage`) et la contre-passation d'une ressource (`finance.manage`) baissent le solde d'un compte par l'action d'une seule personne, sans second contrôle ; elles sont auditées et visibles à l'écran de contrôle (« double contrôle des annulations et contre-passations » : à décider par les instances). Les écritures, paiements et reçus sont immuables : on corrige par contre-passation. **Un décaissement payé n'a aucun chemin de correction dans ce lot** (une correction serait un nouveau décaissement ou une contre-passation, à décider par les instances).

**Paramètres « non configuré »** — aucune valeur n'est inventée ; ils attendent la validation des instances. Valeur technique appliquée en attendant :

| Paramètre | Effet tant qu'il n'est pas validé |
|---|---|
| `finance.insufficient_balance_policy` | **bloquer** : un paiement qui rendrait le solde négatif est refusé |
| `finance.payer_may_be_initiator` | **non** : le payeur ne peut pas être l'initiateur (un trésorier unique qui initie ne peut pas payer) |
| `contributions.up_to_date_rule` | le mot « à jour » n'est jamais affiché comme situation d'un adhérent (seul le bandeau « règle en attente de validation » le cite) ; les impayés sont comptés sans qualification |
| `contributions.auto_generate` | **désactivé** : aucun appel automatique, seulement des appels manuels (individuels ou en lot) ; valeur attendue `true` une fois validée |
| `finance.fiscal_year_start` | aucun exercice défini |
| `approval_rules` (tranches de montants) | une seule tranche : 2 approbations à partir de 0 |
| Types de cotisation : montant, périodicité, référence de l'autorisation, compte crédité | un type sans montant validé ne génère aucun appel ; une cotisation exceptionnelle exige la référence de son autorisation |
| Références `TX-`, `PAY-`, `REC-`, `DEC-` | formats techniques « à valider » (comme le numéro de membre) |
| Échéance d'un appel | fin de la période |
| Périodicités | vocabulaire fixe : mensuelle, trimestrielle, semestrielle, annuelle |
| Forme de la règle « à jour » | un nombre entier : maximum d'appels échus ; le code devra changer si les instances choisissent une autre forme |
| Population d'un appel en lot | adhérents au statut `actif` (bandeau « à valider ») |

**Bases de données** : les contraintes `CHECK` (montants > 0, sens des écritures…) exigent MariaDB ≥ 10.2.7 ou MySQL ≥ 8.0.16 ; le verrou nommé `GET_LOCK` sert à garantir un seul fonds de solidarité actif (nom préfixé par la base) ; la migration `0015` (essayée seulement sur MariaDB 10.11 ; **MySQL 8.0.16 n'a PAS été essayé**) ajoute un filet : colonne générée `solidarity_active` + index `UNIQUE` (si la base contient déjà deux fonds actifs, la migration échoue : désactiver les fonds en trop, puis relancer `php bin/migrate.php`).

**Limites connues** : exports construits en mémoire, sans plafond de lignes (mesure : 50 000 écritures, CSV 7 s et 46 Mo, XLSX 9 s et 116 Mo : avec un `memory_limit` de 128 Mo, l'export XLSX peut échouer vers 55 000 lignes ; relever `memory_limit` ou filtrer) ; aucun paiement sans appel (pas d'avances : non défini par les instances) ; l'annulation d'un paiement ou la contre-passation d'une ressource peut rendre un solde négatif (aucun contrôle de solde à la contre-passation) ; l'approbation d'un utilisateur ensuite désactivé compte toujours (à trancher) ; pas de rejet possible depuis l'état « approuvée deux fois » (`approuve_2`) (à trancher) ; le contrôle « moins d'approbateurs que requis » applique la règle d'approbation **actuelle**, pas celle en vigueur au paiement ; budgets et engagements (T6.9) reportés ; chaîne du journal d'audit de la base de développement rompue (lignes 78, 81, 83 supprimées à la main : ignorer, ne pas réparer) ; plusieurs tests de concurrence reposent sur des verrous tenus par une seconde connexion ou des processus enfants et peuvent, très rarement, être instables (relancer ; le témoin du test d'interblocage se signale « skipped » plutôt que vert par défaut).

## Rôles et permissions

Matrice complète : [`docs/conception/03-architecture.md`](docs/conception/03-architecture.md#a6-matrice-rôles--permissions-base-de-conception-configurable), section A6. Un compte peut cumuler plusieurs rôles (ses permissions s'additionnent).

| Rôle | Usage |
|---|---|
| `super_admin` | Super administrateur technique : tout, sauf les décisions et les données sensibles |
| `admin_general` | Administrateur général : utilisateurs, rôles, paramètres, contenu et gestion courante |
| `pca` | Président du Conseil d'administration : consultation, décisions sociales et disciplinaires, validation des paramètres |
| `vice_president` | Premier Vice-président : consultation, gouvernance, délégués |
| `secretaire_general` | Secrétaire général : adhérents, gouvernance, délégués, discipline, initie les décaissements |
| `tresorier_general` | Trésorier général : cotisations, solidarité, initie les décaissements |
| `resp_communication` | Responsable de la communication : contenu du site, emploi, partenaires |
| `resp_commission` | Responsable de commission : consultation adhérents, gouvernance, rapports |
| `delegue` | Délégué : consulte et crée des adhérents, rapports de délégué |
| `point_focal` | Point focal : mêmes droits que le délégué |
| `controleur` | Contrôleur : consultation étendue, contrôle financier, journal d'audit |
| `gest_adherents` | Gestionnaire des adhérents : fiches et documents des adhérents |
| `gest_social` | Gestionnaire des demandes sociales : instruction des demandes, données médicales |
| `gest_cms` | Gestionnaire du CMS : pages, publication, médias, formulaires |
| `lecture_seule` | Utilisateur en lecture seule : consultation des modules |
| `admin_site` | Administrateur du site : tout le contenu du site |
| `editeur` | Éditeur : pages, publication, médias |
| `redacteur` | Rédacteur : pages |
| `gest_medias` | Gestionnaire des médias : médiathèque |

**Aucun rôle n'approuve ni ne paie un décaissement** tant que les instances ne l'ont pas désigné : les permissions `disbursement.approve` et `disbursement.pay` ne sont attribuées à aucun rôle à l'installation.

**Rôles protégés** : seul un super administrateur peut désigner les approbateurs et les décideurs, attribuer les rôles les plus élevés ou modifier les comptes qui les détiennent
(ou un administrateur qui détient lui-même toutes les permissions du rôle concerné). La ligne de commande `bin/create-admin.php` en est exemptée.

## Sécurité des accès

- Connexion : 5 échecs par compte et par IP sur 15 minutes, 20 échecs par IP sur 15 minutes, puis blocage temporaire.
- Mots de passe : 12 caractères au minimum, mots de passe courants et dérivés du nom ou de l'e-mail refusés ; hachage Argon2id.
- Liens de réinitialisation valables 1 h, invitations valables 72 h, à usage unique.
- L'envoi différé des e-mails (après la réponse) demande PHP-FPM ou LiteSpeed ; sous mod_php il s'exécute avant la réponse.
- Journal d'audit : écran `/admin/journal` (permission `audit.view`), avec un bouton de vérification de l'intégrité de la chaîne ; consultations de données sensibles dans l'onglet « sensibles ».

## E-mail

`MAIL_DRIVER=mail` utilise `mail()` ; `MAIL_DRIVER=smtp` utilise `SMTP_HOST/PORT/USER/PASS`.
Avec `SMTP_USER` renseigné, STARTTLS est obligatoire (port 587) : l'envoi est refusé si le serveur ne l'annonce pas. Le port 465 (TLS implicite) n'est pas pris en charge.

Le transport SMTP est testé automatiquement contre un faux serveur local (`tests/Support/fake_smtp_server.php`) ; STARTTLS n'est vérifié que manuellement :
utiliser par ex. `python3 -m aiosmtpd -n -l 127.0.0.1:1025` (ou Mailpit), régler `SMTP_HOST=127.0.0.1 SMTP_PORT=1025`, puis `Mailer::fromConfig($config)->send('moi@exemple.ci','Test','Bonjour')`.

## HTTPS

En `APP_ENV=production`, `ForceHttps` redirige HTTP vers HTTPS. `Request::isSecure()` lit uniquement `$_SERVER['HTTPS']` :
sur un hébergement qui termine TLS en amont (proxy, CDN) sans renseigner `HTTPS`, la redirection bouclerait.
`X-Forwarded-Proto` n'est volontairement pas pris en compte (en-tête falsifiable) ; vérifier le comportement de l'hébergeur avant la mise en production.

## Notes de sécurité

- Les demandes de « mot de passe oublié » comptent dans la limite de 20 échecs par IP sur 15 minutes partagée avec la connexion : 20 demandes depuis une même IP y bloquent aussi la connexion pendant 15 minutes (choix assumé).
- L'audit des connexions bloquées est borné : une seule ligne `auth.login_blocked` par IP sur 15 minutes quand la limite par IP bloque, une par couple (e-mail, IP) quand la limite par compte bloque ; les tentatives bloquées suivantes de la même fenêtre ne sont ni journalisées ni comptées comme des échecs.
- Le travail de « mot de passe oublié » (jeton, audit, e-mail) s'exécute après la réponse sous PHP-FPM ou LiteSpeed ; sous mod_php ou `php -S`, il s'exécute avant que le client soit libéré.

## Journal d'audit — limites

La chaîne d'audit est vérifiable (`Audit::verify()`) mais n'a pas d'ancrage externe : un attaquant ayant un accès complet à la base peut la réécrire entièrement.
Dans une transaction longue, appeler `log()` en dernier : il verrouille la tête de chaîne jusqu'au commit.
