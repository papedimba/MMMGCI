<?php
declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(private string $body = '', private int $status = 200, private array $headers = [])
    {
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public function withHeader(string $n, string $v): self
    {
        $c = clone $this;
        $c->headers[$n] = $v;
        return $c;
    }

    public function status(): int { return $this->status; }

    public function body(): string { return $this->body; }

    public function headers(): array { return $this->headers; }

    public function send(): void
    {
        http_response_code($this->status);
        header_remove('X-Powered-By');
        foreach ($this->headers as $n => $v) {
            header("$n: $v");
        }
        echo $this->body;
    }
}
