<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Auth\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function test_short_password_rejected_with_actionable_message(): void
    {
        $this->assertSame('Mot de passe trop court : utilisez au moins 12 caractères (une phrase facile à retenir convient).', PasswordPolicy::check('abcdefghijk'));
    }

    public function test_twelve_accented_characters_accepted(): void
    {
        $this->assertNull(PasswordPolicy::check('été à Abidjan'));
    }

    public function test_common_password_rejected_case_insensitively(): void
    {
        $this->assertStringStartsWith('Mot de passe trop facile', (string) PasswordPolicy::check('AZERTY123456'));
    }

    public function test_password_containing_email_local_part_or_name_rejected(): void
    {
        $this->assertNotNull(PasswordPolicy::check('xx-kouame.yao-xx-99', 'Kouame.Yao@x.test'));
        $this->assertNotNull(PasswordPolicy::check('ma phrase Konaté secrète', 'a@x.test', 'Awa Konaté'));
        $this->assertNull(PasswordPolicy::check('une phrase tout autre 42', 'kouame@x.test', 'Awa Ko'));
    }

    public function test_argon2id_hash_verifies(): void
    {
        $h = PasswordPolicy::hash('une phrase tout autre 42');
        $this->assertTrue(password_verify('une phrase tout autre 42', $h));
        $this->assertFalse(PasswordPolicy::needsRehash($h));
    }
}
