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
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null');
        $log = (string) @file_get_contents($dir . '/app-' . date('Y-m-d') . '.log');
        @unlink($script);
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);

        $this->assertStringContainsString('Une erreur est survenue', (string) $out);
        $this->assertStringNotContainsString('secret-detail', (string) $out);
        $this->assertStringNotContainsString('#0', (string) $out);
        $this->assertStringContainsString('secret-detail', $log);
    }

    public function test_log_write_failure_falls_back_to_error_log(): void
    {
        $base = sys_get_temp_dir() . '/mmmgci-eh-' . uniqid();
        $blocker = $base . '-file';
        $errLog = $base . '-err.log';
        $script = $base . '.php';
        file_put_contents($blocker, 'x');
        file_put_contents($script, '<?php ini_set("error_log", ' . var_export($errLog, true) . '); require '
            . var_export(dirname(__DIR__, 2) . '/app/bootstrap.php', true)
            . '; App\Core\ErrorHandler::register(false, ' . var_export($blocker . '/app.log', true)
            . '); throw new RuntimeException("fallback-detail");');
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>/dev/null');
        $err = (string) @file_get_contents($errLog);
        array_map('unlink', array_filter([$blocker, $errLog, $script], 'is_file'));

        $this->assertStringContainsString('Une erreur est survenue', $out);
        $this->assertStringContainsString('fallback-detail', $err);
    }

    public function test_fatal_error_is_logged_and_renders_500_without_details(): void
    {
        $base = sys_get_temp_dir() . '/mmmgci-eh-' . uniqid();
        $broken = $base . '-broken.php';
        $script = $base . '.php';
        // Classe incomplète chargée à l'exécution : E_COMPILE_ERROR, non capturable par set_exception_handler.
        file_put_contents($broken, '<?php interface FatalI { function fatalDetail(); } class FatalC implements FatalI {}');
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/app/bootstrap.php', true)
            . '; App\Core\ErrorHandler::register(false, ' . var_export($base . '/app.log', true)
            . '); require ' . var_export($broken, true) . ';');
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg($script) . ' 2>/dev/null');
        $log = (string) @file_get_contents($base . '/app-' . date('Y-m-d') . '.log');
        array_map('unlink', array_filter([$broken, $script, ...(glob($base . '/*') ?: [])], 'is_file'));
        @rmdir($base);

        $this->assertStringContainsString('Une erreur est survenue', $out);
        $this->assertStringNotContainsString('fatalDetail', $out);
        $this->assertStringContainsString('fatalDetail', $log);
    }
}
