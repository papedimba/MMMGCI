<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    public function test_dated_path_inserts_today_before_extension(): void
    {
        $this->assertSame('storage/logs/app-' . date('Y-m-d') . '.log', ErrorHandler::datedPath('storage/logs/app.log'));
    }

    public function test_uncaught_exception_renders_french_500_and_logs_details(): void
    {
        $dir = sys_get_temp_dir() . '/mmmgci-eh-' . uniqid();
        $script = $dir . '.php';
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/app/bootstrap.php', true)
            . '; App\Core\ErrorHandler::register(false, ' . var_export($dir . '/app.log', true)
            . '); throw new RuntimeException("secret-detail");');
        $out = shell_exec('php ' . escapeshellarg($script) . ' 2>/dev/null');
        $log = (string) @file_get_contents($dir . '/app-' . date('Y-m-d') . '.log');
        @unlink($script);
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);

        $this->assertStringContainsString('Une erreur est survenue', (string) $out);
        $this->assertStringNotContainsString('secret-detail', (string) $out);
        $this->assertStringNotContainsString('#0', (string) $out);
        $this->assertStringContainsString('secret-detail', $log);
    }
}
