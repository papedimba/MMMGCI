<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_required_message_uses_label(): void
    {
        $v = Validator::make(['nom' => '  '], ['nom' => 'required'], ['nom' => 'Nom']);
        $this->assertTrue($v->fails());
        $this->assertSame('Le champ Nom est obligatoire.', $v->errors()['nom']);
    }

    public function test_phone_ci_accepts_spaces_and_country_code(): void
    {
        foreach (['+225 07 12 34 56 78', '00225-07.12.34.56.78', '07 12 34 56 78'] as $in) {
            $v = Validator::make(['tel' => $in], ['tel' => 'phone_ci']);
            $this->assertFalse($v->fails(), $in);
            $this->assertSame('0712345678', $v->validated()['tel']);
        }
    }

    public function test_phone_ci_rejects_short_number_with_example(): void
    {
        $v = Validator::make(['tel' => '0712'], ['tel' => 'phone_ci']);
        $this->assertSame('Numéro de téléphone invalide : saisissez 10 chiffres (ex. 07 12 34 56 78).', $v->errors()['tel']);
    }

    public function test_max_counts_characters_not_bytes(): void
    {
        $this->assertFalse(Validator::make(['n' => 'Aïcha'], ['n' => 'max:5'])->fails());
        $v = Validator::make(['n' => 'Aïchas'], ['n' => 'max:5'], ['n' => 'Nom']);
        $this->assertSame('Nom : 5 caractères au maximum.', $v->errors()['n']);
    }

    public function test_names_with_apostrophe_and_accents_pass_string_max(): void
    {
        $v = Validator::make(['n' => "N'Guessan Élodie"], ['n' => 'required|string|max:120']);
        $this->assertFalse($v->fails());
        $this->assertSame("N'Guessan Élodie", $v->validated()['n']);
    }

    public function test_validated_returns_only_declared_trimmed_fields(): void
    {
        $v = Validator::make(['a' => '  x ', 'b' => '   ', 'extra' => 'y'], ['a' => 'required', 'b' => 'nullable|string']);
        $this->assertSame(['a' => 'x', 'b' => null], $v->validated());
    }

    public function test_date_rejects_impossible_date(): void
    {
        $v = Validator::make(['d' => '2026-02-30'], ['d' => 'date']);
        $this->assertSame('Date invalide : utilisez le format JJ/MM/AAAA.', $v->errors()['d']);
    }

    public function test_date_accepts_both_formats_and_normalizes(): void
    {
        foreach (['2026-03-05', '05/03/2026'] as $in) {
            $v = Validator::make(['d' => $in], ['d' => 'date']);
            $this->assertFalse($v->fails(), $in);
            $this->assertSame('2026-03-05', $v->validated()['d']);
        }
    }

    public function test_before_today(): void
    {
        $this->assertFalse(Validator::make(['d' => '2000-01-01'], ['d' => 'date|before_today'])->fails());
        $this->assertTrue(Validator::make(['d' => '2999-01-01'], ['d' => 'date|before_today'])->fails());
    }

    public function test_in_integer_same_email_min(): void
    {
        $v = Validator::make(
            ['s' => 'z', 'i' => '1.5', 'p' => 'a', 'q' => 'b', 'e' => 'nope', 'm' => 'ab'],
            ['s' => 'in:a,b', 'i' => 'integer', 'q' => 'same:p', 'e' => 'email', 'm' => 'min:3']
        );
        $this->assertSame(['s', 'i', 'q', 'e', 'm'], array_keys($v->errors()));
        $ok = Validator::make(['s' => 'a', 'i' => '-12', 'e' => 'a@b.ci'], ['s' => 'in:a,b', 'i' => 'integer', 'e' => 'email']);
        $this->assertFalse($ok->fails());
    }

    public function test_unknown_rule_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => 'x'], ['a' => 'bogus'])->fails();
    }

    public function test_array_input_is_rejected_and_not_returned(): void
    {
        $v = Validator::make(['n' => ['x']], ['n' => 'required|max:3'], ['n' => 'Nom']);
        $this->assertSame('Nom : valeur invalide, saisissez un texte.', $v->errors()['n']);
        $this->assertNull($v->validated()['n']);
    }

    public function test_int_input_is_cast_to_string(): void
    {
        $v = Validator::make(['n' => 5], ['n' => 'integer']);
        $this->assertFalse($v->fails());
        $this->assertSame('5', $v->validated()['n']);
    }

    public function test_required_accepts_zero_string(): void
    {
        $this->assertFalse(Validator::make(['n' => '0'], ['n' => 'required'])->fails());
    }

    public function test_same_passes_when_equal(): void
    {
        $this->assertFalse(Validator::make(['p' => 'a', 'q' => ' a '], ['q' => 'same:p'])->fails());
    }

    public function test_email_with_newline_is_rejected(): void
    {
        $this->assertTrue(Validator::make(['e' => "a@b.ci\nx"], ['e' => 'email'])->fails());
    }

    public function test_before_today_works_without_date_rule(): void
    {
        $this->assertFalse(Validator::make(['d' => '01/01/2000'], ['d' => 'before_today'])->fails());
        $this->assertTrue(Validator::make(['d' => 'n/a'], ['d' => 'before_today'])->fails());
    }

    public function test_non_numeric_max_parameter_is_a_programming_error(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => 'x'], ['a' => 'max:abc']);
    }

    public function test_same_compares_normalized_values_in_any_order(): void
    {
        $this->assertFalse(Validator::make(['p' => 5, 'q' => 5], ['q' => 'same:p'])->fails());
        $this->assertFalse(Validator::make(['p' => 5, 'q' => '5'], ['q' => 'same:p', 'p' => 'integer'])->fails());
        $this->assertFalse(Validator::make(['p' => 5, 'q' => 5], ['p' => 'same:q', 'q' => 'integer'])->fails());
    }

    public function test_unknown_rule_throws_even_for_array_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => ['x']], ['a' => 'max:abc']);
    }

    public function test_before_today_alone_normalizes_to_iso(): void
    {
        $this->assertSame('2000-01-01', Validator::make(['d' => '01/01/2000'], ['d' => 'before_today'])->validated()['d']);
    }
}
