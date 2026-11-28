-- Permissions de l'Espace adhérent (L15) ; les corrections de fiche se traitent avec members.update (existante).
INSERT INTO permissions (name, label, module) VALUES
    ('espace.accounts.manage', 'Gérer les accès à l''Espace adhérent', 'espace'),
    ('espace.accounts.reset', 'Délivrer un nouveau code à un adhérent', 'espace');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'espace.accounts.manage'
WHERE r.name IN ('gest_adherents', 'secretaire_general', 'admin_general', 'delegue', 'point_focal');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'espace.accounts.reset'
WHERE r.name = 'gest_adherents';

-- Défauts techniques appliqués (propositions A8) mais non validés par les instances ; dépôt de demande en ligne fermé (NULL).
INSERT IGNORE INTO settings (`group`, `key`, `value`, status) VALUES
    ('espace', 'status_access', '{"actif":"complet","suspendu":"lecture","radie":"desactive","decede":"desactive"}', 'non_configure'),
    ('espace', 'activation_code_days', '14', 'non_configure'),
    ('espace', 'depot_demande_en_ligne', NULL, 'non_configure');
