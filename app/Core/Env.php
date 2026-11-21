<?php
declare(strict_types=1);

namespace App\Core;

final class Env
{
    /** @return array<string,string> */
    public static function load(string $path): array
    {
        if (!is_file($path) || ($lines = file($path, FILE_IGNORE_NEW_LINES)) === false) {
            return [];
        }
        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[trim($key)] = $value;
        }
        return $values;
    }
}
