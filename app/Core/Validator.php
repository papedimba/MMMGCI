<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];
    /** @var array<string, ?string> */
    private array $values = [];

    private function __construct(private array $data, private array $rules, private array $labels)
    {
        $this->run();
    }

    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return array<string, ?string> */
    public function validated(): array
    {
        return $this->values;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $spec) {
            $raw = $this->data[$field] ?? null;
            $label = $this->labels[$field] ?? $field;
            if (is_int($raw) || is_float($raw)) {
                $raw = (string) $raw;
            }
            if ($raw !== null && !is_string($raw)) {
                $this->values[$field] = null;
                $this->errors[$field] = "$label : valeur invalide, saisissez un texte.";
                continue;
            }
            $value = $raw === null ? null : trim($raw);
            $value = $value === '' ? null : $value;
            $this->values[$field] = $value;
            $rules = explode('|', $spec);
            $known = ['required', 'nullable', 'string', 'max', 'min', 'email', 'phone_ci', 'date', 'before_today', 'in', 'integer', 'same'];

            foreach ($rules as $rule) {
                if (!in_array(explode(':', $rule, 2)[0], $known, true)) {
                    throw new \InvalidArgumentException("Règle de validation inconnue : $rule");
                }
                if (preg_match('/^(max|min):(.*)$/', $rule, $m) && !ctype_digit($m[2])) {
                    throw new \InvalidArgumentException("Paramètre numérique attendu : $rule");
                }
            }
            if ($value === null) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = "Le champ $label est obligatoire.";
                }
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, '');
                if (($msg = $this->check($field, $name, $arg, $label)) !== null) {
                    $this->errors[$field] = $msg;
                    break;
                }
            }
        }
    }

    private function check(string $field, string $name, string $arg, string $label): ?string
    {
        $v = $this->values[$field];
        $s = is_string($v) ? $v : '';
        switch ($name) {
            case 'string':
                return is_string($v) ? null : "$label : saisissez un texte.";
            case 'max':
                return mb_strlen($s) <= (int) $arg ? null : "$label : $arg caractères au maximum.";
            case 'min':
                return mb_strlen($s) >= (int) $arg ? null : "$label : $arg caractères au minimum.";
            case 'email':
                return filter_var($s, FILTER_VALIDATE_EMAIL) !== false ? null : 'Adresse e-mail invalide (ex. nom@exemple.ci).';
            case 'phone_ci':
                $digits = preg_replace('/[\s.\-]/', '', $s);
                $digits = preg_replace('/^(\+225|00225)/', '', $digits);
                if (!preg_match('/^\d{10}$/', $digits)) {
                    return 'Numéro de téléphone invalide : saisissez 10 chiffres (ex. 07 12 34 56 78).';
                }
                $this->values[$field] = $digits;
                return null;
            case 'date':
                $iso = self::parseDate($s);
                if ($iso === null) {
                    return 'Date invalide : utilisez le format JJ/MM/AAAA.';
                }
                $this->values[$field] = $iso;
                return null;
            case 'before_today':
                $iso = self::parseDate($s);
                if ($iso === null) {
                    return 'Date invalide : utilisez le format JJ/MM/AAAA.';
                }
                $today = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->format('Y-m-d');
                return $iso < $today ? null : "$label : la date doit être antérieure à aujourd'hui.";
            case 'in':
                return in_array($s, explode(',', $arg), true) ? null : "$label : choisissez une valeur dans la liste proposée.";
            case 'integer':
                return preg_match('/^-?\d+$/', $s) ? null : "$label : saisissez un nombre entier (ex. 12).";
            case 'same':
                $other = $this->data[$arg] ?? null;
                $other = is_string($other) ? trim($other) : $other;
                return $v === ($other === '' ? null : $other) ? null : "$label : la valeur doit être identique à celle du champ de confirmation.";
        }
        return null; // required / nullable
    }

    /** Accepts AAAA-MM-JJ or JJ/MM/AAAA; returns AAAA-MM-JJ or null. */
    private static function parseDate(string $s): ?string
    {
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }
}
