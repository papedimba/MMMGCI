# MMMGCI — Plateforme numérique

Plateforme institutionnelle et de gestion de la **Mutuelle des Marins Marchands et Gens de Mer de Côte d'Ivoire** — « Un nouveau jour se lève ».

État : **lot L0 (socle technique) terminé**. La page d'accueil est une page technique (« Socle MMMGCI opérationnel »), remplacée en L4.

- Conception : [`docs/conception/`](docs/conception/README.md)
- Contexte produit : [`PRODUCT.md`](PRODUCT.md)
- Suivi des tâches : [`TASKS.md`](TASKS.md)

## Prérequis

- PHP ≥ 8.2 avec les extensions `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `intl`, `dom` (pas de `bcmath`).
- MariaDB ≥ 10.2 ou MySQL ≥ 8.0.16 (les contraintes `CHECK` ne sont appliquées qu'à partir de ces versions).
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
  views/        gabarits PHP (layouts/, errors/)
  init.php      amorçage commun (fuseau, configuration, gestionnaire d'erreurs) des pages web et des scripts bin/
  bootstrap.php retourne l'App (init.php puis kernel.php qui l'assemble)
bin/            scripts CLI : dev-db, migrate.php, cron.php
database/migrations/  fichiers NNNN_*.sql (ne jamais modifier une migration appliquée : en ajouter une nouvelle)
public/         racine web : index.php, .htaccess, media/
routes/web.php  déclaration des routes
storage/        logs/ (app-AAAA-MM-JJ.log), uploads/, cache/ — interdit au web
tests/          Unit/, Feature/, Support/
```

## Cron

Une seule entrée cPanel, toutes les 5 minutes :

```
*/5 * * * * php /home/USER/mmmgci/bin/cron.php
```

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
- Le travail de « mot de passe oublié » (jeton, audit, e-mail) s'exécute après la réponse sous PHP-FPM ou LiteSpeed ; sous mod_php ou `php -S`, il s'exécute avant que le client soit libéré.

## Journal d'audit — limites

La chaîne d'audit est vérifiable (`Audit::verify()`) mais n'a pas d'ancrage externe : un attaquant ayant un accès complet à la base peut la réécrire entièrement.
Dans une transaction longue, appeler `log()` en dernier : il verrouille la tête de chaîne jusqu'au commit.
