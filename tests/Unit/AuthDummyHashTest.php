<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\Auth;
use App\Modules\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class AuthDummyHashTest extends TestCase
{
    public function test_dummy_hash_matches_the_real_hash_algorithm_and_cost(): void
    {
        $this->assertSame(password_get_info(PasswordPolicy::hash('x')), password_get_info(Auth::dummyHash()));
        $this->assertSame(Auth::dummyHash(), Auth::dummyHash(), 'constante précalculée, jamais recalculée');
    }
}
