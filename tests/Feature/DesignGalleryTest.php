<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use Tests\Support\AdminTestCase;

final class DesignGalleryTest extends AdminTestCase
{
    public function test_gallery_is_404_in_production_and_200_for_admin_elsewhere(): void
    {
        $this->actingAs($this->createUser('admin@example.test', ['super_admin']));
        $this->env = ['APP_ENV' => 'local'];
        $ok = $this->request('GET', '/admin/composants');
        $this->assertSame(200, $ok->status());
        $this->assertSame(1, substr_count($ok->body(), '<h1'));
        $this->assertStringContainsString('class="steps"', $ok->body());
        $this->assertStringNotContainsString('style=', $ok->body());

        $this->forgetApp();
        $this->env = ['APP_ENV' => 'production'];
        $this->actingAs($this->createUser('admin2@example.test', ['super_admin']));
        $res = $this->app()->handle(new Request('GET', '/admin/composants', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'exemple.ci']));
        $this->assertSame(404, $res->status());

        $this->forgetApp(); // route non enregistrée : 404 même sans session
        $anon = $this->app()->handle(new Request('GET', '/admin/composants', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'exemple.ci']));
        $this->assertSame(404, $anon->status());
    }

    public function test_gallery_requires_users_manage(): void
    {
        $this->env = ['APP_ENV' => 'local'];
        $this->assertSame(302, $this->request('GET', '/admin/composants')->status());
        $this->actingAs($this->createUser('lecteur@example.test', ['lecture_seule']));
        $this->assertSame(403, $this->request('GET', '/admin/composants')->status());
    }

    public function test_print_sample_is_gated_like_the_gallery(): void
    {
        $this->env = ['APP_ENV' => 'local'];
        $this->assertSame(302, $this->request('GET', '/admin/composants/document')->status());
        $this->actingAs($this->createUser('lecteur2@example.test', ['lecture_seule']));
        $this->assertSame(403, $this->request('GET', '/admin/composants/document')->status());
        $this->forgetApp();
        $this->actingAs($this->createUser('admin3@example.test', ['super_admin']));
        $ok = $this->request('GET', '/admin/composants/document');
        $this->assertSame(200, $ok->status());
        $this->assertStringContainsString('class="card-cr80"', $ok->body());
        $this->assertStringNotContainsString('<nav', $ok->body());

        $this->forgetApp();
        $this->env = ['APP_ENV' => 'production'];
        $this->actingAs($this->createUser('admin4@example.test', ['super_admin']));
        $res = $this->app()->handle(new Request('GET', '/admin/composants/document', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on', 'HTTP_HOST' => 'exemple.ci']));
        $this->assertSame(404, $res->status());
    }
}
