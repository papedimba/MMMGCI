<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\View;
use PHPUnit\Framework\TestCase;

final class PrintLayoutTest extends TestCase
{
    private function render(array $data): string
    {
        return (new View(dirname(__DIR__, 2) . '/app/views'))->render('admin/design/document', $data, 'layouts/print');
    }

    public function test_print_layout_declares_a4_page_and_hides_controls_in_print(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/print.css');
        $this->assertMatchesRegularExpression('~@page\s*\{[^}]*size:\s*A4;[^}]*margin:\s*18mm~', $css);
        $this->assertMatchesRegularExpression('~@page cr80\s*\{[^}]*85\.6mm 54mm~', $css);
        $this->assertMatchesRegularExpression('~@media print\s*\{[^@]*\.print-bar\s*\{\s*display:\s*none~', $css);
        $this->assertMatchesRegularExpression('~tr\s*\{\s*break-inside:\s*avoid~', $css);
        $html = $this->render(['title' => 'Reçu', 'generatedAt' => '15/11/2026 09:30']);
        $this->assertStringContainsString('data-print', $html);
        $this->assertStringContainsString('document généré le 15/11/2026 09:30', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    public function test_document_header_escapes_title_and_reference(): void
    {
        $html = $this->render(['title' => '<script>alert(1)</script>', 'reference' => '"><img src=x onerror=1>']);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('logo-complet', $html);
    }

    public function test_print_page_has_no_navigation_or_menu(): void
    {
        $html = $this->render(['title' => 'Reçu']);
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('data-menu-toggle', $html);
        $this->assertStringNotContainsString('menu-admin', $html);
    }
}
