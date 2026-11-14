<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    public function test_bootstrap_app_serves_home_and_404(): void
    {
        $app = require dirname(__DIR__, 2) . '/app/bootstrap.php';
        // bootstrap.php installe le gestionnaire d'erreurs de l'application : rendre la main à PHPUnit.
        restore_error_handler();
        restore_exception_handler();
        $this->assertSame(200, $app->handle(new Request('GET', '/'))->status());
        $this->assertSame(404, $app->handle(new Request('GET', '/xyz'))->status());
    }
}
