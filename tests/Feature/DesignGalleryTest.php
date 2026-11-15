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
    }

    public function test_gallery_requires_users_manage(): void
    {
        $this->env = ['APP_ENV' => 'local'];
        $this->assertSame(302, $this->request('GET', '/admin/composants')->status());
        $this->actingAs($this->createUser('lecteur@example.test', ['lecture_seule']));
        $this->assertSame(403, $this->request('GET', '/admin/composants')->status());
    }
}
