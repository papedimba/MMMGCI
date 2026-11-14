<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use LogicException;
use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private bool $inTransaction = false;

    private function __construct(private PDO $pdo)
    {
    }

    public static function connect(Config $c, bool $test = false): self
    {
        $name = (string) $c->get($test ? 'DB_TEST_NAME' : 'DB_NAME');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $c->get('DB_HOST', '127.0.0.1'), $c->get('DB_PORT', '3306'), $name);
        $pdo = new PDO($dsn, (string) $c->get('DB_USER'), (string) $c->get('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return new self($pdo);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** @param array<int|string,mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<int|string,mixed> $params */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<int|string,mixed> $params */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<int|string,mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $v = $this->query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        $cols = array_map(fn (string $c) => '`' . $this->ident($c) . '`', array_keys($row));
        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $this->ident($table),
            implode(', ', $cols), implode(', ', array_fill(0, count($row), '?')));
        $this->query($sql, array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $where conditions d'égalité combinées par AND (jamais null : `= NULL` ne vaut jamais vrai)
     * @return int lignes réellement modifiées (une ligne déjà à la bonne valeur ne compte pas)
     */
    public function update(string $table, array $row, array $where): int
    {
        if ($row === [] || $where === []) {
            throw new InvalidArgumentException('update() exige des valeurs et une condition.');
        }
        if (in_array(null, $where, true)) {
            throw new InvalidArgumentException('update() : une condition à null ne correspond à aucune ligne ; écrivez la requête avec IS NULL via query().');
        }
        $set = implode(', ', array_map(fn (string $c) => '`' . $this->ident($c) . '` = ?', array_keys($row)));
        $cond = implode(' AND ', array_map(fn (string $c) => '`' . $this->ident($c) . '` = ?', array_keys($where)));
        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $this->ident($table), $set, $cond);
        return $this->query($sql, [...array_values($row), ...array_values($where)])->rowCount();
    }

    /**
     * Ouvre une transaction (refusée si une transaction est déjà ouverte, y compris via pdo()).
     * Ne jamais avaler une exception dans $fn pour valider quand même : laisser remonter, la transaction est annulée.
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->inTransaction || $this->pdo->inTransaction()) {
            throw new LogicException('Les transactions imbriquées sont interdites.');
        }
        $this->pdo->beginTransaction();
        $this->inTransaction = true;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->inTransaction = false;
        }
    }

    /**
     * Exécute $fn($this) dans la transaction ouverte s'il y en a une (validée ou annulée par l'appelant), sinon dans une nouvelle.
     * Ne jamais avaler une exception à l'intérieur pour valider quand même : elle doit remonter jusqu'au propriétaire de la transaction.
     */
    public function atomic(callable $fn): mixed
    {
        return $this->pdo->inTransaction() ? $fn($this) : $this->transaction($fn);
    }

    private function ident(string $name): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException("Identifiant SQL invalide : $name");
        }
        return $name;
    }
}
