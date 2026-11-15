<?php
declare(strict_types=1);

namespace App\Modules\Finance;

use App\Core\Audit;
use App\Core\Database;
use DomainException;
use PDOException;

/** Catégories financières : création et (dés)activation, jamais de suppression (elles portent des écritures). */
final class CategoryService
{
    public function __construct(private Database $db, private Audit $audit)
    {
    }

    public function create(string $name, string $direction, int $actor): int
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 160) {
            throw new DomainException('Nom de la catégorie : obligatoire, 160 caractères au maximum.');
        }
        if (!in_array($direction, Ledger::DIRECTIONS, true)) {
            throw new DomainException('Sens invalide : recette ou dépense.');
        }
        return $this->db->atomic(function () use ($name, $direction, $actor): int {
            try {
                $id = $this->db->insert('financial_categories', ['name' => $name, 'direction' => $direction]);
            } catch (PDOException $e) {
                throw ($e->errorInfo[1] ?? 0) === 1062 ? new DomainException('Une catégorie de ce sens porte déjà ce nom.', 0, $e) : $e;
            }
            $this->audit->log($actor, 'finance.category_created', 'financial_category', $id, null, ['name' => $name, 'direction' => $direction]);
            return $id;
        });
    }

    public function setActive(int $id, bool $active, int $actor): void
    {
        $this->db->atomic(function () use ($id, $active, $actor): void {
            $row = $this->db->fetch('SELECT is_active FROM financial_categories WHERE id = ? FOR UPDATE', [$id]) ?? throw new DomainException('Catégorie inconnue.');
            if ((int) $row['is_active'] === (int) $active) {
                return;
            }
            $this->db->update('financial_categories', ['is_active' => (int) $active], ['id' => $id]);
            $this->audit->log($actor, $active ? 'finance.category_activated' : 'finance.category_deactivated', 'financial_category', $id, ['is_active' => (int) !$active], ['is_active' => (int) $active]);
        });
    }
}
