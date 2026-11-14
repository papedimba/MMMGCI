<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

final class Sequence
{
    public function __construct(private Database $db)
    {
    }

    public function next(string $name, string $prefix, int $pad, ?int $year = null): string
    {
        $year ??= (int) (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->format('Y');
        $run = function (Database $db) use ($name, $year): int {
            $db->query('INSERT INTO sequences (name, year, last_value) VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE last_value = last_value + 1', [$name, $year]);
            // Lecture sous le verrou de ligne pris par l'INSERT (LAST_INSERT_ID() renverrait l'id auto-incrémenté à la création).
            return (int) $db->fetchValue('SELECT last_value FROM sequences WHERE name = ? AND year = ?', [$name, $year]);
        };
        // Pas de transaction imbriquée : on se joint à celle de l'appelant.
        $n = $this->db->pdo()->inTransaction() ? $run($this->db) : $this->db->transaction($run);
        return sprintf('%s-%d-%s', $prefix, $year, str_pad((string) $n, $pad, '0', STR_PAD_LEFT));
    }
}
