<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Database;
use App\Core\Migrator;
use PDO;
use Tests\DatabaseTestCase;

final class EspaceSchemaTest extends DatabaseTestCase
{
    /** @param list<int> $codes erreurs attendues (par défaut CHECK : 4025 MariaDB, 3819 MySQL) */
    private function expectSqlError(string $sql, array $params = [], array $codes = [3819, 4025]): void
    {
        try {
            $this->db->query($sql, $params);
        } catch (\PDOException $e) {
            self::assertContains((int) ($e->errorInfo[1] ?? 0), $codes, $e->getMessage());
            return;
        }
        $this->fail('Contrainte attendue : ' . $sql);
    }

    private function insert(string $sql, array $params): int
    {
        $this->db->query($sql, $params);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /** @return array{user:int,member:int,account:int,type:int} */
    private function fixtures(): array
    {
        $n = bin2hex(random_bytes(4));
        $u = $this->insert('INSERT INTO users (name, email, password) VALUES (?, ?, ?)', ["U $n", "u$n@example.test", 'x']);
        $m = $this->insert('INSERT INTO members (member_number, last_name, first_names) VALUES (?, ?, ?)', ["E-$n", 'Test', 'Espace']);
        $a = $this->insert('INSERT INTO member_accounts (member_id, created_by) VALUES (?, ?)', [$m, $u]);
        $t = (int) $this->db->fetchValue("SELECT id FROM social_request_types WHERE code = 'urgences'");
        return ['user' => $u, 'member' => $m, 'account' => $a, 'type' => $t];
    }

    private function request(array $f, string $channel, ?int $user, ?int $account): int
    {
        return $this->insert("INSERT INTO social_requests (reference, member_id, type_id, received_at, channel, description, created_by, created_by_account_id) VALUES (?, ?, ?, '2026-01-05', ?, 'd', ?, ?)",
            ['AS-' . bin2hex(random_bytes(5)), $f['member'], $f['type'], $channel, $user, $account]);
    }

    public function test_migrations_apply_on_fresh_database(): void
    {
        foreach (['member_accounts', 'member_activation_codes', 'member_change_requests', 'member_messages'] as $t) {
            $this->assertSame($t, $this->db->fetchValue("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$t]));
        }
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM schema_migrations WHERE version IN ('0028_member_accounts.sql', '0029_espace_permissions.sql')"));
        $f = $this->fixtures();
        $row = $this->db->fetchAll('SELECT password, status, session_version FROM member_accounts WHERE id = ?', [$f['account']])[0];
        $this->assertSame([null, 'en_attente', 0], array_values($row));
    }

    public function test_one_account_per_member(): void
    {
        $f = $this->fixtures();
        $this->expectSqlError('INSERT INTO member_accounts (member_id, created_by) VALUES (?, ?)', [$f['member'], $f['user']], [1062]);
    }

    public function test_code_hash_is_unique(): void
    {
        $f = $this->fixtures();
        $sql = "INSERT INTO member_activation_codes (member_account_id, code_hash, purpose, delivery, expires_at) VALUES (?, ?, 'oubli', 'email', '2030-01-01 00:00:00')";
        $h = hash('sha256', random_bytes(8));
        $this->db->query($sql, [$f['account'], $h]);
        $this->expectSqlError($sql, [$f['account'], $h], [1062]);
    }

    public function test_status_purpose_delivery_and_kind_checks_reject_unknown_values(): void
    {
        $f = $this->fixtures();
        $this->expectSqlError("UPDATE member_accounts SET status = 'verrouille' WHERE id = ?", [$f['account']]);
        $code = 'INSERT INTO member_activation_codes (member_account_id, code_hash, purpose, delivery, expires_at) VALUES (?, ?, ?, ?, \'2030-01-01 00:00:00\')';
        $this->expectSqlError($code, [$f['account'], hash('sha256', 'a'), 'autre', 'email']);
        $this->expectSqlError($code, [$f['account'], hash('sha256', 'b'), 'activation', 'sms']);
        $this->expectSqlError("INSERT INTO member_change_requests (member_id, member_account_id, fields, status) VALUES (?, ?, '{}', 'x')", [$f['member'], $f['account']]);
        $this->expectSqlError("INSERT INTO member_messages (member_id, kind, label, link) VALUES (?, 'libre', 'L', '/espace')", [$f['member']]);
        $this->db->query("INSERT INTO member_messages (member_id, kind, label, link) VALUES (?, 'demande_avancee', 'Reçue', '/espace/demandes')", [$f['member']]);
    }

    public function test_social_request_accepts_espace_adherent_channel_and_member_author(): void
    {
        $f = $this->fixtures();
        $id = $this->request($f, 'espace_adherent', null, $f['account']);
        $this->assertSame($f['account'], (int) $this->db->fetchValue('SELECT created_by_account_id FROM social_requests WHERE id = ?', [$id]));
        $this->request($f, 'guichet', $f['user'], null);
        $this->expectSqlError("UPDATE social_requests SET channel = 'sms' WHERE id = ?", [$id]);
    }

    public function test_social_request_without_any_author_is_refused(): void
    {
        $f = $this->fixtures();
        $this->expectSqlError("INSERT INTO social_requests (reference, member_id, type_id, received_at, channel, description) VALUES ('AS-X1', ?, ?, '2026-01-05', 'guichet', 'd')", [$f['member'], $f['type']]);
    }

    public function test_document_and_event_need_exactly_or_at_least_one_author(): void
    {
        $f = $this->fixtures();
        $r = $this->request($f, 'espace_adherent', null, $f['account']);
        $file = $this->insert("INSERT INTO files (path, original_name, mime, size, sha256) VALUES (?, 'p.pdf', 'application/pdf', 1, ?)", ['x/' . bin2hex(random_bytes(4)), hash('sha256', random_bytes(4))]);
        $doc = 'INSERT INTO social_request_documents (social_request_id, file_id, uploaded_by, uploaded_by_account_id) VALUES (?, ?, ?, ?)';
        $this->db->query($doc, [$r, $file, null, $f['account']]);
        $this->db->query($doc, [$r, $file, $f['user'], null]);
        $this->expectSqlError($doc, [$r, $file, null, null]);
        $this->expectSqlError($doc, [$r, $file, $f['user'], $f['account']]);
        $ev = "INSERT INTO social_request_events (social_request_id, to_status, action, user_id, member_account_id) VALUES (?, 'recue', 'creation', ?, ?)";
        $this->db->query($ev, [$r, null, $f['account']]);
        $this->db->query($ev, [$r, $f['user'], null]);
        $this->expectSqlError($ev, [$r, null, null]);
    }

    public function test_existing_social_rows_survive_migration(): void
    {
        $c = self::config();
        $name = (string) $c->get('DB_TEST_NAME');
        self::guardTestDatabase($name, (string) $c->get('DB_NAME'));
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c->get('DB_HOST', '127.0.0.1'), $c->get('DB_PORT', '3306')),
            (string) $c->get('DB_USER'), (string) $c->get('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $old = sys_get_temp_dir() . '/mig27-' . bin2hex(random_bytes(4));
        mkdir($old);
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') as $file) {
            if ((int) basename($file) <= 27) {
                copy($file, $old . '/' . basename($file));
            }
        }
        try {
            $db = Database::connect($c, test: true);
            (new Migrator($db, $old))->migrate();
            $db->query("INSERT INTO users (name, email, password) VALUES ('U', 'old@example.test', 'x')");
            $u = (int) $db->pdo()->lastInsertId();
            $db->query("INSERT INTO members (member_number, last_name, first_names) VALUES ('OLD-1', 'Vieux', 'Marin')");
            $m = (int) $db->pdo()->lastInsertId();
            $t = (int) $db->fetchValue("SELECT id FROM social_request_types WHERE code = 'sante'");
            $db->query("INSERT INTO social_requests (reference, member_id, type_id, received_at, channel, description, created_by) VALUES ('AS-OLD-1', ?, ?, '2026-01-05', 'courrier', 'ancienne', ?)", [$m, $t, $u]);
            $r = (int) $db->pdo()->lastInsertId();
            $db->query("INSERT INTO social_request_events (social_request_id, to_status, action, user_id) VALUES (?, 'recue', 'creation', ?)", [$r, $u]);
            $db->query("INSERT INTO files (path, original_name, mime, size, sha256) VALUES ('old/p', 'p.pdf', 'application/pdf', 1, ?)", [hash('sha256', 'old')]);
            $db->query('INSERT INTO social_request_documents (social_request_id, file_id, uploaded_by) VALUES (?, ?, ?)', [$r, (int) $db->pdo()->lastInsertId(), $u]);

            $this->assertSame(['0028_member_accounts.sql', '0029_espace_permissions.sql'], (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate());

            $row = $db->fetchAll('SELECT created_by, created_by_account_id, channel FROM social_requests WHERE id = ?', [$r])[0];
            $this->assertSame([$u, null, 'courrier'], array_values($row));
            $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM social_request_events WHERE social_request_id = ? AND user_id = ? AND member_account_id IS NULL', [$r, $u]));
            $this->assertSame(1, (int) $db->fetchValue('SELECT COUNT(*) FROM social_request_documents WHERE social_request_id = ? AND uploaded_by = ? AND uploaded_by_account_id IS NULL', [$r, $u]));
        } finally {
            foreach (glob($old . '/*') as $f) {
                unlink($f);
            }
            rmdir($old);
        }
    }

    public function test_espace_settings_are_non_configure_with_technical_defaults(): void
    {
        $rows = [];
        foreach ($this->db->fetchAll("SELECT `key`, `value`, status FROM settings WHERE `group` = 'espace'") as $r) {
            $rows[$r['key']] = $r;
        }
        ksort($rows);
        $this->assertSame(['activation_code_days', 'depot_demande_en_ligne', 'status_access'], array_keys($rows));
        $this->assertSame(['actif' => 'complet', 'suspendu' => 'lecture', 'radie' => 'desactive', 'decede' => 'desactive'], json_decode($rows['status_access']['value'], true));
        $this->assertSame(14, json_decode($rows['activation_code_days']['value'], true));
        $this->assertNull($rows['depot_demande_en_ligne']['value']);
        foreach ($rows as $r) {
            $this->assertSame('non_configure', $r['status']);
        }
    }
}
