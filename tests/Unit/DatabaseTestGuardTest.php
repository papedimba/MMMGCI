<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\DatabaseTestCase;

final class DatabaseTestGuardTest extends TestCase
{
    public function test_valid_test_database_is_accepted(): void
    {
        DatabaseTestCase::guardTestDatabase('mmmgci_test', 'mmmgci');
        $this->addToAssertionCount(1);
    }

    #[DataProvider('bad')]
    public function test_unsafe_names_are_refused(string $test, string $main): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('.env');
        DatabaseTestCase::guardTestDatabase($test, $main);
    }

    public static function bad(): array
    {
        return [['', 'm'], ['mmmgci', 'mmmgci'], ['m_test', 'm_test'], ['prod', 'm'], ['Bad-test', 'm'], ['a`b_test', 'm']];
    }
}
