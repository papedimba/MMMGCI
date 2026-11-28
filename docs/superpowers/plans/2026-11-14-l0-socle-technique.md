# Lot L0 — Socle technique PHP natif — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Livrer le socle PHP sans framework sur lequel tous les modules MMMGCI seront construits : point d'entrée, routeur, vues échappées, base de données et migrations, validation, session/CSRF, en-têtes de sécurité, fichiers, montants, références, paramètres, audit, e-mail, cron, exports, nettoyage HTML.

**Architecture:** Petites classes dans `app/Core/` (namespace `App\Core`), chargées par un autoload PSR-4 maison. Un seul point d'entrée `public/index.php` construit `App\Core\App`, qui enchaîne middleware → routeur → contrôleur → `Response`. Toutes les dépendances (base, session, config) sont passées explicitement au constructeur ; pas de conteneur, pas de façade, pas d'état global hormis `$_SESSION`.

**Tech Stack:** PHP 8.2+ (dev : 8.3), MariaDB 10.11 (dev), PDO MySQL, PHPUnit 11 via Composer **en dev seulement**.

**Spec:** `docs/conception/03-architecture.md` (B1–B6), `docs/conception/04-base-de-donnees.md` (§1, §3.1), `docs/conception/06-plan-taches.md` (L0).

## Global Constraints

- PHP ≥ 8.2 ; `declare(strict_types=1);` dans tout fichier PHP.
- Aucune dépendance PHP tierce en production ; Composer sert uniquement à installer PHPUnit (`require-dev`). `vendor/` est ignoré par git et exclu de l'archive. L'application ne charge jamais `vendor/autoload.php` hors des tests.
- Extensions requises : `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `intl`, `dom`. **Pas de `bcmath`**.
- Toute requête SQL passe par `Database` avec paramètres liés ; aucune valeur interpolée dans du SQL.
- Base : InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, horodatages stockés en UTC, affichage `Africa/Abidjan`.
- Montants : `DECIMAL(15,2)` en base, `int` de centimes en PHP, devise `XOF`, affichage sans décimales (`150 000 XOF`, espace insécable fine U+202F comme séparateur de milliers).
- Messages destinés aux utilisateurs en français ; chaque erreur dit le problème **et** la correction.
- `APP_DEBUG=false` par défaut ; aucun détail technique affiché en production.
- Seul `public/` est exposé au web.
- Références lisibles : `PREFIXE-AAAA-NNNN…` (ex. `DEC-2026-0031`, `MMMGCI-2026-00412`).

## Review Focus

1. **Noms ivoiriens avec accents et apostrophes** (`N'Guessan`, `Kouamé`, `Aïcha`) : doivent traverser validation, base, échappement HTML et export CSV sans être altérés ni rejetés. → tests dans Tasks 3, 5, 15.
2. **Saisie de montants telle que tapée par un trésorier** (`150 000`, `150000`, `150 000,50`, `1.5e3`, `-500`, vide) : formats français acceptés, notation scientifique et négatifs refusés avec un message clair. → test dans Task 8.
3. **Téléversement réaliste** (`PHOTO.JPG` en majuscules, `piece.identite.pdf` à plusieurs points, fichier vide, fichier dépassant `upload_max_filesize` qui arrive avec `UPLOAD_ERR_INI_SIZE`) : majuscules et points multiples acceptés, cas d'erreur expliqués en français. → tests dans Task 11.
4. **Migration qui échoue au milieu** (MySQL valide implicitement le DDL déjà exécuté) : la version n'est pas marquée appliquée, l'erreur nomme le fichier, une nouvelle exécution après correction reprend. → test dans Task 4.
5. **Cron interrompu** (processus tué, verrou jamais libéré) : un verrou plus vieux que 30 minutes est repris au passage suivant au lieu de bloquer pour toujours. → test dans Task 14.

---

## File Structure

```
.env.example  .gitignore  composer.json  phpunit.xml  README.md
public/index.php  public/.htaccess  public/media/.htaccess
app/bootstrap.php            # autoload, config, fuseau, gestion d'erreurs → retourne App
app/helpers.php              # e(), money_fmt()
app/Core/Config.php  Env.php  ErrorHandler.php  App.php
app/Core/Http/Request.php  Response.php  Router.php  Middleware.php (interface)
app/Core/Http/SecurityHeaders.php  ForceHttps.php  VerifyCsrf.php
app/Core/View.php
app/Core/Database.php  Migrator.php
app/Core/Validator.php
app/Core/Session.php  Csrf.php
app/Core/Money.php  Sequence.php  Settings.php  Audit.php
app/Core/Uploads.php  UploadError.php
app/Core/HtmlSanitizer.php
app/Core/Mailer.php
app/Core/Scheduler.php
app/Core/Export/Csv.php  Xlsx.php  SvgBarChart.php
app/views/layouts/base.php  app/views/errors/{404,403,419,500}.php  app/views/home.php
bin/cron.php  bin/migrate.php
database/migrations/0001_core.sql
routes/web.php
storage/{private,logs,cache,backups}/.gitkeep   storage/.htaccess
tests/bootstrap.php  tests/DatabaseTestCase.php  tests/Unit/…  tests/Feature/…
```

