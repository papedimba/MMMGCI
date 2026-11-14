# Lot L0 — décisions prises et points reportés

Extrait du registre d'exécution (14/11/2026). Commits f595db1..b9a9e2a.

## Décisions (rulings)
- Ruling: DatabaseTestCase moves from T1 to T4 (T1 only creates tests/bootstrap.php) — T1 cannot use a class that does not exist yet — cost if wrong: none.
- Ruling: Router takes an optional `callable(int $status, Request $r): Response $errorRenderer`; default renders a minimal French text page ("Page introuvable" for 404); T16 wires a View-based renderer — keeps T2 independent of T3 — cost if wrong: small rewire in T16.
- Ruling: T1 adds `bin/dev-db` (bash) that starts MariaDB if not running and creates the test user/db idempotently; every DB task runs it first — shells are not persistent across subagents — cost: none.
- Ruling: reserved column names `group`, `key` kept (spec) and always backticked in SQL — cost if wrong: rename later.
- Ruling: subagent commit trailer names its own model (Sonnet) — accurate attribution, no history rewrite.
- Ruling: plan's LAST_INSERT_ID(last_value+1) is a plan defect (first insert returns auto-increment id); accept INSERT…ODKU + SELECT inside a transaction (own or joined) — cost if wrong: duplicate references; reviewer to check atomicity.
- Ruling: add chain-head row table `audit_chain` (single row id=1: last_id, last_hash, count) in NEW migration 0002_audit_chain.sql, locked FOR UPDATE by log() — serialises under any isolation level and anchors tail deletion — cost: one hot row (acceptable at 500–2000 members).
- Ruling: 0002 also ALTERs audit_logs.old_values/new_values to LONGTEXT with CHECK (JSON_VALID(...)) instead of editing 0001 — nothing is deployed yet; checksum rule forbids editing 0001.
- Ruling: editing 0002 before any deployment is acceptable; if the dev DB (mmmgci) applied the old 0002, drop/recreate the dev DB (no real data) — carry to Task 16
- Ruling: plan-mandated `php_flag` line is wrapped in <IfModule mod_php.c>/<IfModule mod_php7.c> — spec requires cPanel compatibility, unguarded directive breaks FPM hosts — cost: none.
- Ruling: global constraint (problem + correction) overrides the brief's exact « Le fichier est vide. » → « Le fichier est vide : choisissez un autre fichier. »
- Ruling: Uploads insert joins an open transaction (like Sequence) instead of always opening one.
- Ruling: allow `#fragment` hrefs (CMS anchors; no scheme, safe) — extends the brief's URL list — cost: none.
- Ruling: nesting deeper than libxml's 256 limit is accepted as a documented limit (fails closed) — not worth custom handling.
- Ruling: Task 13 transports split one-per-file in app/Core/Mail/ (autoloader is one class per file) — cost: none. Task 13 dispatched (base 60c6a24, agent ab306c91c211b3c15)
- Ruling: SmtpTransport $requireTls opt-out accepted only as a test seam — fromConfig must never pass false (no env/config path) — reviewer to verify
- Ruling: fix all 4 now (cheap) + minors 5–9, 10a, 12 in ONE fix wave.
- Ruling: /.well-known excluded from the root dotfile block (cPanel AutoSSL needs it) — cost: none. Ruling: Scheduler::lock keeps transaction() (lock must commit on its own).
- Ruling: apply the one-line fix (exit(1) in CLI exception handler + test assertion) via the same fix agent rather than parking it — load-bearing for migrate/cron exit codes, trivially verifiable by running the suite — deviation from 'no second fix wave', cost: one small extra dispatch.
- Ruling: PDF stockés tels quels (servis en pièce jointe avec nosniff).
- Ruling: verrou du planificateur dans une table scheduler_locks (et non une ligne 'running' de scheduled_runs).

