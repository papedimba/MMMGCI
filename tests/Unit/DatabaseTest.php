<?php
declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use RuntimeException;
use Tests\DatabaseTestCase;

final class DatabaseTest extends DatabaseTestCase
{
    public function test_insert_and_fetch_roundtrip_utf8mb4(): void
    {
        $id = $this->db->insert('files', ['path' => 'a', 'original_name' => "N'Guessan Kouamé 🌊", 'mime' => 'text/plain', 'size' => 1, 'sha256' => str_repeat('a', 64)]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame("N'Guessan Kouamé 🌊", $this->db->fetchValue('SELECT original_name FROM files WHERE id = ?', [$id]));
        $this->assertSame(1, $this->db->update('files', ['size' => 2], ['id' => $id]));
        $this->assertSame(2, (int) $this->db->fetch('SELECT size FROM files WHERE id = ?', [$id])['size']);
    }

    public function test_transaction_rolls_back_on_exception(): void
    {
        try {
            $this->db->transaction(function ($db) {
                $db->insert('sequences', ['name' => 'rb', 'year' => 2026]);
                throw new RuntimeException('boom');
            });
            $this->fail('exception attendue');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM sequences WHERE name = 'rb'"));
    }

    public function test_identifier_injection_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->db->insert('files; DROP TABLE x', ['a' => 1]);
    }
}