---

### Task 1: Squelette, configuration, gestion d'erreurs, environnement de test

**Files:**
- Create: `.gitignore`, `.env.example`, `composer.json`, `phpunit.xml`, `app/bootstrap.php`, `app/helpers.php`, `app/Core/Env.php`, `app/Core/Config.php`, `app/Core/ErrorHandler.php`, `storage/**/.gitkeep`, `storage/.htaccess`, `tests/bootstrap.php`, `tests/DatabaseTestCase.php`
- Test: `tests/Unit/ConfigTest.php`, `tests/Unit/HelpersTest.php`

**Interfaces:**
- Produces:
  - `Env::load(string $path): array<string,string>` — parse `KEY=valeur`, ignore `#` et lignes vides, retire les guillemets, ne lève pas d'exception si le fichier manque (retourne `[]`).
  - `new Config(array $values)`, `Config::get(string $key, mixed $default = null): mixed`, `Config::bool(string $key): bool` (`true/1/yes/on`).
  - `ErrorHandler::register(bool $debug, string $logFile): void` — journalise dans `storage/logs/app-AAAA-MM-JJ.log`, rend `errors/500.php` sans détail si `$debug=false`.
  - `e(?string $s): string` — `htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
  - `tests/DatabaseTestCase` : classe abstraite PHPUnit qui recrée la base `DB_TEST_NAME` (DROP/CREATE), applique toutes les migrations via `Migrator` (Task 4) et expose `protected Database $db`.
- Clés `.env.example` : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `APP_KEY` (32 octets base64, sert au hachage d'audit et aux jetons), `DB_HOST`, `DB_PORT=3306`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_TEST_NAME`, `MAIL_FROM`, `MAIL_DRIVER=mail`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SESSION_IDLE_MINUTES=30`, `UPLOAD_MAX_MB=5`.

- [ ] **Step 1: Installer l'environnement de dev** — `apt-get install -y mariadb-server`, démarrer `mariadbd`, créer l'utilisateur de test ; `composer require --dev phpunit/phpunit:^11` ; `.gitignore` contient `/vendor/`, `/.env`, `/storage/*/*` sauf `.gitkeep`, `/.taste-skill/`. Script `composer test` → `phpunit`.
- [ ] **Step 2: Écrire les tests**

```php
public function test_env_parses_quotes_comments_and_blank_lines(): void {
    // fichier temporaire : "# c\n\nAPP_NAME=\"MMMGCI\"\nAPP_DEBUG=false\n"
    $this->assertSame(['APP_NAME' => 'MMMGCI', 'APP_DEBUG' => 'false'], Env::load($tmp));
}
public function test_env_missing_file_returns_empty_array(): void {
    $this->assertSame([], Env::load('/nexiste/pas/.env'));
}
public function test_config_bool_and_default(): void {
    $c = new Config(['APP_DEBUG' => 'false', 'X' => 'on']);
    $this->assertFalse($c->bool('APP_DEBUG')); $this->assertTrue($c->bool('X'));
    $this->assertSame('d', $c->get('ABSENT', 'd'));
}
public function test_e_escapes_html_and_keeps_ivorian_names(): void {
    $this->assertSame('&lt;script&gt;', e('<script>'));
    $this->assertSame('N&#039;Guessan Kouamé Aïcha', e("N'Guessan Kouamé Aïcha"));
    $this->assertSame('', e(null));
}
```

- [ ] **Step 3: Lancer** `composer test` → FAIL (classes absentes).
- [ ] **Step 4: Implémenter** `Env`, `Config`, `ErrorHandler`, `helpers.php`, `app/bootstrap.php` (autoload `spl_autoload_register` : `App\` → `app/`, `date_default_timezone_set('Africa/Abidjan')`, `mb_internal_encoding('UTF-8')`), `storage/.htaccess` = `Require all denied`.
- [ ] **Step 5: Lancer** `composer test` → PASS.
- [ ] **Step 6: Commit** `git commit -m "L0: squelette, configuration, erreurs, environnement de test"`

### Task 2: Requête, réponse, routeur, middleware, point d'entrée

**Files:**
- Create: `app/Core/Http/{Request,Response,Router,Middleware}.php`, `app/Core/App.php`, `public/index.php`, `public/.htaccess`, `routes/web.php`
- Test: `tests/Unit/RouterTest.php`, `tests/Feature/AppTest.php`

**Interfaces:**
- Consumes: `Config` (Task 1).
- Produces:
  - `Request::fromGlobals(): Request` ; `new Request(string $method, string $path, array $query = [], array $post = [], array $files = [], array $server = [], array $cookies = [])` ; `method(): string`, `path(): string` (sans query, sans `/` final sauf racine), `input(string $k, mixed $d = null): mixed` (post puis query), `all(): array`, `file(string $k): ?array`, `isSecure(): bool`, `ip(): string`, `header(string $name): ?string`, `attribute(string $k): mixed` / `withAttribute(string $k, mixed $v): Request` (paramètres de route, utilisateur…).
  - `new Response(string $body = '', int $status = 200, array $headers = [])` ; `Response::redirect(string $url, int $status = 302): Response` ; `withHeader(string $n, string $v): Response` ; `status(): int` ; `body(): string` ; `headers(): array` ; `send(): void`.
  - `interface Middleware { public function handle(Request $r, callable $next): Response; }`
  - `Router::get|post(string $pattern, callable|array $handler, array $middleware = []): self` ; motifs `/membres/{id:\d+}`, `/actualites/{slug}` (par défaut `[^/]+`) ; `Router::group(string $prefix, array $middleware, callable $define): void` ; `Router::dispatch(Request $r): Response` → 404 (`errors/404.php`), 405 avec en-tête `Allow`. Un handler `[Classe::class, 'methode']` est instancié via une fabrique `callable(string $class): object` passée au constructeur du routeur.
  - `App::__construct(Config $c, Router $r, array $globalMiddleware)` ; `App::handle(Request $r): Response`.

- [ ] **Step 1: Écrire les tests**

```php
public function test_route_with_typed_param_matches_and_passes_param(): void // GET /membres/42 → attribut id === '42'
public function test_typed_param_rejects_non_digits_with_404(): void       // GET /membres/abc → 404
public function test_wrong_method_returns_405_with_allow_header(): void    // POST sur route GET → 405, Allow: GET
public function test_trailing_slash_is_normalized(): void                  // /contact/ == /contact
public function test_group_applies_prefix_and_middleware_in_order(): void  // ordre d'exécution enregistré : ['global','groupe','route']
public function test_unknown_path_returns_404_page(): void                 // status 404, corps contient « Page introuvable »
```

- [ ] **Step 2: Lancer** → FAIL.
- [ ] **Step 3: Implémenter** ; `public/index.php` : `$app = require __DIR__.'/../app/bootstrap.php'; $app->handle(Request::fromGlobals())->send();`. `public/.htaccess` : réécriture vers `index.php` si fichier absent, `Options -Indexes`.
- [ ] **Step 4: Lancer** → PASS ; vérification manuelle `php -S 127.0.0.1:8080 -t public` puis `curl -i /` (200) et `/xyz` (404).
- [ ] **Step 5: Commit** `L0: routeur, requête, réponse, point d'entrée`

### Task 3: Vues

**Files:**
- Create: `app/Core/View.php`, `app/views/layouts/base.php`, `app/views/errors/{404,403,419,500}.php`, `app/views/home.php`
- Test: `tests/Unit/ViewTest.php`

**Interfaces:**
- Produces: `new View(string $viewsDir)` ; `View::render(string $name, array $data = [], ?string $layout = 'layouts/base'): string` — `$name` en notation `dossier/fichier` sans `.php` ; les données sont extraites en variables ; le contenu est injecté dans le gabarit via `$content` ; `View::partial(string $name, array $data = []): string`. Un nom contenant `..` lève `InvalidArgumentException`.
- `layouts/base.php` : `<html lang="fr">`, `<meta charset="utf-8">`, viewport, `<title>` échappé, aucun script en ligne (CSP Task 7).

- [ ] **Step 1: Tests**

```php
public function test_render_escapes_by_default_via_e(): void // vue "<p><?= e($nom) ?></p>", $nom="<b>N'Guessan</b>" → "&lt;b&gt;N&#039;Guessan&lt;/b&gt;"
public function test_layout_wraps_content(): void
public function test_null_layout_returns_bare_view(): void
public function test_path_traversal_is_refused(): void       // render('../../etc/passwd') → InvalidArgumentException
```

- [ ] **Step 2: Lancer** → FAIL. **Step 3: Implémenter** (`ob_start` + `include` dans une closure isolée). **Step 4:** → PASS.
- [ ] **Step 5: Commit** `L0: moteur de vues échappé`

### Task 4: Base de données et migrations

**Files:**
- Create: `app/Core/Database.php`, `app/Core/Migrator.php`, `bin/migrate.php`, `database/migrations/0001_core.sql`
- Test: `tests/Unit/DatabaseTest.php`, `tests/Unit/MigratorTest.php` (étendent `DatabaseTestCase`)

**Interfaces:**
- Produces:
  - `Database::connect(Config $c, bool $test = false): Database` — DSN `mysql:…;charset=utf8mb4`, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `FETCH_ASSOC`, `SET time_zone = '+00:00'`.
  - `query(string $sql, array $params = []): PDOStatement` ; `fetch(string $sql, array $params = []): ?array` ; `fetchAll(string $sql, array $params = []): array` ; `fetchValue(string $sql, array $params = []): mixed` ; `insert(string $table, array $row): int` (dernier id) ; `update(string $table, array $row, array $where): int` ; `transaction(callable $fn): mixed` (rollback et relance en cas d'exception, imbrication interdite → `LogicException`) ; `pdo(): PDO`. Les noms de table/colonne passés à `insert`/`update` doivent correspondre à `/^[a-z_][a-z0-9_]*$/` sinon `InvalidArgumentException`.
  - `new Migrator(Database $db, string $dir)` ; `pending(): list<string>` ; `migrate(): list<string>` (versions appliquées) ; table `schema_migrations(version VARCHAR(100) PK, checksum CHAR(64), applied_at TIMESTAMP)` créée si absente ; fichiers `NNNN_nom.sql` triés, découpés sur `;` en fin de ligne ; somme SHA-256 ; si un fichier déjà appliqué a changé → `RuntimeException("La migration 0001_core.sql a été modifiée après son application…")`.
- `0001_core.sql` crée les tables transverses de `04-base-de-donnees.md` §3.1 utilisées par L0 : `settings`, `files`, `sequences`, `audit_logs`, `scheduled_runs`, `login_attempts` (colonnes et index exactement comme le §3.1 ; `audit_logs.hash`/`prev_hash` CHAR(64)).

- [ ] **Step 1: Tests**

```php
public function test_insert_and_fetch_roundtrip_utf8mb4(): void      // "N'Guessan Kouamé 🌊" relu à l'identique
public function test_transaction_rolls_back_on_exception(): void
public function test_identifier_injection_is_refused(): void          // insert('files; DROP TABLE x', …) → InvalidArgumentException
public function test_migrate_applies_once_then_nothing_pending(): void
public function test_modified_applied_migration_is_detected(): void
public function test_failed_migration_is_not_marked_and_rerun_resumes(): void
    // dossier temp : 0001 ok, 0002 "CREATE TABLE a(...); INVALID SQL;" → exception dont le message contient "0002"
    // 0002 absent de schema_migrations ; après correction du fichier (et DROP TABLE IF EXISTS a en tête), migrate() applique 0002
```

- [ ] **Step 2:** → FAIL. **Step 3: Implémenter.** `bin/migrate.php` appelle `Migrator::migrate()` et affiche les versions. **Step 4:** → PASS.
- [ ] **Step 5: Commit** `L0: base de données et migrations versionnées`

### Task 5: Validation

**Files:** Create `app/Core/Validator.php` · Test `tests/Unit/ValidatorTest.php`

**Interfaces:**
- Produces: `Validator::make(array $data, array $rules): Validator` ; règles en chaîne `'required|max:120'` ; `fails(): bool` ; `errors(): array<string, string>` (premier message par champ) ; `validated(): array` (seulement les champs déclarés, chaînes `trim`ées, `''` → `null`). Règles : `required`, `nullable`, `string`, `max:n`, `min:n` (longueur en caractères `mb_strlen`), `email`, `phone_ci` (10 chiffres après suppression des espaces/points/tirets, préfixe `+225`/`00225` toléré et retiré), `date` (`AAAA-MM-JJ` réel), `before_today`, `in:a,b,c`, `integer`, `amount` (via `Money::parse`, Task 8), `same:champ`. Libellés : `make($data, $rules, $labels = [])`.
- Messages fixés : `required` → « Le champ {label} est obligatoire. » ; `phone_ci` → « Numéro de téléphone invalide : saisissez 10 chiffres (ex. 07 12 34 56 78). » ; `email` → « Adresse e-mail invalide (ex. nom@exemple.ci). » ; `max` → « {label} : {n} caractères au maximum. » ; `date` → « Date invalide : utilisez le format JJ/MM/AAAA. »

- [ ] **Step 1: Tests**

```php
public function test_required_message_uses_label(): void
public function test_phone_ci_accepts_spaces_and_country_code(): void // "+225 07 12 34 56 78" → validated '0712345678'
public function test_phone_ci_rejects_short_number_with_example(): void
public function test_max_counts_characters_not_bytes(): void          // "Aïcha" = 5 caractères, max:5 passe
public function test_names_with_apostrophe_and_accents_pass_string_max(): void
public function test_validated_returns_only_declared_trimmed_fields(): void
public function test_date_rejects_impossible_date(): void             // 2026-02-30
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS (la règle `amount` est ajoutée en Task 8 ; d'ici là elle n'existe pas).
- [ ] **Step 5: Commit** `L0: validation serveur en français`

### Task 6: Session, CSRF, jetons à usage unique, messages flash

**Files:** Create `app/Core/Session.php`, `app/Core/Csrf.php`, `app/Core/Http/VerifyCsrf.php` · Test `tests/Unit/SessionTest.php`, `tests/Unit/CsrfTest.php`, `tests/Feature/CsrfMiddlewareTest.php`

**Interfaces:**
- Produces:
  - `new Session(array &$store, int $idleMinutes, string $name = 'mmmgci_sid')` (le tableau est `$_SESSION` en production, un tableau local en test) ; `start(bool $secure): void` (cookie `HttpOnly`, `SameSite=Lax`, `Secure` si HTTPS, `use_strict_mode`) ; `get/set/forget(string $k)` ; `regenerate(): void` ; `isExpired(int $now): bool` + `touch(int $now)` ; `flash(string $k, mixed $v)` / `pullFlash(string $k): mixed` ; `invalidate(): void`.
  - `new Csrf(Session $s)` ; `token(): string` (64 hex, `random_bytes(32)`, stable pour la session) ; `check(?string $t): bool` (`hash_equals`) ; `oneTimeToken(string $form): string` ; `consumeOneTime(string $form, ?string $t): bool` (vrai une seule fois).
  - `VerifyCsrf implements Middleware` : sur `POST`, lit `_csrf` ou en-tête `X-CSRF-Token` ; échec → `Response` 419 rendant `errors/419.php` (« Votre session a expiré. Rechargez la page et recommencez : vos informations n'ont pas été enregistrées. »).

- [ ] **Step 1: Tests**

```php
public function test_token_is_stable_and_64_hex(): void
public function test_check_rejects_null_and_wrong_token(): void
public function test_one_time_token_cannot_be_replayed(): void
public function test_idle_expiry_after_configured_minutes(): void      // 30 min + 1 s → expiré
public function test_flash_is_read_once(): void
public function test_post_without_token_gets_419_and_get_passes(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: session, CSRF, jetons à usage unique`

### Task 7: En-têtes de sécurité et HTTPS

**Files:** Create `app/Core/Http/SecurityHeaders.php`, `app/Core/Http/ForceHttps.php` · Test `tests/Feature/SecurityHeadersTest.php`

**Interfaces:**
- Produces: `SecurityHeaders implements Middleware` ajoute à **toute** réponse : `Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, `X-Frame-Options: DENY`, et `Strict-Transport-Security: max-age=31536000` si la requête est HTTPS. `ForceHttps implements Middleware` : en `APP_ENV=production`, requête HTTP → 301 vers l'URL HTTPS équivalente ; inactif en `local`/`testing`.

- [ ] **Step 1: Tests**

```php
public function test_headers_present_on_200_and_404(): void
public function test_hsts_only_over_https(): void
public function test_force_https_redirects_in_production_only(): void // http://x/membres?a=1 → 301 https://x/membres?a=1
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: en-têtes de sécurité et HTTPS`

### Task 8: Montants et références

**Files:** Create `app/Core/Money.php`, `app/Core/Sequence.php` ; Modify `app/Core/Validator.php` (règle `amount`), `app/helpers.php` (`money_fmt`) · Test `tests/Unit/MoneyTest.php`, `tests/Unit/SequenceTest.php`

**Interfaces:**
- Produces:
  - `Money::parse(string $input): int` (centimes) — accepte chiffres, espaces (normales, insécables), virgule ou point décimal avec 0–2 décimales ; refuse vide, négatif, notation scientifique, plus de 2 décimales, > 9 999 999 999 999,99 → `InvalidArgumentException` avec message français : « Montant invalide : saisissez un nombre positif, par exemple 150 000 ou 150 000,50. »
  - `Money::fromDecimal(string $dbValue): int` (lecture `DECIMAL` `"150000.50"`) ; `Money::toDecimal(int $cents): string` (`"150000.50"`) ; `Money::format(int $cents): string` → `"150 000 XOF"` (U+202F, centimes affichés seulement s'ils sont non nuls : `"150 000,50 XOF"`) ; `Money::add(int ...$c): int`.
  - `new Sequence(Database $db)` ; `next(string $name, string $prefix, int $pad, ?int $year = null): string` — dans une transaction, `INSERT … ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1)` sur `sequences(name, year)` ; renvoie `"{$prefix}-{$year}-" . str_pad(...)`. Doit être appelée **à l'intérieur** de la transaction métier quand l'appelant en a une (accepte d'être appelée hors transaction aussi).

- [ ] **Step 1: Tests**

```php
public function test_parse_french_inputs(): void
    // "150 000" → 15000000 ; "150000" → 15000000 ; "150 000,50" → 15000050 ; "1.25" → 125 ; " 2 500 " (espace insécable) → 250000
public function test_parse_rejects_scientific_negative_empty_and_three_decimals(): void // "1.5e3", "-500", "", "10,123"
public function test_format_uses_narrow_nbsp_and_hides_zero_cents(): void   // 15000000 → "150\u{202F}000 XOF"
public function test_decimal_roundtrip(): void                              // toDecimal(fromDecimal("150000.50")) === "150000.50"
public function test_sequence_increments_per_name_and_year(): void          // DEC-2026-0001, DEC-2026-0002, DEC-2027-0001
public function test_sequence_is_unique_across_two_connections(): void      // deux connexions PDO, 50 appels alternés → 100 valeurs distinctes
public function test_validator_amount_rule_uses_money_parse(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: montants en centimes et références uniques`

### Task 9: Paramètres administrables

**Files:** Create `app/Core/Settings.php` · Test `tests/Unit/SettingsTest.php`

**Interfaces:**
- Produces: `new Settings(Database $db)` ; `define(string $group, string $key, mixed $default = null): void` (crée la ligne en statut `non_configure` si absente, sans écraser) ; `get(string $group, string $key): mixed` (valeur JSON décodée, quel que soit le statut) ; `require(string $group, string $key): mixed` → `SettingNotValidated` (exception, message : « Ce paramètre n'a pas encore été validé par les instances de la Mutuelle : {group}.{key}. ») si statut `non_configure` ; `validate(string $group, string $key, mixed $value, int $userId, string $decisionRef): void` (statut `valide`, `validated_by`, `validated_at`, `decision_ref` obligatoire non vide) ; `isValidated(string $group, string $key): bool`.

- [ ] **Step 1: Tests**

```php
public function test_define_does_not_overwrite_existing_value(): void
public function test_require_throws_until_validated(): void
public function test_validate_requires_decision_reference(): void     // '' → InvalidArgumentException
public function test_value_roundtrips_as_json(): void                 // ['montant' => 1000000, 'periodicite' => 'mensuelle']
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: paramètres soumis à validation des instances`

### Task 10: Journal d'audit en ajout seul

**Files:** Create `app/Core/Audit.php` · Test `tests/Unit/AuditTest.php`

**Interfaces:**
- Produces: `new Audit(Database $db, string $appKey)` ; `log(?int $userId, string $action, string $type, ?int $id, ?array $old, ?array $new, ?string $reference = null, ?string $ip = null, ?string $ua = null): int` — calcule `hash = hash_hmac('sha256', prev_hash . json_encode(champs, JSON_UNESCAPED_UNICODE), $appKey)` en relisant la dernière ligne `FOR UPDATE` dans une transaction ; `verify(): ?int` → `null` si la chaîne est intacte, sinon l'`id` de la première ligne altérée. Aucune méthode de modification ou de suppression.

- [ ] **Step 1: Tests**

```php
public function test_chain_links_each_entry_to_previous(): void
public function test_verify_detects_tampered_row(): void        // UPDATE audit_logs SET new_values=… WHERE id=2 → verify() === 2
public function test_verify_detects_deleted_row(): void
public function test_class_exposes_no_update_or_delete_method(): void // réflexion : méthodes publiques === ['__construct','log','verify']
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: journal d'audit chaîné`

### Task 11: Fichiers téléversés

**Files:** Create `app/Core/Uploads.php`, `app/Core/UploadError.php`, `public/media/.htaccess` · Test `tests/Unit/UploadsTest.php` (fixtures générées dans le test : vrai JPEG via GD, vrai PDF minimal, PHP déguisé)

**Interfaces:**
- Produces:
  - `const Uploads::PROFILES = ['image' => ['jpg','jpeg','png','webp'], 'document' => ['pdf','jpg','jpeg','png']]` avec types MIME attendus `image/jpeg|png|webp`, `application/pdf`.
  - `new Uploads(Database $db, string $privateDir, string $publicDir, int $maxBytes)` ; `store(array $phpFile, string $profile, bool $public, ?int $userId): int` (id dans `files`) ; `path(int $fileId): string` ; `UploadError extends RuntimeException` avec messages : `UPLOAD_ERR_INI_SIZE`/`FORM_SIZE` ou taille > max → « Fichier trop volumineux : 5 Mo au maximum. Réduisez la photo ou numérisez en qualité moyenne. » ; vide → « Le fichier est vide. » ; type refusé → « Format non accepté : PDF, JPG ou PNG uniquement. »
  - Règles : extension en minuscules issue du **dernier** point ; MIME réel via `finfo` doit correspondre à l'extension ; images ré-encodées avec GD (supprime EXIF et contenu caché) ; nom stocké `AAAA/MM/<32 hex>.<ext>` ; SHA-256 enregistré ; `public/media/.htaccess` interdit l'exécution (`php_flag engine off` + `RemoveHandler .php .phtml .phar` + `<FilesMatch "\.(php|phtml|phar)$">Require all denied</FilesMatch>`).

- [ ] **Step 1: Tests**

```php
public function test_uppercase_extension_and_multiple_dots_are_accepted(): void // "PHOTO.JPG", "piece.identite.pdf"
public function test_php_disguised_as_jpg_is_refused(): void                    // "<?php …" nommé x.jpg → UploadError format
public function test_double_extension_php_is_refused(): void                    // "x.jpg.php"
public function test_svg_is_refused(): void
public function test_empty_file_message(): void
public function test_ini_size_error_message_is_french_and_actionable(): void    // error = UPLOAD_ERR_INI_SIZE
public function test_private_file_is_stored_outside_public_dir(): void
public function test_image_is_reencoded_without_exif(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter (`move_uploaded_file` en production ; `rename` autorisé quand un drapeau de test l'indique, via un paramètre constructeur `bool $allowNonUploaded = false`) → PASS. **Step 5: Commit** `L0: téléversements contrôlés`

### Task 12: Nettoyage du contenu riche

**Files:** Create `app/Core/HtmlSanitizer.php` · Test `tests/Unit/HtmlSanitizerTest.php`

**Interfaces:**
- Produces: `HtmlSanitizer::clean(string $html): string` — `DOMDocument::loadHTML` (UTF-8 forcé), conserve uniquement `p, br, h2, h3, h4, strong, em, ul, ol, li, a[href,title], img[src,alt,width,height], blockquote, table, thead, tbody, tr, th, td` ; supprime tout autre élément **avec son contenu** pour `script, style, iframe, object, embed, form` et en conservant le texte pour les autres ; `href`/`src` acceptés seulement en `https:`, `http:`, `mailto:`, `tel:` ou chemin relatif commençant par `/` ; ajoute `rel="noopener"` aux liens externes ; retire tout attribut `on*` et `style`.
- (L'éditeur `contenteditable` côté navigateur est déplacé en T3.9 avec le reste du CMS : il dépend du design system.)

- [ ] **Step 1: Tests**

```php
public function test_script_and_event_handlers_are_removed(): void   // '<p onclick="x()">a<script>b</script></p>' → '<p>a</p>'
public function test_javascript_and_data_urls_are_removed(): void
public function test_allowed_markup_and_accents_survive(): void      // '<h2>Fonds de solidarité</h2><p>N\'Guessan</p>'
public function test_unknown_tag_keeps_text(): void                  // '<span>texte</span>' → 'texte'
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: nettoyage HTML par liste blanche`

### Task 13: E-mail

**Files:** Create `app/Core/Mailer.php` · Test `tests/Unit/MailerTest.php`

**Interfaces:**
- Produces: `interface Transport { public function send(string $to, string $subject, string $body, array $headers): void; }` dans `Mailer.php` avec `MailTransport` (`mail()`), `SmtpTransport` (socket `stream_socket_client`, `STARTTLS`, `AUTH LOGIN`), `ArrayTransport` (tests, garde les messages) ; `new Mailer(Transport $t, string $from)` ; `send(string $to, string $subject, string $textBody): void` — texte brut UTF-8, sujet encodé `=?UTF-8?B?…?=`, refuse un `$to` contenant `\r` ou `\n` (injection d'en-têtes) ; `Mailer::fromConfig(Config $c): Mailer` choisit le transport selon `MAIL_DRIVER`.

- [ ] **Step 1: Tests**

```php
public function test_subject_is_utf8_encoded(): void        // "Reçu de cotisation" encodé, décodable
public function test_header_injection_in_recipient_is_refused(): void
public function test_array_transport_records_message(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter (SMTP non testé automatiquement : vérification manuelle documentée dans le README avec un serveur SMTP de test) → PASS. **Step 5: Commit** `L0: envoi d'e-mails`

### Task 14: Tâches planifiées (cron)

**Files:** Create `app/Core/Scheduler.php`, `bin/cron.php` · Test `tests/Unit/SchedulerTest.php`

**Interfaces:**
- Produces: `new Scheduler(Database $db)` ; `register(string $name, int $everyMinutes, callable $task): void` ; `run(int $now): array<string,string>` (nom → `ran` | `skipped_not_due` | `skipped_locked` | `failed`) ; une tâche est due si sa dernière exécution réussie date de plus de `$everyMinutes` ; verrou = ligne `scheduled_runs` en statut `running` ; un verrou de plus de **30 minutes** est considéré abandonné et repris ; une exception dans une tâche → statut `failed`, message journalisé, les autres tâches continuent. `bin/cron.php` construit l'application et appelle `run(time())` ; une seule entrée cron cPanel toutes les 5 minutes.

- [ ] **Step 1: Tests**

```php
public function test_task_runs_when_due_and_skips_otherwise(): void
public function test_concurrent_run_is_skipped_while_locked(): void
public function test_stale_lock_older_than_30_minutes_is_reclaimed(): void
public function test_failing_task_does_not_stop_others(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: planificateur pour cron cPanel`

### Task 15: Exports CSV, XLSX et graphique SVG

**Files:** Create `app/Core/Export/{Csv,Xlsx,SvgBarChart}.php` · Test `tests/Unit/ExportTest.php`

**Interfaces:**
- Produces:
  - `Csv::build(array $headers, iterable $rows): string` — BOM UTF-8, séparateur `;` (Excel en paramètres régionaux français), guillemets échappés, cellules commençant par `= + - @` préfixées d'une apostrophe (injection de formule).
  - `Xlsx::build(string $sheetName, array $headers, iterable $rows): string` (contenu binaire) — classeur minimal via `ZipArchive` dans un fichier temporaire : `[Content_Types].xml`, `_rels/.rels`, `xl/workbook.xml`, `xl/_rels/workbook.xml.rels`, `xl/worksheets/sheet1.xml` (chaînes en `inlineStr`, nombres en `n`).
  - `SvgBarChart::render(array $labels, array $series, string $title): string` — `<svg role="img" aria-labelledby>` avec `<title>`, barres empilées, couleurs `#1A3F86` et `#D2AE55` (jetons du design system), accompagné d'un `<table>` de données dans la même sortie (accessibilité, `05-design-system.md`).

- [ ] **Step 1: Tests**

```php
public function test_csv_has_bom_semicolon_and_keeps_accents(): void      // "N'Guessan;Kouamé"
public function test_csv_neutralizes_formula_injection(): void            // "=HYPERLINK(...)" → "'=HYPERLINK(...)"
public function test_xlsx_is_a_valid_zip_with_sheet_and_values(): void    // ZipArchive ouvre, sheet1.xml contient "Kouamé" et <v>150000</v>
public function test_svg_chart_has_title_and_data_table(): void
```

- [ ] **Steps 2–4:** FAIL → implémenter → PASS. **Step 5: Commit** `L0: exports CSV, XLSX et graphiques SVG`

### Task 16: Assemblage et vérification de bout en bout

**Files:** Modify `app/bootstrap.php`, `routes/web.php`, `app/views/home.php`, `README.md` · Test `tests/Feature/SmokeTest.php`

**Interfaces:**
- Consumes: tout ce qui précède.
- Produces: `app/bootstrap.php` retourne une `App` câblée : middleware globaux dans cet ordre `ForceHttps`, `SecurityHeaders`, démarrage de session, `VerifyCsrf` ; route `GET /` rendant `home.php` (« Socle MMMGCI opérationnel » — page technique remplacée en L4) ; `GET /sante` renvoyant `200` + `ok` si la base répond, `503` sinon (sans détail).

- [ ] **Step 1: Tests**

```php
public function test_home_returns_200_with_security_headers_and_csrf_cookie(): void
public function test_health_endpoint_reports_database(): void
public function test_post_to_home_without_token_is_419(): void
```

- [ ] **Step 2:** FAIL → **Step 3:** câbler → **Step 4:** `composer test` → toute la suite PASS.
- [ ] **Step 5: Vérification manuelle** — `php -S 127.0.0.1:8080 -t public` ; `curl -sI http://127.0.0.1:8080/` montre CSP, nosniff, X-Frame-Options ; `curl -s http://127.0.0.1:8080/storage/../.env` ne renvoie pas le fichier ; `php bin/migrate.php` sur une base vide puis une seconde fois (« Aucune migration en attente »).
- [ ] **Step 6:** README : prérequis, installation de dev (MariaDB, `composer install` pour les tests seulement), lancer les tests, lancer le serveur, structure des dossiers.
- [ ] **Step 7: Commit** `L0: assemblage du socle et vérification`

---

## Couverture de la spec (auto-relecture)

| Exigence L0 (`06-plan-taches.md`) | Tâche |
|---|---|
| T0.1 squelette, config, erreurs | 1 |
| T0.2 environnement de test | 1 |
| T0.3 routeur | 2 |
| T0.4 vues et échappement | 3 (messages flash : 6) |
| T0.5 base et migrations | 4 |
| T0.6 validation | 5 (+ `amount` en 8) |
| T0.7 session et CSRF | 6 |
| T0.8 en-têtes et HTTPS | 7 |
| T0.9 fichiers | 11 |
| T0.10 montants et références | 8 |
| T0.11 paramètres | 9 |
| T0.12 audit | 10 |
| T0.13 e-mail | 13 |
| T0.14 cron | 14 |
| T0.15 exports (pages imprimables : gabarits en T2.5) | 15 |
| T0.16 nettoyage HTML (éditeur JS déplacé en T3.9) | 12 |
