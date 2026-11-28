<?php
declare(strict_types=1);

namespace Tests\Feature;

use Tests\DatabaseTestCase;

final class CmsSchemaTest extends DatabaseTestCase
{
    private function expectSqlError(string $sql, array $params = []): void
    {
        try {
            $this->db->query($sql, $params);
        } catch (\PDOException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Contrainte attendue : ' . $sql);
    }

    public function test_all_cms_tables_exist_with_checks_and_uniques(): void
    {
        foreach (['cms_pages', 'cms_menus', 'cms_menu_items', 'cms_articles', 'cms_categories', 'cms_tags', 'cms_article_tag', 'cms_media', 'cms_events',
            'cms_documents', 'cms_galleries', 'cms_gallery_items', 'cms_banners', 'cms_testimonials', 'cms_forms', 'cms_form_submissions', 'cms_page_redirects'] as $t) {
            $this->assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]), $t);
        }
        $this->expectSqlError("INSERT INTO cms_pages (title, slug, status) VALUES ('T', 'bad-status', 'public')");
        $this->expectSqlError("INSERT INTO cms_events (title, slug, starts_at, status) VALUES ('T', 'bad-ev', NOW(), 'publie_x')");
        $this->db->query("INSERT INTO cms_pages (title, slug) VALUES ('T', 'dup-slug')");
        $this->expectSqlError("INSERT INTO cms_pages (title, slug) VALUES ('U', 'dup-slug')");
        $this->db->query("INSERT INTO cms_forms (form_key, name) VALUES ('contact', 'Contact')");
        $f = (int) $this->db->pdo()->lastInsertId();
        $this->expectSqlError("INSERT INTO cms_forms (form_key, name) VALUES ('contact', 'Autre')");
        $this->expectSqlError("INSERT INTO cms_form_submissions (form_id, payload, status) VALUES ($f, '{}', 'publie')");
        $this->expectSqlError("INSERT INTO cms_menus (name, location) VALUES ('X', 'header')");
        $this->expectSqlError("INSERT INTO cms_documents (title, file_id) VALUES ('D', 999999)");
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM cms_pages'.' WHERE slug <> \'dup-slug\''));
    }

    public function test_site_settings_are_unconfigured_by_default(): void
    {
        $rows = $this->db->fetchAll("SELECT `key`, `value`, status FROM settings WHERE `group` = 'site' ORDER BY `key`");
        $this->assertSame(['address', 'adhesion_open', 'consent_text', 'contact_email', 'contact_phone', 'forms_retention_months', 'social_facebook', 'social_whatsapp', 'testimonials_enabled'], array_column($rows, 'key'));
        foreach ($rows as $r) {
            $this->assertNull($r['value']);
            $this->assertSame('non_configure', $r['status']);
        }
    }
}
