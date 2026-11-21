<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Cms\PageInvalid;
use App\Modules\Cms\SiteSettings;
use App\Core\Settings;
use Tests\Support\AdminTestCase;

final class SiteSettingsTest extends AdminTestCase
{
    private int $admin;
    private int $validator;
    private int $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query("UPDATE settings SET value = NULL, status = 'non_configure', validated_by = NULL, validated_at = NULL, decision_ref = NULL WHERE `group` = 'site'");
        $this->db->query('DELETE FROM users');
        $this->admin = $this->createUser('adminsite@x.test', ['admin_site']);
        $this->validator = $this->createUser('pca@x.test', ['pca']);
        $this->writer = $this->createUser('redac@x.test', ['redacteur']);
    }

    private function svc(): SiteSettings
    {
        $this->app();
        return new SiteSettings($this->db, $this->services['audit'](), $this->services['gate']());
    }

    public function test_unvalidated_contact_settings_are_not_exposed_publicly(): void
    {
        $s = $this->svc();
        $s->save('contact_email', 'contact@exemple.ci', $this->admin);
        $this->assertNull($s->publicValue('contact_email'), 'saisi mais non validé');
        $s->validate('contact_email', $this->validator, 'PV-2026-01', $this->seen($s, 'contact_email'));
        $this->assertSame('contact@exemple.ci', $s->publicValue('contact_email'));
        // Modifier une valeur validée la remet en attente : le public la perd.
        $s->save('contact_email', 'autre@exemple.ci', $this->admin);
        $this->assertNull($s->publicValue('contact_email'));
        $this->assertSame('non_configure', $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'site' AND `key` = 'contact_email'"));
        // Enregistrer la même valeur ne change pas l'état.
        $s->validate('contact_email', $this->validator, 'PV-2026-02', $this->seen($s, 'contact_email'));
        $s->save('contact_email', 'autre@exemple.ci', $this->admin);
        $this->assertSame('autre@exemple.ci', $s->publicValue('contact_email'));
        // Un réglage jamais saisi reste vide et inutilisable ; Settings::require refuse.
        $this->assertNull($s->publicValue('address'));
        $this->expectException(\App\Core\SettingNotValidated::class);
        (new Settings($this->db))->require('site', 'address');
    }

    public function test_validation_needs_value_reference_permission_and_unchanged_value(): void
    {
        $s = $this->svc();
        try {
            $s->validate('contact_phone', $this->validator, 'PV-1', md5(''));
            $this->fail('vide');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('vide', $e->getMessage());
        }
        $s->save('contact_phone', '+225 01 02 03 04 05', $this->admin);
        $seen = $this->seen($s, 'contact_phone');
        foreach ([[$this->validator, ' ', $seen, 'référence'], [$this->admin, 'PV-1', $seen, 'droit'], [$this->validator, 'PV-1', md5('autre'), 'changé']] as [$u, $ref, $sn, $word]) {
            try {
                $s->validate('contact_phone', $u, $ref, $sn);
                $this->fail($word);
            } catch (\DomainException $e) {
                $this->assertStringContainsString($word, $e->getMessage());
            }
        }
        try {
            $s->save('contact_phone', '0102030405', $this->validator);
            $this->fail('droit de saisie');
        } catch (\DomainException) {
            $this->assertNull($s->publicValue('contact_phone'));
        }
        $this->assertSame('valide', $this->after(fn () => $s->validate('contact_phone', $this->validator, 'PV-1', $seen), 'contact_phone'));
    }

    private function after(callable $do, string $key): string
    {
        $do();
        return (string) $this->db->fetchValue("SELECT status FROM settings WHERE `group` = 'site' AND `key` = ?", [$key]);
    }

    private function seen(SiteSettings $s, string $key): string
    {
        foreach ($s->all() as $r) {
            if ($r['key'] === $key) {
                return $r['seen'];
            }
        }
        return '';
    }

    public function test_settings_formats_are_validated(): void
    {
        $s = $this->svc();
        $bad = ['contact_email' => ['pas-un-mail', "a@b.ci\r\nBcc: x@y.z"], 'contact_phone' => ['abc', '+225 (01)', str_repeat('1', 25)],
            'address' => ['<b>rue</b>', str_repeat('a', 301)], 'social_facebook' => ['http://facebook.com/x', 'javascript:alert(1)', 'facebook.com/x'],
            'social_whatsapp' => ['https://exemple.ci/x', 'abc'], 'consent_text' => [str_repeat('a', 4001), '<script>x</script>'],
            'adhesion_open' => ['oui', '1'], 'testimonials_enabled' => ['vrai'], 'forms_retention_months' => ['0', '121', 'abc', '-3', '1.5']];
        foreach ($bad as $key => $values) {
            foreach ($values as $v) {
                try {
                    $s->save($key, $v, $this->admin);
                    $this->fail("$key « $v » aurait dû être refusé");
                } catch (PageInvalid $e) {
                    $this->assertArrayHasKey($key, $e->errors);
                }
            }
        }
        $good = ['contact_email' => 'Info@Exemple.ci', 'contact_phone' => '+225 07 00 00 00 00', 'address' => 'Abidjan, Plateau', 'social_facebook' => 'https://www.facebook.com/mmmgci',
            'social_whatsapp' => 'https://wa.me/2250700000000', 'consent_text' => "Ligne 1\nLigne 2", 'adhesion_open' => 'true', 'testimonials_enabled' => 'false', 'forms_retention_months' => '24'];
        foreach ($good as $k => $v) {
            $s->save($k, $v, $this->admin);
        }
        $vals = array_column($s->all(), 'value', 'key');
        $this->assertSame('info@exemple.ci', $vals['contact_email']);
        $this->assertTrue($vals['adhesion_open']);
        $this->assertFalse($vals['testimonials_enabled']);
        $this->assertSame(24, $vals['forms_retention_months']);
        $s->save('contact_email', '', $this->admin);
        $this->assertNull(array_column($s->all(), 'value', 'key')['contact_email']);
    }

    public function test_settings_screen_permission_matrix_csrf_and_escaping(): void
    {
        $this->actingAs($this->writer);
        $this->assertSame(403, $this->request('GET', '/admin/site/reglages')->status());
        $this->assertSame(403, $this->request('POST', '/admin/site/reglages/contact_email', ['contact_email' => 'a@b.ci'])->status());

        $this->actingAs($this->admin);
        $this->assertSame(200, $this->request('GET', '/admin/site/reglages')->status());
        $this->assertSame(403, $this->request('POST', '/admin/site/reglages/contact_email/validation', ['decision_ref_contact_email' => 'PV'])->status(), 'saisir ne valide pas');
        $res = $this->request('POST', '/admin/site/reglages/contact_email', ['contact_email' => 'a@b.ci']);
        $this->assertSame(302, $res->status());
        $page = $this->request('GET', '/admin/site/reglages')->body();
        $this->assertStringContainsString('En attente de validation', $page);
        $this->assertStringNotContainsString('Valider ce réglage', $page, 'sans settings.validate');
        // Format refusé : 422, saisie hostile échappée.
        $evil = '<script>alert(1)</script>';
        $res = $this->request('POST', '/admin/site/reglages/address', ['address' => $evil]);
        $this->assertSame(422, $res->status());
        $this->assertStringNotContainsString($evil, $res->body());
        // CSRF.
        $res = $this->app()->handle(new \App\Core\Http\Request('POST', '/admin/site/reglages/contact_email', [], ['contact_email' => 'x@y.ci', '_csrf' => 'faux'], [], ['REMOTE_ADDR' => '127.0.0.1']));
        $this->assertSame(419, $res->status());
        $this->assertNull($this->svc()->publicValue('contact_email'));

        $this->actingAs($this->validator);
        $this->assertSame(200, $this->request('GET', '/admin/site/reglages')->status());
        $this->assertStringContainsString('Valider ce réglage', $this->request('GET', '/admin/site/reglages')->body());
        $seen = $this->seen($this->svc(), 'contact_email');
        $res = $this->request('POST', '/admin/site/reglages/contact_email/validation', ['decision_ref_contact_email' => ' ', 'seen_contact_email' => $seen]);
        $this->assertSame(422, $res->status());
        $res = $this->request('POST', '/admin/site/reglages/contact_email/validation', ['decision_ref_contact_email' => 'PV-9', 'seen_contact_email' => $seen]);
        $this->assertSame(302, $res->status());
        $this->assertSame('a@b.ci', $this->svc()->publicValue('contact_email'));
        $this->assertSame(404, $this->request('POST', '/admin/site/reglages/inconnu/validation', ['x' => 1])->status());
        $this->assertGreaterThan(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'cms.setting_validated'"));
    }
}
