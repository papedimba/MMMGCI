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
- `max_input_vars` ≥ 1000 (valeur par défaut de PHP et de cPanel). Le formulaire d'une page du site envoie au plus 800 champs de blocs plus une vingtaine d'autres (plafond `Blocks::MAX_FIELDS`) ; au-delà de `max_input_vars`, PHP tronquerait l'envoi sans erreur : un champ sentinelle final le détecte et l'enregistrement est refusé. Ne pas abaisser cette valeur.
- `upload_max_filesize` ≥ 5M (taille par fichier, `UPLOAD_MAX_MB=5`) et `post_max_size` ≥ 16M (6 pièces et 15 Mo au plus par demande d'adhésion en ligne, plus les champs) ; réglables dans cPanel (« MultiPHP INI Editor »). Le défaut cPanel de `post_max_size` (souvent 8M) est trop bas. En cas de dépassement : un fichier au-delà de `upload_max_filesize` est refusé avec le message « fichier trop volumineux » (rien n'est enregistré) ; un envoi au-delà de `post_max_size` arrive **vide** à PHP (jeton CSRF compris) : le visiteur voit la page publique « Session expirée » avec un lien vers le début de son formulaire ; la pièce trop lourde n'est pas enregistrée, mais les étapes d'adhésion déjà remplies restent dans la session tant qu'elle n'a pas expiré (30 min d'inactivité : `SESSION_IDLE_MINUTES`).

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

Sous nginx, les `.htaccess` (dont `public/media/.htaccess`) sont ignorés : le serveur doit interdire l'exécution de PHP sous `/media`.

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

Publication programmée du site (lot L3) : la tâche `cms.publish_scheduled` est prévue **toutes les minutes** ; avec l'entrée ci-dessus, une publication programmée peut donc avoir jusqu'à 5 minutes de retard. Pour la minute exacte, utiliser `* * * * * php /home/USER/mmmgci/bin/cron.php`.

Tâches enregistrées (lot L1) : `login_attempts.purge` et `password_resets.purge`, chacune toutes les 60 minutes (suppression des tentatives de connexion et des jetons de réinitialisation ou d'invitation de plus de 24 h).

Adhésion en ligne (lot L4) : `site.cleanup_apply_drafts`, toutes les 15 minutes, supprime les pièces téléversées dans une demande en ligne **abandonnée** depuis plus de 6 h (jamais rattachées à une demande) : ligne `apply_draft_files`, puis fichier en base, puis fichier sur le disque privé.

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

**Limites connues** : la vérification publique `/verifier` est limitée à 30 requêtes par minute et par `REMOTE_ADDR` (limite partagée derrière un proxy ou un CDN) ; les exports et la liste imprimable sont construits en mémoire (ordre de grandeur validé : 2 000 lignes en moins de 0,1 s ; au-delà de ~100 000 lignes, prévoir de relever `memory_limit` ou de filtrer) ; la carte s'imprime sur une page A4 (le format physique CR80 sur imprimante de cartes, page nommée `cr80`, n'est pas utilisé ni vérifié) ; pas d'anonymisation après conservation, pas de suppression de pièce ni de photo, pas d'Espace adhérent (lot L15) ; l'adhésion en ligne (lot L4) est décrite sous « Site web ».

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

## Demandes sociales (lot L7)

Enregistrement, instruction, décision et suivi des demandes d'assistance des adhérents (art. 9 et 10 du cahier des charges). Comme en L5 et L6, **aucune règle métier dans les vues** : `SocialRequestService` décide (transaction, verrou `FOR UPDATE` de la demande avant la lecture de son état, contrôle contre la table de transitions, événement écrit, audit en dernier).

**Ce que le module fait, et ce qu'il ne fait jamais**
- Il **ne paie jamais directement**. Une décision « aide financière accordée » permet seulement de préparer un décaissement **au brouillon** (`DisbursementService::create`), qui suit ensuite le circuit L6 inchangé. Aucune route ni méthode du module n'écrit dans `financial_transactions`, `disbursement_approvals` ou `disbursement_payments`.
- Il **ne crée aucun droit automatique à une aide** : le montant est saisi par le décideur ; il n'est borné par le plafond du type que si ce plafond est **validé** ; sinon il est libre mais le motif reste obligatoire. Aucun montant n'est pré-rempli, sauf la recopie du montant **décidé** dans le décaissement.
- **L'adhérent ne verra jamais** les notes internes, les avis d'instruction, les noms des instructeurs et des décideurs, ni le circuit de décaissement (contrat `SocialMemberView`, voir `docs/superpowers/plans/2026-11-22-l7-decisions-et-reports.md`). L'Espace adhérent lui-même est le lot L15 : **aucun écran adhérent n'existe dans ce lot**.
- Il n'invente ni critère d'éligibilité, ni plafond, ni carence, ni délai, ni organe : ce sont des paramètres « non configuré » (voir plus bas). Le contexte d'examen (statut du membre, appels impayés, ancienneté, carence et plafond « non configuré » s'ils ne sont pas validés) est **affiché**, jamais bloquant.

**Écrans** (menu « Demandes sociales »)

