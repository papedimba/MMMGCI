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

    /** Base de test recréée et migrée une fois par classe. */
    public static function setUpBeforeClass(): void
    {
        $c = self::config();
        $name = (string) $c->get('DB_TEST_NAME');
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $c->get('DB_HOST', '127.0.0.1'), $c->get('DB_PORT', '3306')),
            (string) $c->get('DB_USER'), (string) $c->get('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("DROP DATABASE IF EXISTS `$name`");
        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        (new Migrator(Database::connect($c, test: true), dirname(__DIR__) . '/database/migrations'))->migrate();
    }

    protected function setUp(): void
    {
        $this->db = Database::connect(self::config(), test: true);
    }
}
