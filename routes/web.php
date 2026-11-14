<?php
declare(strict_types=1);

use App\Core\Http\Response;
use App\Core\Http\Router;

return static function (Router $router): void {
    $router->get('/', static fn($r): Response => new Response('MMMGCI : plateforme en construction.', 200, ['Content-Type' => 'text/plain; charset=utf-8']));
};
