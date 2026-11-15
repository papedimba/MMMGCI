<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected Database $db;

    protected static function config(): Config
    {
        return new Config(Env::load(dirname(__DIR__) . '/.env'));
    }

    /** Refuse de supprimer une base qui ne ressemble pas à une base de test dédiée. */
    public static function guardTestDatabase(string $test, string $main): void
    {
        if ($test === '' || !preg_match('/^[a-z0-9_]+$/', $test) || $test === $main || !str_ends_with($test, '_test')) {
            throw new \RuntimeException("DB_TEST_NAME (« $test ») est refusé : il doit être non vide, composé de a-z, 0-9 et _, différent de DB_NAME et se terminer par _test. Corrigez-le dans .env (ex. DB_TEST_NAME=mmmgci_test).");
        }
    }

    /** Base de test recréée et migrée une fois par classe. */
    public static function setUpBeforeClass(): void
    {
        $c = self::config();
        $name = (string) $c->get('DB_TEST_NAME');
        self::guardTestDatabase($name, (string) $c->get('DB_NAME'));
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c->get('DB_HOST', '127.0.0.1'), $c->get('DB_PORT', '3306')),
            (string) $c->get('DB_USER'), (string) $c->get('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        (new Migrator(Database::connect($c, test: true), dirname(__DIR__) . '/database/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        set_time_limit(0); // AuditLogController::verify() pose set_time_limit(60) en cours de processus : on le lève avant chaque test
        $this->db = Database::connect(self::config(), test: true);
    }
}
