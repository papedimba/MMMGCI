<?php
declare(strict_types=1);

namespace App\Modules\Members;

use App\Core\Audit;
use App\Core\Database;
use DomainException;

/** Référentiels administrables des adhérents : jamais supprimés, seulement désactivés ; chaque écriture est auditée. */
final class Referentials
{
    public const TABLES = ['member_categories', 'maritime_professions', 'maritime_specialties', 'zones'];
    public const ZONE_TYPES = ['port' => 'Port', 'localite' => 'Localité', 'region' => 'Région'];
    private const PARENT_TABLE = ['maritime_professions' => 'maritime_professions', 'maritime_specialties' => 'maritime_professions', 'zones' => 'zones'];

    public function __construct(private Database $db, private Audit $audit)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(string $table): array
    {
        $this->assertTable($table);
        $parent = self::PARENT_TABLE[$table] ?? null;
        $join = $parent === null ? '' : "LEFT JOIN `$parent` p ON p.id = t.parent_id";
        $cols = 't.*' . ($parent === null ? '' : ', p.name AS parent_name');
        return $this->db->fetchAll("SELECT $cols FROM `$table` t $join ORDER BY t.is_active DESC, t.name, t.id");
    }

    /** @param array<string,mixed> $data name, parent_id ?, type ? (zones) */
    public function save(string $table, ?int $id, array $data, int $actor): int
    {
        $this->assertTable($table);
        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Le libellé est obligatoire (120 caractères au plus).');
        }
        $row = ['name' => $name];
        if (isset(self::PARENT_TABLE[$table])) {
            $p = $data['parent_id'] ?? null;
            $row['parent_id'] = ($p === null || $p === '') ? null : (int) $p;
            if ($row['parent_id'] !== null && ($row['parent_id'] === $id
                || $this->db->fetchValue('SELECT 1 FROM `' . self::PARENT_TABLE[$table] . '` WHERE id = ?', [$row['parent_id']]) === null)) {
                throw new DomainException('Le parent choisi est invalide : choisissez-le dans la liste.');
            }
        }
        if ($table === 'zones') {
            $type = $data['type'] ?? '';
            if (!is_string($type) || !isset(self::ZONE_TYPES[$type])) {
                throw new DomainException('Le type de zone est invalide : choisissez port, localité ou région.');
            }
            $row['type'] = $type;
        }
        return $this->db->atomic(function () use ($table, $id, $row, $actor): int {
            $before = $id === null ? null : ($this->db->fetch("SELECT * FROM `$table` WHERE id = ? FOR UPDATE", [$id]) ?? throw new DomainException('Cet élément n\'existe plus : rechargez la page.'));
            $byParent = array_key_exists('parent_id', $row); // même libellé permis sous deux parents différents
            $dup = $this->db->fetchValue("SELECT 1 FROM `$table` WHERE name = ? AND id <> ?" . ($byParent ? ' AND parent_id <=> ?' : ''),
                $byParent ? [$row['name'], $id ?? 0, $row['parent_id']] : [$row['name'], $id ?? 0]);
            if ($dup !== null) {
                throw new DomainException('Un élément porte déjà ce libellé : modifiez-le ou choisissez-en un autre.');
            }
            if ($before === null) {
                $id = $this->db->insert($table, $row);
                $this->audit->log($actor, 'referential.created', $table, $id, null, $row);
            } else {
                $changed = array_diff_assoc($row, $before);
                if ($changed !== []) {
                    $this->db->update($table, $changed, ['id' => $id]);
                    $this->audit->log($actor, 'referential.updated', $table, $id, array_intersect_key($before, $changed), $changed);
                }
            }
            return (int) $id;
        });
    }

    public function setActive(string $table, int $id, bool $active, int $actor): void
    {
        $this->assertTable($table);
        $this->db->atomic(function () use ($table, $id, $active, $actor): void {
            $before = $this->db->fetchValue("SELECT is_active FROM `$table` WHERE id = ? FOR UPDATE", [$id]) ?? throw new DomainException('Cet élément n\'existe plus : rechargez la page.');
            if ((bool) $before === $active) {
                return;
            }
            $this->db->update($table, ['is_active' => (int) $active], ['id' => $id]);
            $this->audit->log($actor, $active ? 'referential.activated' : 'referential.deactivated', $table, $id, ['is_active' => !$active], ['is_active' => $active]);
        });
    }

    private function assertTable(string $table): void
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new DomainException("Référentiel inconnu : $table.");
        }
    }
}