| Écran | Adresse | Permission |
|---|---|---|
| Liste, recherche, filtres, export CSV / XLSX | `/admin/demandes-sociales`, `/export.csv`, `/export.xlsx` | `social.view` ; export `social.export` |
| Nouvelle demande (recherche de l'adhérent, canal, priorité, description, données de santé, une pièce) | `/admin/demandes-sociales/nouvelle` | `social.create` |
| Demande (onglets Suivi, Examen, Pièces, Données de santé), affectation, pièces demandées, avis, soumission, notes | `/admin/demandes-sociales/{id}` | `social.view` ; actions `social.manage` ; notes `social.manage` ou `social.decide` ; pièces `social.manage` ou `social.create` |
| Décision (motif, message à l'adhérent, organe et référence de décision obligatoires) | `/{id}/decision` | `social.decide` |
| Préparation du décaissement, échec du paiement, clôture | `/{id}/decaissement`, `/{id}/decaissement/echec`, `/{id}/cloturer` | `social.manage` (la préparation exige aussi `disbursement.initiate`) |
| Affichage des données médicales | `/{id}/medical/afficher` | `social.medical.view` |
| Types de demande (paramètres par type) et réglages généraux | `/admin/demandes-sociales/types`, `/parametres` | lecture `social.view` ; validation `settings.validate` |
| Onglet « Demandes d'assistance » de la fiche adhérent | `/admin/adherents/{id}?onglet=demandes` | `social.view` ; bouton « Nouvelle demande » : `social.create` |

**Circuit et statuts** (table `SocialWorkflow::TRANSITIONS`, rien d'autre n'est permis, un POST forgé est refusé)

| Statut interne | Ce que verrait l'adhérent (liste blanche, pour L15) | Suite possible |
|---|---|---|
| `recue` | Reçue | affectation |
| `en_instruction` | En cours d'examen | pièces demandées ; soumission à décision ; avis ; réaffectation |
| `en_attente_pieces` | Pièces demandées | reprise de l'instruction |
| `soumise_decision` | En cours d'examen | décision (accordée ou refusée), **seulement s'il existe un avis d'instruction** |
| `accordee` | Décision rendue | préparation du décaissement (aide financière) ; clôture (aide non financière) |
| `refusee` | Décision rendue | clôture |
| `en_paiement` | Décision rendue | clôture seulement si le décaissement lié est au statut `paye` ; échec du paiement : retour à `accordee` |
| `cloturee` | Clôturée | aucune action |

Une décision exige motif, message à l'adhérent, organe et référence de décision ; elle est **immuable** (aucune route de modification ni de suppression) ; il n'y en a qu'une par demande (contrainte UNIQUE).

**Permissions et rôles par défaut**
- Existantes depuis la migration `0005` : `social.view`, `social.manage`, `social.decide`, `social.medical.view`. Nouvelles (migration `0026`) : `social.create` (`gest_social`, `delegue`, `point_focal`) et `social.export` (`gest_social`).
- `gest_social` : voit, enregistre, instruit, prépare le décaissement, lit les données médicales, exporte. `delegue` et `point_focal` : enregistrent des demandes (`social.create`) et les voient (`social.view`) **dans leur zone seulement**. `pca` détient `social.decide` depuis `0005` **à titre de suggestion** : les instances doivent confirmer qui décide.
- **Rôles protégés** : `social.decide` et `social.medical.view` figurent parmi les permissions qui rendent un rôle protégé (`ProtectedRoles`) : seul un super administrateur (ou un administrateur qui les détient déjà) peut les attribuer. `super_admin` ne les a pas à l'installation.
- **Personne ne reçoit `disbursement.approve` ni `disbursement.pay`** (le test `RbacSeedTest::test_no_role_can_approve_or_pay_disbursements_at_install` reste vert).
- **Périmètre** : une demande appartient à la zone **actuelle** de l'adhérent. Un compte sans `members.scope.all` ne voit, ne liste, n'exporte et ne saisit que sa zone ; hors périmètre, adhérent supprimé logiquement, compte désactivé ou compte sans zone : **404** (pas de fuite d'existence, comme en L5).

**Données médicales et journal des accès sensibles**
- Les données de santé ne sont **jamais** dans la description, le journal d'audit, un e-mail, un export, l'objet d'un décaissement ni un journal applicatif. Elles vivent dans `social_request_medical` et dans les pièces marquées médicales.
- Lecture réservée à `social.medical.view`. **Chaque** consultation (affichage de l'onglet par clic explicite, téléchargement d'une pièce médicale, affichage d'un libellé de pièce médicale) écrit une ligne dans `sensitive_access_logs` (groupe `donnees_medicales`) avant de rendre la donnée. Sans cette permission, une pièce médicale s'affiche « Pièce médicale (accès restreint) », sans libellé ni nom de fichier.
- L'audit ne porte que des identifiants, statuts, références, drapeaux et montants, jamais un texte libre (description, motif, message, note, avis, libellés). **Point d'attention** : la référence de décision (`decision_ref`) est saisie librement, figure dans l'audit et dans l'objet du décaissement ; l'écran demande de n'y mettre aucune donnée de santé ni nom de personne, mais rien ne l'empêche techniquement.
- Les accès sont visibles dans le journal des accès sensibles. Les liens d'export recopient le texte cherché dans l'URL (journaux du serveur web).

**Lien avec le circuit de décaissement (L6) et le fonds de solidarité**
- La préparation crée un décaissement **brouillon** : montant = montant décidé, bénéficiaire = l'adhérent (nom corrigeable pour un ayant droit), objet « Aide sociale AS-… — décision {référence} », compte choisi par l'agent (le fonds de solidarité actif est proposé en premier, **sans présélection**). Le montant et l'adhérent sont figés par la décision (refus de `revise`). Un seul décaissement « vivant » (ni rejeté ni annulé) par décision, garanti par un contrôle sous verrou **et** par un index unique en base (migration `0027`).
- Puis tout suit le circuit L6 : soumission, **deux approbateurs distincts de l'initiateur**, payeur distinct de l'initiateur (par défaut), paiement unique avec justificatif.
- **D3 à régler avant toute utilisation** : les instances doivent désigner **au moins deux approbateurs** (`disbursement.approve`) et **un payeur** (`disbursement.pay`). Sans eux le circuit L6 est inopérant : une aide financière accordée ne pourra pas être payée (voir « Finances (lot L6) »).
- Un justificatif ne peut pas être une pièce sociale. La page du décaissement et le fonds de solidarité affichent la référence `AS-…` seulement (ni nom, ni type, ni motif).

**Paramètres « non configuré »** — aucune valeur n'est inventée ; les instances les valident par `settings.validate`, **avec une référence de décision** ; tant qu'ils ne le sont pas, le logiciel n'en tire aucune conséquence automatique (bandeau « paramètre en attente de validation par les instances »).

| Paramètre | Où | Effet tant qu'il n'est pas validé |
|---|---|---|
| `criteria_text`, `ceiling_amount`, `waiting_period_days`, `deciding_body`, `allowed_aid_kinds` | par type de demande (8 types de l'art. 9 semés, paramètres NULL), un seul jeu validé avec une référence de décision par type | affichés « Non configuré » ; aucun plafond, aucune carence appliqués ; toutes les sortes d'aide permises ; organe obligatoire à la saisie de la décision |
| `social.reference_format` | `/admin/demandes-sociales/parametres` | bandeau seulement : la référence reste le format technique `AS-AAAA-NNNN` (**le réglage validé ne change pas encore la numérotation**) ; format à valider (V11) |
| `social.processing_deadline_days` | idem | aucun délai de traitement, aucune alerte |
| `social.retention_months` | idem | aucune purge ni anonymisation (non implémentées) |
| `social.eligibility_rule` | idem | aucun contrôle d'éligibilité ; contexte affiché, jamais bloquant |
| Canaux (`guichet`, `delegue`, `telephone`, `courrier`, `email`), priorités (`normale`, `urgente`), avis (`favorable`, `defavorable`, `reserve`), sortes d'aide (`financiere`, `orientation`, `accompagnement`, `materielle`) | contraintes `CHECK` de la migration `0025` | listes **techniques proposées** : toute évolution (par exemple un canal « espace adhérent » en L15) exige une migration |

**Migrations** : `0025_social.sql` (8 tables et clé étrangère `disbursement_requests.social_decision_id`), `0026_social_permissions.sql` (permissions, 4 réglages, 8 types) et `0027_disbursement_live_social_decision.sql` (colonne générée `live_social_decision_id` + index unique `uq_dr_live_social_decision`, filet « un seul décaissement vivant par décision » ; ajoutée **en plus** de 0025 et 0026 parce que le `Migrator` refuse de modifier une migration déjà appliquée). Même modèle que la `0015` de L6. **Si la base contient déjà deux décaissements vivants pour la même décision** (très improbable : aucune route ne le permet), la `0027` **échoue** : sur MariaDB 10.11 l'erreur observée est **1834** (« Cannot delete rows from table which is parent in a foreign key constraint 'fk_da_request' »), parfois « Duplicate entry … for key 'uq_dr_live_social_decision' » ; l'échec est propre, rien n'est modifié. Diagnostic : `SELECT social_decision_id, COUNT(*) FROM disbursement_requests WHERE status NOT IN ('rejete','annule') AND social_decision_id IS NOT NULL GROUP BY 1 HAVING COUNT(*) > 1`, puis rejeter ou annuler le décaissement en trop et relancer `php bin/migrate.php`. (L'en-tête du fichier `0027` ne cite que « Duplicate entry » : migration appliquée, elle n'a pas été modifiée.) Essayée seulement sur MariaDB 10.11 ; MySQL 8.0.16 n'a pas été essayé.

**Pièces et sauvegarde** : les pièces d'une demande sociale (PDF, JPG ou PNG, type réel vérifié, images ré-encodées) sont sur le **disque privé** `storage/uploads/private/`, hors de la racine web, jamais servies publiquement, téléchargées seulement par la route contrôlée (périmètre, permission, journal pour les pièces médicales). Elles ne sont **pas** dans la base : la sauvegarde doit copier la base **et** ce dossier, et la restauration doit remettre les deux en même temps. Les pièces d'une demande ne sont jamais purgées par une tâche existante : la future purge des fichiers orphelins devra **exclure** les fichiers référencés par `social_request_documents` (et ceux de `apply_draft_files`).

**Limites connues** : pas d'Espace adhérent ni de dépôt de demande en ligne (lot L15, décision C38) ; pas de notification de décision à l'adhérent ; aucune purge après la durée de conservation ; pas de lien avec l'organe de gouvernance ni la réunion (lot L10) ; pas d'indicateurs « actions sociales » (lot L11) ; une demande soumise à décision ne peut pas être renvoyée à l'instruction, l'adhérent ne peut pas se désister et une décision ne peut pas être corrigée (à trancher par les instances) ; rien n'oblige l'instructeur et le décideur à être deux personnes différentes ; un enregistreur (`social.create`) ne peut déposer une pièce que sur une demande `recue` ; la recherche par référence fonctionne par préfixe ; l'e-mail d'affectation ne contient que la référence et un lien ; contrôle visuel fait par Playwright à 1440, 768 et 390 px, mais les liens de tableau et le fil d'Ariane restent sous 44 px au pointeur fin (conception existante).

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

## Site web (CMS, lot L3)

Le CMS gère le contenu du futur site public (lot L4 : le rendu public et l'adhésion en ligne). **Aucun contenu n'est inventé par le code** : pages, textes, images, coordonnées et réseaux sociaux sont saisis par la Mutuelle. Écrans : menu « Site web » de l'administration (`/admin/site/...`).

### Rôles

| Besoin | Permission | Rôles prévus |
|---|---|---|
| Rédiger, modifier, **soumettre à relecture** pages, articles, évènements, documents, galeries, bannières, témoignages ; catégories et étiquettes | `cms.pages.manage` | `redacteur` (seul droit), `editeur`, `gest_cms`, `admin_site`, `admin_general`, `resp_communication`, `secretaire_general`, `super_admin` |
| **Publier, programmer, archiver, retirer** ; enregistrer les menus (avec `cms.pages.manage`) | `cms.publish` | `editeur`, `gest_cms`, `admin_site`, `admin_general`, `resp_communication`, `super_admin` |
| Médiathèque | `cms.media.manage` | `gest_medias`, `editeur`, `gest_cms`, `admin_site`, `admin_general`, `resp_communication`, `super_admin` |
| Formulaires reçus (boîte de réception, export) | `cms.forms.manage` | `gest_cms`, `admin_site`, `admin_general`, `resp_communication`, `super_admin` |
| **Saisir** les réglages du site | `cms.settings.manage` | `admin_site`, `admin_general`, `resp_communication`, `super_admin` |
| **Valider** un réglage (coordonnées, décisions) | `settings.validate` | `pca` |

Le rédacteur soumet, il ne publie jamais. Chaque transition de publication est auditée (sans donnée personnelle de visiteur).

### Contenu initial issu du cahier des charges (L4)

`php bin/seed-site.php --actor=<courriel d'un administrateur>` crée **en brouillon** les pages du site tirées du cahier des charges 2026–2027 (texte copié, réserves conservées) et les menus s'ils sont vides ; idempotent par adresse (une page existante, même modifiée, n'est jamais touchée). Le script refuse `--publish` : **le cahier n'étant pas encore adopté (art. 21–23), un humain relit chaque page (champ interne « Source » de chaque bloc, jamais affiché au public) puis la publie.** Rubriques non créées faute de source : mot du Président, historique, mentions légales, confidentialité, galerie (liste « À fournir par la Mutuelle » sur l'écran Pages).

### Circuit de publication

États : `brouillon` → `en_relecture` → `publie` (ou `programme`, publié à l'heure par le cron) → `archive`. Un brouillon, un contenu en relecture ou archivé n'est **jamais public** (hors fichiers de la médiathèque : voir plus bas).

- **Version relue** : le publicateur publie ou programme la version qu'il a lue. Si le contenu change entre la lecture et le clic (nouvelle sauvegarde), la publication est refusée (« relisez-le »). Les contenus simples (documents, galeries, bannières, témoignages) exigent aussi la version affichée.
- **Version en ligne conservée** : modifier une page ou un article **publié** le repasse en brouillon, mais la dernière version publiée reste servie au public jusqu'à la republication. « Retirer du site » supprime aussi cette version conservée. Une publication programmée échue remplace la version conservée.
- Renommer une catégorie ou une étiquette (ou changer son adresse) utilisée par un article en ligne exige `cms.publish`. Un article déjà publié : seul un publicateur change son adresse, sa catégorie, son auteur affiché ou ses étiquettes. Un auteur qui modifie ces champs sur un article en relecture le renvoie en brouillon.
- Un évènement déjà en ligne est modifié en direct par un publicateur (pas de version conservée) ; l'auteur ne le modifie plus.
- Changer l'adresse (slug) d'une page déjà publiée conserve l'ancienne en redirection simple ; une page publiée ne peut pas être supprimée (l'archiver).
- Adresses : `[a-z0-9-]`, 1 à 80 caractères, uniques ; réservées : `admin`, `verifier`, `espace`, `assets`, `media`, `sante`, `adhesion-en-ligne`, `partenariat`, `sitemap.xml`, `robots.txt`, `favicon.ico`, et, pour les pages seulement, les listes fixes du site `actualites`, `evenements`, `documents`, `galeries` (une page à ces adresses serait masquée par la liste : refus avec un message clair ; une page plus ancienne déjà à une de ces adresses n'est jamais au plan du site).

### Blocs d'une page (registre fixe, pas de constructeur libre)

Une page est une suite ordonnée (60 au plus) de blocs ; chaque bloc est stocké en JSON (`cms_pages.blocks`, copie publiée dans `published_blocks`) avec une clé `type` :

| Type | Clés JSON |
|---|---|
| `texte` | `html` (texte riche nettoyé) |
| `titre` | `text`, `level` (2 ou 3) |
| `intro` | `title`, `note` |
| `faits` | `items` : `[{label, value}]` |
| `domaines` | `items` : `[{name, description, nature}]` |
| `liste` | `items` : `[{text}]` |
| `appel` | `title`, `text`, `button_label`, `page_id` ou `url` |
| `image` | `media_id` (id de la médiathèque, **chaîne ou entier**), `caption` |
| `documents` | `category` (vide = toutes) |
| `articles`, `evenements` | `count` (1 à 12) |
| `formulaire` | `form_key` : `contact`, `partenariat`, `adhesion` (**`adhesion` n'est pas un formulaire de la boîte de réception : le rendu L4 l'oriente vers `/adhesion-en-ligne`** ; le formulaire `information` n'a pas de bloc) |
| `dossier` | « Préparer mon dossier » (L4) : `title`, `intro`, `situations` : `[{key, label}]` (clé en minuscules et tirets, unique), `pieces` : `[{label, situations}]` (`situations` : clés déclarées séparées par des virgules, vide = pièce toujours demandée). Le visiteur coche sa situation et la liste se filtre (`site.js`) ; sans JavaScript, toute la liste reste affichée avec « (selon votre situation) » ; rien n'est envoyé ni stocké. Éditeur : chaque liste a ses boutons (`item-add:i:champ`, `item-del:i:j:champ`) |

**Contrat des références de médias** : seules les clés `media_id` (un média), `media_ids` (liste de médias, galeries) et `file_id` désignent un média dans un bloc ; la médiathèque s'en sert pour refuser la suppression d'un média encore utilisé (aussi dans le texte riche : images `/media/...`). Toute nouvelle clé de média doit être ajoutée à ce contrat (`MediaService::usages`).

Texte riche : éditeur limité (titres 2 à 4, gras, italique, listes, citation, lien, image de la médiathèque), nettoyé à l'enregistrement **et** à l'affichage : aucun script, gestionnaire `on*`, style ni URL `javascript:` ou `data:` ; liens externes en `rel="noopener noreferrer"` ; images limitées aux médias du site (`/media/...`) avec texte alternatif. Sans JavaScript, le champ reste un champ de saisie du HTML autorisé.

`max_input_vars` : un formulaire de page peut envoyer jusqu'à 800 champs de blocs ; **PHP doit autoriser `max_input_vars` ≥ 1000** (défaut PHP et cPanel : 1000). Au-delà, le formulaire est refusé avec un message clair plutôt que tronqué.

### Médiathèque

JPG, PNG, WebP et PDF seulement ; **pas de SVG** (ni HTML, ni PHP, ni double extension) ; texte alternatif obligatoire pour une image ; le nom du fichier téléversé n'est jamais conservé (nom aléatoire) ; tailles et nombre de pixels bornés. **Les fichiers de la médiathèque sont publics par leur URL dès le téléversement** (nom aléatoire de 128 bits, pas de liste de répertoire), y compris ceux d'un brouillon : « aucun brouillon public » vaut pour les pages, listes et menus, pas pour le fichier lui-même. **Ne jamais déposer de document confidentiel dans la médiathèque.** Un média utilisé (page, article, bannière, texte riche…) ne peut pas être supprimé : l'écran liste les usages. `public/media` ne doit **jamais exécuter de code** : le `.htaccess` fourni l'impose sous Apache ; **sous nginx il est ignoré** : le déploiement doit interdire PHP sous `/media` (voir `public/media/.htaccess`).

### Menus

Deux emplacements : `header` (7 éléments de premier niveau au plus) et `footer` (12) ; deux niveaux, 40 éléments au plus. Chaque élément pointe vers une page **ou** une adresse (https, mailto, tel, chemin du site). Enregistrer un menu le publie immédiatement : il faut donc **les deux droits** `cms.pages.manage` et `cms.publish` (la route et le service l'exigent). Un élément dont la page n'a pas de version en ligne est masqué au public avec ses sous-éléments ; un élément de premier niveau qui a des sous-éléments ne peut pas être supprimé tant qu'ils existent.

### Articles et évènements

Articles : catégorie, étiquettes, image à la une, extrait, SEO (titre, description, image de partage, adresse canonique) ; évènements : début, fin, lieu. Programmation : le publicateur choisit une date future (heure d'Abidjan) ; la tâche `cms.publish_scheduled` (cron, toutes les minutes — voir « Cron ») publie à l'échéance ; un échec sur un contenu n'empêche pas les autres et est audité.

### Circuit simple (documents, galeries, bannières, témoignages)

Brouillon → publié → archivé, sans version conservée ni programmation : un rédacteur crée, **un publicateur publie** en confirmant la version lue. Documents officiels : un PDF de la médiathèque, catégorie, date. Galeries : photos avec texte alternatif obligatoire. Bannières : trois publiées au plus (période en cours ou à venir), lien sûr. **Témoignages : désactivés par défaut**, publiables seulement si le réglage « Témoignages activés » est validé **et** si la pièce de consentement signée est jointe ; la pièce est stockée sur le disque **privé** et n'est jamais servie publiquement.

### Réglages du site (`site.*`)

Coordonnées (e-mail, téléphone, adresse), Facebook (https sur `facebook.com`, `fb.com` ou `m.facebook.com`), WhatsApp, texte de consentement des formulaires, adhésion en ligne ouverte, témoignages activés, conservation des formulaires (mois) : tous **vides / « non configuré » à l'installation**. Flux : un titulaire de `cms.settings.manage` **saisit** → la valeur est « à valider » → un titulaire de `settings.validate` la **valide** en indiquant la référence de la décision (procès-verbal) en ayant vu la valeur courante (si elle a changé entre-temps, la validation est refusée). Modifier une valeur validée la remet « à valider ». **Tant qu'un réglage n'est pas validé, le site public ne l'utilise pas.** La même personne peut saisir et valider si elle cumule les deux droits : à éviter (décision des instances).

### Formulaires reçus et notifications

Trois formulaires (contact, information, partenariat) déposés via `FormInbox::submit` (appelé par le lot L4) : champs en liste blanche, texte brut sans balises, caractères de contrôle et invisibles retirés, champ piège `website`, 5 envois au plus par adresse IP et 10 minutes. Boîte de réception `/admin/site/formulaires` : références `MSG-AAAA-NNNNN`, états nouveau → en cours → traité → archivé, affectation, notes internes, historique en ajout seulement, export CSV neutralisé. L'audit et l'e-mail de notification ne contiennent **que la référence et le type de formulaire**, jamais le message ni les coordonnées du visiteur ; le destinataire est l'adresse de contact validée, sinon les titulaires de `cms.forms.manage`. Notifications : **20 e-mails par heure au plus** pour l'ensemble des formulaires (au-delà, l'envoi reste dans la boîte de réception sans e-mail) ; limite de 5 envois par IP et 10 minutes, les adresses IPv6 étant regroupées par /64. Aucune purge automatique (durée à fixer par les instances).

### Adresses publiques (pour le lot L4)

Le schéma d'URL public est défini **une seule fois** dans `App\Modules\Cms\PublishedContent` : pages `/{slug}` (la forme hiérarchique `/parent/enfant` n'est honorée que si tous les parents sont en ligne et redirige vers `/{slug}`), articles `/actualites/{slug}`, évènements `/evenements/{slug}`, plan du site `sitemapUrls()`. Le rendu public (L4) ne lit que `PublishedContent` (jamais un brouillon), qui fournit aussi les résolveurs `mediaUrl()`, `fileUrl()`, `pagePath()` (pages en ligne seulement) et `category()` pour les identifiants qu'il renvoie.

### Adhésion en ligne (`/adhesion-en-ligne`, lot L4)

Parcours public en 5 étapes servies par le serveur (Situation, Identité, Coordonnées et profession, Pièces, Consentement et envoi), sans JavaScript, une adresse par étape (`/adhesion-en-ligne/etape/1` à `5`, accusé `/adhesion-en-ligne/envoye`). Code : `App\Modules\Site\ApplyFlow` (règles) et `ApplyController` (routes).

- **Ouverture** : seulement si `site.adhesion_open` est **validé et vaut oui** et si `site.consent_text` est **validé et non vide** ; relu à chaque requête ; fermé, **aucune session ni cookie** n'est créé (prédicat de StartSession). Sinon toute route du parcours (GET et POST) répond une page « Les adhésions en ligne ouvriront prochainement. » (200, avec la liste des pièces du bloc « dossier » de la page Adhésion si elle est publiée, et le téléphone / l'e-mail de contact validés) et **rien n'est écrit**. Sur la page Adhésion, le bloc formulaire « adhesion » affiche « Commencer ma demande » seulement quand le parcours est ouvert, sinon « Le formulaire en ligne ouvrira prochainement. ».
- **Brouillon** : en session (cookie de session seulement sur ces routes), clé `apply`, jeton aléatoire ; chaque étape valide ses champs avec les règles de `MemberService` (celles de l'envoi), plus : référentiels **actifs** seulement, sexe dans la liste, e-mail refusé s'il contient CR/LF. Les cases « livret disponible » et « ayants droit à déclarer » restent dans le brouillon (aucune colonne de demande ne les reçoit).
- **Pièces** : toutes facultatives (l'art. 6 les liste sans les rendre bloquantes) ; profils `document` (PDF, JPG, PNG) et `image` pour la photo (ré-encodée) ; 6 pièces et 15 Mo au plus par demande, taille par fichier `UPLOAD_MAX_MB` ; **12 tentatives de téléversement par adresse IP et par heure** (refusées comprises, clé `IpKey`, repère 9) ; **200 Mo au plus pour l'ensemble des pièces de brouillon non rattachées** (au-delà : « service momentanément indisponible » jusqu'à la purge du cron, 6 h) ; disque **privé** ; nom du fichier du visiteur jamais conservé (`identite.pdf`, `photo.png`…) ; liées au jeton (`apply_draft_files`, migration 0023), rattachées à la demande (`membership_application_files`, types `identite`, `livret_maritime`, `photo`, `autre`) dans la même transaction que la création ; visibles côté administration sur la demande.
- **Envoi** : `ApplicationService::submit(…, 'en_ligne', null, $ip)` crée une demande « Reçue », **jamais un membre** ; l'adresse IP est conservée dans `membership_applications.ip` (preuve et lutte contre les abus ; durée de conservation à fixer par les instances). Accusé « Votre demande a été reçue. Elle sera examinée par la Mutuelle. Conservez cette référence. » avec la référence `AD-AAAA-NNNN`, identique qu'il existe ou non des homonymes (la détection des doublons reste interne, à la validation). Un renvoi du même formulaire (double clic, retour arrière) ne crée rien : jeton consommé en session et en base (`membership_applications.draft_token` unique, migration 0024 : un second envoi simultané est annulé même sans verrou de session), même accusé.
- **Anti-abus** : jeton CSRF ; champ piège `website` ; délai minimal de 8 s entre l'ouverture de l'étape 1 et l'envoi (horodatage signé HMAC avec `APP_KEY`) ; refus générique sans dire quel contrôle a échoué ; 5 demandes au plus par adresse IP et 30 minutes (clé partagée avec les formulaires : `App\Core\IpKey`, IPv4 mappée = IPv4, IPv6 par /64 ; compteur `login_attempts`, repère 7) puis 429.
- **Accusé par e-mail** : seulement si une adresse est donnée et que l'envoi est configuré (`MAIL_FROM` valide), en différé ; sujet et corps fixes avec la **référence seule** (aucun nom) ; 20 accusés par heure au plus pour tout le site (repère 8) ; un échec d'envoi n'empêche rien.
- Toutes les réponses du parcours : `Cache-Control: no-store`, `X-Robots-Tag: noindex, nofollow`. L'audit ne contient que référence, source et zone.
- **Limites** : la limite par IP est partagée derrière un proxy ou un CDN (`REMOTE_ADDR`) ; adhésion fermée, un POST sans session reçoit la page 419 (aucun jeton CSRF obtenable), rien n'est écrit. Les pages publiques contenant un bloc formulaire sont servies en `Cache-Control: no-cache` (revalidation ETag) : l'état du bouton d'adhésion n'est jamais périmé.

### Limites connues

Pas de glisser-déposer (boutons Monter/Descendre) ; une seule version de travail par page (pas d'historique de versions) ; redirections d'adresse simples (pages seulement, pas d'articles) ; listes d'administration plafonnées à 200 lignes pour les contenus simples ; SEO avancé (T3.8) fait au lot L4 (voir « Site public (lot L4) ») ; pas de purge des pièces de consentement ni des formulaires ; les images du texte riche ne bloquent pas la suppression d'un média dans tous les cas (voir le plan L3). Détail et décisions : [`docs/superpowers/plans/2026-11-16-l3-decisions-et-reports.md`](docs/superpowers/plans/2026-11-16-l3-decisions-et-reports.md).

## Site public (lot L4)

Le site public est servi par `App\Modules\Site` (gabarits `app/views/public/`, feuille `public/assets/css/public.css`). **Seul du contenu publié est public** : il ne lit que `PublishedContent` (L3) ; un brouillon, un contenu en relecture, archivé ou programmé pour plus tard n'apparaît ni en page, ni au menu, ni au plan du site. Les aperçus d'administration restent sous session et `noindex`.

### Ce qui est publié

- **Pages** du CMS à l'adresse plate `/{slug}` ; accueil = page « accueil » servie à `/` (sinon une page d'attente « Le site de la Mutuelle est en cours de construction »). Listes `/actualites`, `/evenements`, `/documents`, `/galeries` (état vide honnête tant que rien n'est publié), fiches `/actualites/{slug}`, `/evenements/{slug}`, `/galeries/{slug}`. Les anciennes adresses, les majuscules et la barre finale redirigent en **une seule** 301 vers l'adresse actuelle.
- **Formulaires** `/contact` (contact ou demande d'information) et `/partenariat`, chacun seulement si sa page hôte (« contact », « partenaires ») est en ligne ; les messages arrivent dans la boîte `/admin/site/formulaires` ; l'accusé « Message envoyé » n'apparaît qu'une fois, juste après un vrai envoi.
- **Adhésion en ligne** `/adhesion-en-ligne` (voir ci-dessus) et **vérification de carte** `/verifier/{jeton}` (L5).
- **SEO** (T3.8, fait ici) : titre et description par page, adresse canonique (champ `canonical_url` sûr, sinon l'adresse propre sans paramètre, absolue si `APP_URL` est renseigné), balises Open Graph et Twitter, `noindex` sur les pages d'erreur, de formulaire envoyé et d'adhésion ; `/sitemap.xml` (contenu publié seulement) et `/robots.txt` ; images responsives (variantes `srcset`).
- **Cookies** : aucun sur le site public ; une session (cookie `mmmgci_sid`) seulement sur `/contact` et `/partenariat` quand leur page est en ligne, et sur `/adhesion-en-ligne` quand l'adhésion est ouverte. En-têtes CSP stricte, `nosniff`, `X-Frame-Options` partout ; méthode non permise → 405.
- **Performance** : accueil sous 200 Ko hors photos (mesuré : ~150 Ko transférés **sans compression**, 15 requêtes) ; compression et cache des fichiers statiques par `public/.htaccess` ; pages en cache public 5 min avec ETag (pages à formulaire et accueil : revalidées à chaque visite) ; tout fonctionne sans JavaScript.

### Comment publier

1. Un rédacteur (`cms.pages.manage`) crée ou modifie la page dans « Site web » puis la **soumet à relecture**.
2. Un publicateur (`cms.publish`) relit **la version affichée** et la publie (ou la programme : la tâche cron `cms.publish_scheduled` la publie à l'heure ; le cron doit donc tourner, voir « Cron »).
3. Les menus (`header`, `footer`) s'enregistrent avec les deux droits ; un élément qui pointe vers une page non publiée reste masqué au public.

### Contenu semé en brouillon : à relire par un humain

`php bin/seed-site.php --actor=<courriel>` crée **en brouillon** 11 pages et les deux menus, avec des textes **copiés du cahier des charges 2026–2027**, qui **n'est pas encore adopté**. Rien n'est publié par le script (`--publish` est refusé). Avant publication, un humain relit chaque page : le champ interne « Source » de chaque bloc (jamais affiché) indique l'article du cahier. Le semis ne remplace jamais une page existante : une correction du semis (par exemple le titre « Objectif spécifique » ou la réserve de l'art. 9 sur les pages Emploi et Formation) ne touche pas une base déjà semée ; reprendre ces pages à la main si besoin. Le titre de l'accueil est la devise « Un nouveau jour se lève » (le nom complet est dans le titre SEO et sur la carte).

### Pages « à fournir » par la Mutuelle

Mot du Président, historique, mentions légales, politique de confidentialité, galerie et photographies : **aucune source** dans le cahier des charges, donc **non créées** (liste « À fournir par la Mutuelle » sur l'écran Pages). Aucune photo n'est semée : les visuels sont des **dessins** du métier, toujours légendés « Illustration », jamais présentés comme des photos ; les vraies photos passent par la médiathèque. Coordonnées (téléphone, e-mail, adresse, réseaux sociaux) : affichées seulement une fois **validées** dans les réglages du site.

### Ouvrir l'adhésion en ligne : deux réglages à valider

L'adhésion en ligne est **fermée par défaut**. Elle ne s'ouvre que lorsque **les deux** réglages suivants sont **saisis puis validés** (référence de la décision des instances, par un titulaire de `settings.validate`) dans « Site web › Réglages » :

1. `consent_text` : le texte de consentement au traitement des données, **fourni par la Mutuelle** (aucun texte n'est proposé par le code) ;
2. `adhesion_open` : « oui ».

Tant que l'un manque, toute adresse du parcours affiche « Les adhésions en ligne ouvriront prochainement. », sans session ni écriture ; le bouton de l'accueil s'intitule « Comment adhérer » et mène à la page d'information « adhesion » (si elle est en ligne). Ouverte, il devient « Demander mon adhésion » et mène au formulaire. Une demande déposée apparaît dans `/admin/adherents/demandes` avec l'origine « En ligne » : c'est une **demande**, jamais un membre, instruite comme les autres.

### Sauvegarde des pièces déposées

Les pièces jointes aux demandes d'adhésion (et les pièces de consentement des témoignages) sont sur le **disque privé** `storage/uploads/private/`, hors de la racine web, jamais servies publiquement. Elles ne sont **pas** dans la base : la sauvegarde doit copier **à la fois** la base de données **et** ce dossier (la sauvegarde cPanel « répertoire personnel » le contient si le projet y est installé ; à vérifier lors de l'installation, L13). Les fichiers de la médiathèque (`public/media/`) sont publics par nature mais doivent aussi être sauvegardés. Les pièces de brouillon abandonnées (jamais envoyées, plus de 6 h) sont purgées par la tâche cron `site.cleanup_apply_drafts`.

### Limites du lot L4

- **Pas d'étude de sites comparables** : l'accès réseau aux sites visés était bloqué pendant les travaux (voir [`docs/conception/etude-sites-comparables.md`](docs/conception/etude-sites-comparables.md)) ; la direction visuelle repose sur le contexte du projet, pas sur une observation de sites.
- Photos, texte de consentement, coordonnées et pages « à fournir » : à fournir par la Mutuelle.
- Détail des décisions et des reports : [`docs/superpowers/plans/2026-11-16-l4-decisions-et-reports.md`](docs/superpowers/plans/2026-11-16-l4-decisions-et-reports.md).

## Site public (lot L3) — points à décider par les instances

- **Pièces de consentement des témoignages** : supprimer un témoignage (jamais publié) supprime seulement sa ligne, la pièce signée reste sur le disque privé comme preuve ; remplacer la pièce d'un témoignage supprime l'ancienne. La durée de conservation et la purge des pièces orphelines sont à décider par les instances.

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
