<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Members\MemberService;
use PHPUnit\Framework\TestCase;

final class DuplicateDetectionTest extends TestCase
{
    public function test_phone_normalisation_without_any_extension(): void
    {
        $this->assertSame('0712345678', MemberService::normalizePhone(' 07.12 34-56 78 '));
        $this->assertSame('0712345678', MemberService::normalizePhone('+225 0712345678'));
        $this->assertSame('+33612345678', MemberService::normalizePhone('0033 6 12 34 56 78'));
        $this->assertNull(MemberService::normalizePhone('abc'));
        $this->assertNull(MemberService::normalizePhone(null));
    }
}
