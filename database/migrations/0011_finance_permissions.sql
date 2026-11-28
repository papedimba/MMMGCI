-- Permission finance.manage (L6) : comptes, catégories et règles ; attribuée à qui gère déjà les cotisations ou la solidarité.
INSERT INTO permissions (name, label, module) VALUES
    ('finance.manage', 'Gérer les finances', 'finance');

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, p.id FROM role_permissions rp
JOIN permissions v ON v.id = rp.permission_id AND v.name IN ('contributions.manage', 'solidarity.manage')
JOIN permissions p ON p.name = 'finance.manage';

-- Paramètres financiers, non configurés (les défauts techniques « bloquer » / « non » sont appliqués par le code).
INSERT IGNORE INTO settings (`group`, `key`, `value`, status) VALUES
    ('finance', 'insufficient_balance_policy', NULL, 'non_configure'),
    ('finance', 'payer_may_be_initiator', NULL, 'non_configure'),
    ('finance', 'fiscal_year_start', NULL, 'non_configure'),
    ('contributions', 'up_to_date_rule', NULL, 'non_configure');
