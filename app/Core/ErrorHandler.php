<?php
declare(strict_types=1);

namespace App\Core;

use Throwable;

final class ErrorHandler
{
    public static function register(bool $debug, string $logFile): void
    {
        // $logFile est un chemin de base : la date est insérée avant l'extension (app.log -> app-AAAA-MM-JJ.log).
        $dated = static fn (): string => preg_replace('/(\.[^.\/]+)?$/', '-' . date('Y-m-d') . '$1', $logFile, 1);

        $log = static function (string $message) use ($dated): void {
            $file = $dated();
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0775, true);
            }
            @file_put_contents($file, '[' . date('c') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
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
            require dirname(__DIR__) . '/Views/errors/500.php';
        });
    }
}
