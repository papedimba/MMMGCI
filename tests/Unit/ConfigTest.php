<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Config;
use App\Core\Env;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function test_env_parses_quotes_comments_and_blank_lines(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($tmp, "# c\n\nAPP_NAME=\"MMMGCI\"\nAPP_DEBUG=false\n");
        $this->assertSame(['APP_NAME' => 'MMMGCI', 'APP_DEBUG' => 'false'], Env::load($tmp));
        unlink($tmp);
    }

    public function test_env_missing_file_returns_empty_array(): void
    {
        $this->assertSame([], Env::load('/nexiste/pas/.env'));
    }

    public function test_config_bool_and_default(): void
    {
        $c = new Config(['APP_DEBUG' => 'false', 'X' => 'on']);
        $this->assertFalse($c->bool('APP_DEBUG'));
        $this->assertTrue($c->bool('X'));
        $this->assertSame('d', $c->get('ABSENT', 'd'));
    }
}
