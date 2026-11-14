<?php
declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    private array $attributes = [];
    private string $path;

    public function __construct(
        private string $method,
        string $path,
        private array $query = [],
        private array $post = [],
        private array $files = [],
        private array $server = [],
        private array $cookies = [],
    ) {
        $this->path = '/' . trim($path, '/');
    }

    public static function fromGlobals(): self
    {
        // Chemin brut (non décodé) : le routeur décode chaque paramètre, pour que %2F reste dans son segment.
        $uri = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', preg_replace('#^/+#', '/', $uri), $_GET, $_POST, $_FILES, $_SERVER, $_COOKIE);
    }

    public function method(): string { return strtoupper($this->method); }

    public function path(): string { return $this->path; }

    public function input(string $k, mixed $d = null): mixed { return $this->post[$k] ?? $this->query[$k] ?? $d; }

    public function all(): array { return $this->post + $this->query; }

    public function file(string $k): ?array { return $this->files[$k] ?? null; }

    public function cookie(string $k): ?string { return $this->cookies[$k] ?? null; }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off';
    }

    public function queryString(): string { return http_build_query($this->query); }

    public function ip(): string { return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0'); }

    public function header(string $name): ?string
    {
        $v = $this->server['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? null;
        return $v === null ? null : (string) $v;
    }

    public function attribute(string $k): mixed { return $this->attributes[$k] ?? null; }

    public function withAttribute(string $k, mixed $v): self
    {
        $c = clone $this;
        $c->attributes[$k] = $v;
        return $c;
    }
}