## Points mineurs reportés (à traiter dans les lots indiqués)
- Task 1: minor (deferred): ErrorHandler @mkdir/@file_put_contents silent failure → fallback error_log()
- Task 1: minor (deferred): no register_shutdown_function for fatal errors (consider in Task 16)
- Task 1: minor (deferred): stray newline before doctype in errors/500.php
- Task 1: minor (deferred): .gitignore /.superpowers/ is intended (controller ruling) — no action
- Task 2: minor (deferred): HEAD → 405; Request $path property placement; [object,'method'] handlers; App middleware typing inconsistency
- Task 2: minor (deferred): RouterTest leaks $_SERVER['REQUEST_URI']; absolute-form REQUEST_URI 404s; route params not UTF-8 validated (handlers must validate)
- Task 3: minor (deferred): error pages lack per-page $title (Task 16 wiring)
- Task 4: minor (deferred): Database::update NULL in WHERE never matches + rowCount semantics (docblock); pending() creates table (side effect); no lock on concurrent migrate; connect() raw PDOException on missing settings
- Task 4: minor (deferred): Migrator does not detect a deleted applied migration file
- Task 5: minor (deferred): max:abc → 0; integer beyond PHP_INT_MAX; validated() includes failed fields (callers must check fails())
- Task 5: minor (deferred): test_unknown_rule_throws_even_for_array_input uses max:abc, not an unknown name (code path verified by reviewer via php -r); normalize() redundant null check
- Task 6: minor (deferred → carry to Task 16): VerifyCsrf allow-list === 'POST' → should check all non-safe methods; session middleware must call isExpired()+touch() each request; Session ctor needs a local [] (not unset $_SESSION) before start(); invalidate() should expire the cookie; _csrf accepted from query string
- Task 7: minor (deferred → Task 16 / L13 deploy): isSecure() ignores X-Forwarded-Proto (proxy TLS → redirect loop); queryString() re-encodes instead of raw QUERY_STRING; case-sensitive header names
- Task 8: minor (deferred): fromDecimal rejects negatives (asymmetric with toDecimal) — must fix before any signed DECIMAL column (L6); fromDecimal regex lacks D; parse strips inner spaces anywhere; amount must be last rule (later rules see ''); two-connection test is sequential; no default-year test
- Task 9: minor (deferred): validate() with null value marks valide; validate() keeps no history (audit to be wired by callers/L1); undefined-key test covers get() only
- Task 10: minor (deferred → docs L13): audit_chain has no MAC (DB-write attacker can rewind tail) — document; audited writes serialise on audit_chain (call log() last in long transactions); action/auditable_type not length-checked
- Task 10: minor (deferred): rows above audit_chain.last_id unchecked until next log(); stderr read after child exit
- Task 11: minor (deferred): PNG lossless re-encode can carry pixel payload (names random, exec denied)
- Task 11: minor (deferred): joined-transaction branch untested; rolled-back caller leaves orphan file (future cleanup job); $allowNonUploaded must be hard-coded false when wired (carry to Task 16/L3)
- Task 12: minor (deferred): empty li/td re-serialisation not idempotent
- Task 12: minor (deferred): </body>/</html> stripped from raw string also alters attribute/text data; NUL truncates input (fails closed)
- Task 13: minor (deferred): port 465 implicit TLS unsupported (document); empty body loses header/body separator
- Task 13: minor (deferred → Task 16 README): read-timeout message lacks 465/SMTP_PORT hint; README must say SMTP_USER requires STARTTLS and 465 unsupported
- Task 14: minor (deferred → L1): failing task re-runs every 5 min (no backoff); finished_at uses time() vs $now
- Task 14: minor (deferred): run() inside a transaction throws (docblock line); test DB user needs TRIGGER privilege
- Task 15: minor (deferred): exports held in memory (XLSX 200k rows ≈ 87 MB) — streaming later if needed; >2 series colour cycling; duplicate chart ids for same title; sheet-name edge rules; 32767-char cells
- Task 15: minor (deferred → Task 16): Csv.php shortcut comment carries XLSX figure; temp-file cleanup test does not reach finally
- Task 16: minor (deferred): ForceHttps responses bypass SecurityHeaders (plan order); class factory nullable params
- Task 16: minor (deferred): app handlers installed for the whole PHPUnit run via tests/bootstrap.php; fatal before handler registration in bootstrap/Env/Config uncaught (tiny window)
