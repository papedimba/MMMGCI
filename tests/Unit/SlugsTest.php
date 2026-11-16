<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Cms\Slugs;
use DomainException;
use Tests\DatabaseTestCase;

final class SlugsTest extends DatabaseTestCase
{
    private function slugs(): Slugs
    {
        return new Slugs($this->db);
    }

    public function test_reserved_slugs_are_refused(): void
    {
        foreach (Slugs::RESERVED as $r) {
            try {
                $this->slugs()->validate($r, null, 'cms_pages');
                $this->fail("$r accepté");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('admin-page', Slugs::fromTitle('Admin'));
    }

    public function test_format_and_length(): void
    {
        $this->assertSame('a-b-1', $this->slugs()->validate('a-b-1', null, 'cms_pages'));
        foreach (['', 'A', 'a b', '-a', 'a-', 'a--b', 'é', str_repeat('a', 81)] as $bad) {
            try {
                $this->slugs()->validate($bad, null, 'cms_pages');
                $this->fail("« $bad » accepté");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(str_repeat('a', 80), $this->slugs()->validate(str_repeat('a', 80), null, 'cms_pages'));
        $long = Slugs::fromTitle(str_repeat('mot ', 40));
        $this->assertLessThanOrEqual(80, strlen($long));
        $this->assertStringEndsNotWith('-', $long);
        $this->assertSame('page', Slugs::fromTitle('???'));
    }

    public function test_from_title_strips_accents(): void
    {
        $this->assertSame('oeuvres-sociales-d-urgence', Slugs::fromTitle("Œuvres sociales d'urgence"));
        $this->assertSame('ca-et-la-ete', Slugs::fromTitle('  Ça  et là — été '));
    }

    public function test_uniqueness_ignores_current_row(): void
    {
        $this->db->query("INSERT INTO cms_pages (title, slug) VALUES ('T', 'unique-test')");
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->assertSame('unique-test', $this->slugs()->validate('unique-test', $id, 'cms_pages'));
        $this->expectException(DomainException::class);
        $this->slugs()->validate('unique-test', null, 'cms_pages');
    }
}
