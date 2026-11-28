<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Espace\ActivationCode;
use PHPUnit\Framework\TestCase;

final class ActivationCodeTest extends TestCase
{
    public function test_code_alphabet_length_and_format(): void
    {
        $seen = [];
        for ($i = 0; $i < 300; $i++) {
            $code = ActivationCode::generate();
            $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{12}$/D', $code);
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/D', ActivationCode::format($code));
            $this->assertSame($code, ActivationCode::normalize(ActivationCode::format($code)));
            $seen += array_flip(str_split($code));
        }
        foreach (['0', 'O', '1', 'I', 'L'] as $ambiguous) {
            $this->assertArrayNotHasKey($ambiguous, $seen);
        }
        $this->assertGreaterThan(25, count($seen), 'tout l\'alphabet est tiré');
    }

    public function test_code_normalization_ignores_case_dashes_and_spaces(): void
    {
        $key = str_repeat('k', 32);
        $this->assertSame('ABCDEFGHJKMN', ActivationCode::normalize(" abcd-EFGH jkmn\t"));
        $hash = ActivationCode::hash('ABCD-EFGH-JKMN', $key);
        foreach (['abcdefghjkmn', 'ABCD EFGH JKMN', ' abcd-efgh-jkmn '] as $variant) {
            $this->assertSame($hash, ActivationCode::hash($variant, $key), $variant);
        }
        $this->assertSame(hash_hmac('sha256', 'ABCDEFGHJKMN', $key), $hash);
        $this->assertNotSame($hash, ActivationCode::hash('ABCDEFGHJKMN', str_repeat('x', 32)), 'la clé de l\'application entre dans le haché');
        $this->assertNotSame($hash, ActivationCode::hash('ABCDEFGHJKMP', $key));
    }
}
