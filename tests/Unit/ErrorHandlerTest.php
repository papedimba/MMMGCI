<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\ErrorHandler;
use PHPUnit\Framework\TestCase;

final class ErrorHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            if (is_dir($path)) {
                array_map('unlink', glob($path . '/*') ?: []);
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * Lance un processus fils CLI qui n'installe que le gestionnaire du test (journal temporaire,
     * jamais storage/logs du dépôt), puis exécute $code.
     *
     * @param list<string> $phpArgs
     * @return array{out:string, err:string, log:string}
     */
    private function child(string $code, string $logDir, array $phpArgs = [], bool $debug = false): array
    {
        $script = $logDir . '.php';
        $this->cleanup[] = $logDir;
        $this->cleanup[] = $script;
        file_put_contents($script, '<?php declare(strict_types=1); require ' . var_export(dirname(__DIR__, 2) . '/app/autoload.php', true)
            . '; App\Core\ErrorHandler::register(' . var_export($debug, true) . ', ' . var_export($logDir . '/app.log', true) . '); ' . $code);
        $proc = proc_open([PHP_BINARY, ...$phpArgs, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        proc_close($proc);
        return ['out' => $out, 'err' => $err, 'log' => (string) @file_get_contents($logDir . '/app-' . date('Y-m-d') . '.log')];
    }

    private static function base(): string
    {
        return sys_get_temp_dir() . '/mmmgci-eh-' . uniqid();
    }

    public function test_dated_path_inserts_today_before_extension(): void
    {
        $this->assertSame('storage/logs/app-' . date('Y-m-d') . '.log', ErrorHandler::datedPath('storage/logs/app.log'));
    }

    public function test_uncaught_exception_in_cli_logs_details_and_prints_one_french_line_to_stderr(): void
    {
        $r = $this->child('throw new RuntimeException("secret-detail");', self::base());

        $this->assertSame('', $r['out']);
        $this->assertStringContainsString('Une erreur est survenue', $r['err']);
        $this->assertSame(1, substr_count(trim($r['err']), "\n") + 1);
        $this->assertStringNotContainsString('secret-detail', $r['err']);
        $this->assertStringNotContainsString('<html', $r['err']);
        $this->assertStringContainsString('secret-detail', $r['log']);
    }

    public function test_log_write_failure_falls_back_to_error_log(): void
    {
        $base = self::base();
        $blocker = $base . '-file';
        $errLog = $base . '-err.log';
        file_put_contents($blocker, 'x');
        $this->cleanup[] = $blocker;
        $this->cleanup[] = $errLog;
        $r = $this->child('throw new RuntimeException("fallback-detail");', $blocker, ['-d', 'error_log=' . $errLog]);

        $this->assertStringContainsString('Une erreur est survenue', $r['err']);
        $this->assertStringContainsString('fallback-detail', (string) @file_get_contents($errLog));
    }

    public function test_fatal_error_is_logged_to_the_given_path_only_and_reported_without_details(): void
    {
        $base = self::base();
        $broken = $base . '-broken.php';
        $this->cleanup[] = $broken;
        $marker = 'fatalDetail' . uniqid();
        // Classe incomplète chargée à l'exécution : E_COMPILE_ERROR, non capturable par set_exception_handler.
        file_put_contents($broken, "<?php interface FatalI { function $marker(); } class FatalC implements FatalI {}");
        $repoLog = dirname(__DIR__, 2) . '/storage/logs/app-' . date('Y-m-d') . '.log';
        $r = $this->child('require ' . var_export($broken, true) . ';', $base, ['-d', 'display_errors=1']);

        $this->assertStringContainsString('Une erreur est survenue', $r['err']);
        $this->assertStringNotContainsString($marker, $r['out']);
        $this->assertStringContainsString($marker, $r['log']);
        $this->assertStringNotContainsString($marker, (string) @file_get_contents($repoLog));
    }

    public function test_logged_stack_traces_never_contain_call_arguments(): void
    {
        $r = $this->child('function login(string $password): void { throw new RuntimeException("echec"); } login("pw-secret-123");',
            self::base(), ['-d', 'zend.exception_ignore_args=0', '-d', 'zend.exception_string_param_max_len=15']);

        $this->assertStringContainsString('login(', $r['log']);
        $this->assertStringNotContainsString('pw-secret-123', $r['log']);
    }

    public function test_deprecations_are_logged_not_thrown_when_not_debug(): void
    {
        $r = $this->child('trigger_error("dep-detail", E_USER_DEPRECATED); echo "suite";', self::base());

        $this->assertSame('suite', $r['out']);
        $this->assertSame('', $r['err']);
        $this->assertStringContainsString('dep-detail', $r['log']);
    }

    public function test_deprecations_still_throw_in_debug(): void
    {
        $r = $this->child('trigger_error("dep-detail", E_USER_DEPRECATED); echo "suite";', self::base(), [], true);

        $this->assertStringNotContainsString('suite', $r['out']);
        $this->assertStringContainsString('dep-detail', $r['log']);
    }
}
