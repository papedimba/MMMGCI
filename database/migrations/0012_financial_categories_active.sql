-- Désactivation d'une catégorie financière (L6 T2) : on ne supprime jamais une catégorie portée par des écritures.
ALTER TABLE financial_categories ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER parent_id;
