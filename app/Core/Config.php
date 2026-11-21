<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    /** @param array<string,string> $values */
    public function __construct(private array $values)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower((string) $this->get($key, '')), ['true', '1', 'yes', 'on'], true);
    }
}
