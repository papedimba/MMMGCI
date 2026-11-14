<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\SettingNotValidated;
use App\Core\Settings;
use InvalidArgumentException;
use Tests\DatabaseTestCase;

final class SettingsTest extends DatabaseTestCase
{
    private Settings $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->query('DELETE FROM settings');
        $this->s = new Settings($this->db);
    }

    public function test_define_does_not_overwrite_existing_value(): void
    {
        $this->s->define('cotisation', 'montant', 5);
        $this->s->validate('cotisation', 'montant', 7, 1, 'AG-2026-01');
        $this->s->define('cotisation', 'montant', 9);
        $this->assertSame(7, $this->s->get('cotisation', 'montant'));
        $this->assertTrue($this->s->isValidated('cotisation', 'montant'));
    }

    public function test_require_throws_until_validated(): void
    {
        $this->s->define('cotisation', 'montant', 5);
        $this->assertSame(5, $this->s->get('cotisation', 'montant'));
        try {
            $this->s->require('cotisation', 'montant');
            $this->fail('exception attendue');
        } catch (SettingNotValidated $e) {
            $this->assertSame("Ce paramètre n'a pas encore été validé par les instances de la Mutuelle : cotisation.montant.", $e->getMessage());
        }
        $this->s->validate('cotisation', 'montant', 5, 1, 'CA-12');
        $this->assertSame(5, $this->s->require('cotisation', 'montant'));
    }

    public function test_validate_requires_decision_reference(): void
    {
        $this->s->define('cotisation', 'montant');
        $this->expectException(InvalidArgumentException::class);
        $this->s->validate('cotisation', 'montant', 5, 1, '');
    }

    public function test_undefined_setting_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->s->get('x', 'y');
    }

    public function test_value_roundtrips_as_json(): void
    {
        $v = ['montant' => 1000000, 'periodicite' => 'mensuelle'];
        $this->s->define('cotisation', 'regle');
        $this->s->validate('cotisation', 'regle', $v, 1, 'AG-1');
        $this->assertSame($v, $this->s->get('cotisation', 'regle'));
    }
}
