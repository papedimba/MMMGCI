-- Permissions de l'adhérent (L5) : changement de statut et périmètre national.
INSERT INTO permissions (name, label, module) VALUES
    ('members.status', 'Changer le statut des adhérents', 'members'),
    ('members.scope.all', 'Voir les adhérents de toutes les zones', 'members');

-- Périmètre complet : tous les détenteurs de members.view sauf delegue et point_focal (limités à leur zone).
INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, p.id FROM role_permissions rp
JOIN permissions v ON v.id = rp.permission_id AND v.name = 'members.view'
JOIN roles r ON r.id = rp.role_id AND r.name NOT IN ('delegue', 'point_focal')
JOIN permissions p ON p.name = 'members.scope.all';

INSERT INTO role_permissions (role_id, permission_id)
SELECT DISTINCT rp.role_id, p.id FROM role_permissions rp
JOIN permissions v ON v.id = rp.permission_id AND v.name = 'members.validate'
JOIN permissions p ON p.name = 'members.status';

-- Paramètres des adhérents, non configurés (équivalent de Settings::define, présents sur toute installation).
INSERT IGNORE INTO settings (`group`, `key`, `value`, status) VALUES
    ('members', 'number_format', '"MMMGCI-{YYYY}-{NNNNN}"', 'non_configure'),
    ('members', 'card_validity_months', NULL, 'non_configure'),
    ('members', 'card_conditions', NULL, 'non_configure'),
    ('members', 'retention_months', NULL, 'non_configure');
