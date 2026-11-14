<?php
declare(strict_types=1);

namespace App\Core;

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

        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });

        set_exception_handler(static function (Throwable $e) use ($debug, $log): void {
            $log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
            if (!headers_sent()) {
                http_response_code(500);
            }
            if ($debug) {
                echo '<pre>' . e(get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
                return;
            }
            require dirname(__DIR__) . '/views/errors/500.php';
        });
    }
}
