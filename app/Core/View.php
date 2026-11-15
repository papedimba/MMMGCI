<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

final class View
{
    public function __construct(private readonly string $viewsDir)
    {
    }

    /** @var array<string,mixed> */
    private array $shared = [];

    /** Données communes à toutes les vues (posées par middleware) ; celles du contrôleur priment. */
    public function share(array $data): void
    {
        $this->shared = $data + $this->shared;
    }

    public function render(string $name, array $data = [], ?string $layout = 'layouts/base'): string
    {
        $content = $this->partial($name, $data);
        return $layout === null ? $content : $this->partial($layout, ['content' => $content] + $data);
    }

    public function partial(string $name, array $data = []): string
    {
        $file = $this->resolve($name);
        $data += $this->shared;
        $run = static function (string $__file, array $__data): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                include $__file;
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };
        return $run($file, $data);
    }

    private function resolve(string $name): string
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '..') || $name[0] === '/' || $name[0] === '\\') {
            throw new InvalidArgumentException("Nom de vue invalide : « $name ».");
        }
        $file = $this->viewsDir . '/' . $name . '.php';
        if (!is_file($file)) {
            throw new InvalidArgumentException("Vue introuvable : « $name ».");
        }
        return $file;
    }
}
