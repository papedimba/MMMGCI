<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Migrator;
use RuntimeException;
use Tests\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/mig' . uniqid();
        mkdir($this->dir);
        $this->db->query('DROP TABLE IF EXISTS a');
        $this->db->query("DELETE FROM schema_migrations WHERE version NOT LIKE '0001_core%'");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function test_migrate_applies_once_then_nothing_pending(): void
    {
        file_put_contents($this->dir . '/0900_x.sql', "-- c\nCREATE TABLE a (id INT);\n\n");
        $m = new Migrator($this->db, $this->dir);
        $this->assertSame(['0900_x.sql'], $m->pending());
        $this->assertSame(['0900_x.sql'], $m->migrate());
        $this->assertSame([], $m->pending());
        $this->assertSame([], $m->migrate());
    }

    public function test_modified_applied_migration_is_detected(): void
    {
        file_put_contents($this->dir . '/0900_x.sql', 'CREATE TABLE a (id INT);');
        (new Migrator($this->db, $this->dir))->migrate();
        file_put_contents($this->dir . '/0900_x.sql', 'CREATE TABLE a (id BIGINT);');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('0900_x.sql a été modifiée');
        (new Migrator($this->db, $this->dir))->pending();
    }

    public function test_failed_migration_is_not_marked_and_rerun_resumes(): void
    {
        file_put_contents($this->dir . '/0900_ok.sql', 'SELECT 1;');
        file_put_contents($this->dir . '/0901_bad.sql', "CREATE TABLE a (id INT);\nINVALID SQL;\n");
        $m = new Migrator($this->db, $this->dir);
        try {
            $m->migrate();
            $this->fail('exception attendue');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('0901', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM schema_migrations WHERE version = '0901_bad.sql'"));
        file_put_contents($this->dir . '/0901_bad.sql', "DROP TABLE IF EXISTS a;\nCREATE TABLE a (id INT);\n");
        $this->assertSame(['0901_bad.sql'], $m->migrate());
    }
}
