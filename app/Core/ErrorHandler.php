<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Http\SecurityHeaders;
use Throwable;

final class ErrorHandler
{
    /** Insère la date avant l'extension : app.log -> app-AAAA-MM-JJ.log */
    public static function datedPath(string $logFile): string
    {
        return preg_replace('/(\.[^.\/]+)?$/', '-' . date('Y-m-d') . '$1', $logFile, 1) ?? $logFile;
    }

    public static function register(bool $debug, string $logFile): void
    {
        $log = static function (string $message) use ($logFile): void {
            $file = self::datedPath($logFile);
            $line = '[' . date('c') . '] ' . $message . PHP_EOL;
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
                error_log($message);
            }
        };

        ini_set('zend.exception_ignore_args', '1'); // aucune valeur d'argument (mot de passe…) dans les traces journalisées
        if (!$debug) {
            ini_set('display_errors', '0'); // les fatales sont journalisées et rendues par la fonction d'arrêt
        }
        $render500 = static function () use ($debug): void {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "Une erreur est survenue : le détail est journalisé dans storage/logs ; corrigez la cause puis relancez la commande.\n");
                return;
            }
            if (!headers_sent()) {
                http_response_code(500);
                header_remove('X-Powered-By');
                foreach (SecurityHeaders::HEADERS as $name => $value) {
                    header("$name: $value");
                }
            }
            if (!$debug) {
                require dirname(__DIR__) . '/views/errors/500.php';
            }
        };

        register_shutdown_function(static function () use ($log, $render500): void {
            $e = error_get_last();
            if ($e !== null && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
                $log("Fatal: {$e['message']} in {$e['file']}:{$e['line']}");
                while (ob_get_level() > 0) {
                    ob_end_clean(); // pas de page à moitié rendue avant la page 500
                }
                $render500();
            }
        });

        set_error_handler(static function (int $no, string $str, string $file, int $line) use ($debug, $log): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            if (!$debug && ($no & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
                $log("Deprecated: $str in $file:$line"); // une dépréciation ne doit pas casser la production
                return true;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler(static function (Throwable $e) use ($debug, $log, $render500): void {
            $log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
            $render500();
            if ($debug && PHP_SAPI === 'cli') {
                fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
            } elseif ($debug) {
                echo '<pre>' . e(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
            }
            if (PHP_SAPI === 'cli') {
                exit(1); // sinon le script bin/ (migration, cron) sortirait en 0 et paraîtrait réussi
            }
        });
    }
}
