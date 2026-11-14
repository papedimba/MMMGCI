<?php
declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;

/** Paramètres (montants, plafonds…) utilisables seulement une fois validés par les instances. */
final class Settings
{
    public function __construct(private Database $db)
    {
    }

    public function define(string $group, string $key, mixed $default = null): void
    {
        if (mb_strlen($group) > 50 || mb_strlen($key) > 100) {
            throw new InvalidArgumentException("Nom de paramètre trop long : $group.$key (groupe 50 caractères, clé 100 au plus) ; raccourcissez-le.");
        }
        $this->db->query('INSERT IGNORE INTO settings (`group`, `key`, `value`, status) VALUES (?, ?, ?, ?)',
            [$group, $key, $this->encode($default), 'non_configure']);
    }

    public function get(string $group, string $key): mixed
    {
        $v = $this->row($group, $key)['value'];
        return $v === null ? null : json_decode($v, true, 512, JSON_THROW_ON_ERROR);
    }

    public function require(string $group, string $key): mixed
    {
        if (!$this->isValidated($group, $key)) {
            throw new SettingNotValidated("Le paramètre $group.$key n'a pas encore été validé par les instances de la Mutuelle : faites valider ce paramètre par les instances dans l'administration.");
        }
        return $this->get($group, $key);
    }

    public function validate(string $group, string $key, mixed $value, int $userId, string $decisionRef): void
    {
        $this->row($group, $key);
        if ($value === null) {
            throw new InvalidArgumentException("La valeur du paramètre $group.$key est vide : saisissez la valeur décidée par les instances.");
        }
        if (trim($decisionRef) === '') {
            throw new InvalidArgumentException('La référence de la décision est obligatoire pour valider un paramètre.');
        }
        $this->db->query('UPDATE settings SET `value` = ?, status = ?, validated_by = ?, validated_at = NOW(), decision_ref = ?
            WHERE `group` = ? AND `key` = ?', [$this->encode($value), 'valide', $userId, $decisionRef, $group, $key]);
    }

    public function isValidated(string $group, string $key): bool
    {
        return $this->row($group, $key)['status'] === 'valide';
    }

    private function row(string $group, string $key): array
    {
        return $this->db->fetch('SELECT `value`, status FROM settings WHERE `group` = ? AND `key` = ?', [$group, $key])
            ?? throw new InvalidArgumentException("Paramètre non défini : $group.$key.");
    }

    private function encode(mixed $v): ?string
    {
        return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
