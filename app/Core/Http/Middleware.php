<?php
declare(strict_types=1);

namespace App\Core\Http;

interface Middleware
{
    public function handle(Request $r, callable $next): Response;
}
