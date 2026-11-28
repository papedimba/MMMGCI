-- Catalogue initial des permissions et des rôles (matrice A6). Aucun id codé en dur : jointures sur les noms.

INSERT INTO permissions (name, label, module) VALUES
    ('users.manage', 'Gérer les utilisateurs', 'admin'),
    ('roles.manage', 'Gérer les rôles', 'admin'),
    ('audit.view', 'Consulter le journal d''audit', 'admin'),
    ('settings.manage', 'Gérer les paramètres', 'admin'),
    ('settings.validate', 'Valider les paramètres', 'admin'),
    ('cms.pages.manage', 'Gérer les pages', 'cms'),
    ('cms.publish', 'Publier les contenus', 'cms'),
    ('cms.media.manage', 'Gérer les médias', 'cms'),
    ('cms.forms.manage', 'Gérer les formulaires', 'cms'),
    ('cms.settings.manage', 'Gérer les réglages du site', 'cms'),
    ('members.view', 'Consulter les adhérents', 'members'),
    ('members.create', 'Créer des adhérents', 'members'),
    ('members.update', 'Modifier les adhérents', 'members'),
    ('members.validate', 'Valider les adhésions', 'members'),
    ('members.export', 'Exporter les adhérents', 'members'),
    ('members.documents.view', 'Consulter les pièces des adhérents', 'members'),
    ('contributions.view', 'Consulter les cotisations', 'contributions'),
    ('contributions.manage', 'Gérer les cotisations', 'contributions'),
    ('contributions.export', 'Exporter les cotisations', 'contributions'),
    ('finance.view', 'Consulter les finances', 'finance'),
    ('finance.control', 'Contrôler les finances', 'finance'),
    ('disbursement.initiate', 'Initier un décaissement', 'finance'),
    ('disbursement.approve', 'Approuver un décaissement', 'finance'),
    ('disbursement.pay', 'Payer un décaissement', 'finance'),
    ('solidarity.view', 'Consulter la solidarité', 'solidarity'),
    ('solidarity.manage', 'Gérer la solidarité', 'solidarity'),
    ('social.view', 'Consulter les demandes sociales', 'social'),
    ('social.manage', 'Gérer les demandes sociales', 'social'),
    ('social.decide', 'Décider des demandes sociales', 'social'),
    ('social.medical.view', 'Consulter les données médicales', 'social'),
    ('employment.view', 'Consulter l''emploi', 'employment'),
    ('employment.manage', 'Gérer l''emploi', 'employment'),
    ('partners.view', 'Consulter les partenaires', 'partners'),
    ('partners.manage', 'Gérer les partenaires', 'partners'),
    ('governance.view', 'Consulter la gouvernance', 'governance'),
    ('governance.manage', 'Gérer la gouvernance', 'governance'),
    ('delegates.view', 'Consulter les délégués', 'delegates'),
    ('delegates.manage', 'Gérer les délégués', 'delegates'),
    ('delegates.report', 'Remettre un rapport de délégué', 'delegates'),
    ('discipline.view', 'Consulter la discipline', 'discipline'),
    ('discipline.manage', 'Gérer la discipline', 'discipline'),
    ('discipline.decide', 'Décider en discipline', 'discipline'),
    ('reports.view', 'Consulter les rapports', 'reports'),
    ('reports.export', 'Exporter les rapports', 'reports');

INSERT INTO roles (name, label, is_system) VALUES
    ('super_admin', 'Super administrateur technique', 1),
    ('admin_general', 'Administrateur général', 0),
    ('pca', 'Président du Conseil d''administration', 0),
    ('vice_president', 'Premier Vice-président', 0),
    ('secretaire_general', 'Secrétaire général', 0),
    ('tresorier_general', 'Trésorier général', 0),
    ('resp_communication', 'Responsable de la communication', 0),
    ('resp_commission', 'Responsable de commission', 0),
    ('delegue', 'Délégué', 0),
    ('point_focal', 'Point focal', 0),
    ('controleur', 'Contrôleur', 0),
    ('gest_adherents', 'Gestionnaire des adhérents', 0),
    ('gest_social', 'Gestionnaire des demandes sociales', 0),
    ('gest_cms', 'Gestionnaire du CMS', 0),
    ('lecture_seule', 'Utilisateur en lecture seule', 0),
    ('admin_site', 'Administrateur du site', 0),
    ('editeur', 'Éditeur', 0),
    ('redacteur', 'Rédacteur', 0),
    ('gest_medias', 'Gestionnaire des médias', 0);

-- super_admin : toutes les permissions sauf les 7 décisions/données sensibles.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.name = 'super_admin' AND p.name NOT IN ('disbursement.approve', 'disbursement.pay', 'social.decide', 'discipline.decide', 'settings.validate', 'members.documents.view', 'social.medical.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'users.manage', 'roles.manage', 'audit.view', 'settings.manage', 'cms.pages.manage', 'cms.publish', 'cms.media.manage', 'cms.forms.manage', 'cms.settings.manage', 'members.view', 'members.create', 'members.update', 'members.validate', 'members.export', 'contributions.view', 'finance.view', 'solidarity.view', 'social.view', 'employment.view', 'employment.manage', 'partners.view', 'partners.manage', 'governance.view', 'governance.manage', 'delegates.view', 'delegates.manage', 'delegates.report', 'discipline.view', 'reports.view', 'reports.export')
WHERE r.name = 'admin_general';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'audit.view', 'members.view', 'contributions.view', 'finance.view', 'solidarity.view', 'social.view', 'social.decide', 'employment.view', 'partners.view', 'governance.view', 'delegates.view', 'discipline.view', 'discipline.decide', 'settings.validate', 'reports.view', 'reports.export')
WHERE r.name = 'pca';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'contributions.view', 'finance.view', 'solidarity.view', 'social.view', 'employment.view', 'partners.view', 'governance.view', 'governance.manage', 'delegates.view', 'delegates.manage', 'delegates.report', 'discipline.view', 'reports.view', 'reports.export')
WHERE r.name = 'vice_president';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.pages.manage', 'members.view', 'members.create', 'members.update', 'members.validate', 'members.export', 'contributions.view', 'finance.view', 'disbursement.initiate', 'solidarity.view', 'social.view', 'employment.view', 'partners.view', 'partners.manage', 'governance.view', 'governance.manage', 'delegates.view', 'delegates.manage', 'delegates.report', 'discipline.view', 'discipline.manage', 'reports.view', 'reports.export')
WHERE r.name = 'secretaire_general';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'contributions.view', 'contributions.manage', 'contributions.export', 'finance.view', 'disbursement.initiate', 'solidarity.view', 'solidarity.manage', 'social.view', 'partners.view', 'governance.view', 'reports.view', 'reports.export')
WHERE r.name = 'tresorier_general';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.pages.manage', 'cms.publish', 'cms.media.manage', 'cms.forms.manage', 'cms.settings.manage', 'employment.view', 'employment.manage', 'partners.view', 'reports.view')
WHERE r.name = 'resp_communication';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'governance.view', 'reports.view')
WHERE r.name = 'resp_commission';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'members.create', 'social.view', 'delegates.report')
WHERE r.name = 'delegue';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'members.create', 'social.view', 'delegates.report')
WHERE r.name = 'point_focal';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'audit.view', 'members.view', 'contributions.view', 'contributions.export', 'finance.view', 'finance.control', 'solidarity.view', 'social.view', 'employment.view', 'partners.view', 'governance.view', 'delegates.view', 'discipline.view', 'reports.view', 'reports.export')
WHERE r.name = 'controleur';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'members.create', 'members.update', 'members.validate', 'members.export', 'members.documents.view', 'contributions.view', 'delegates.view', 'reports.view')
WHERE r.name = 'gest_adherents';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'contributions.view', 'disbursement.initiate', 'solidarity.view', 'social.view', 'social.manage', 'social.medical.view', 'employment.view', 'reports.view')
WHERE r.name = 'gest_social';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.pages.manage', 'cms.publish', 'cms.media.manage', 'cms.forms.manage')
WHERE r.name = 'gest_cms';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'members.view', 'contributions.view', 'finance.view', 'solidarity.view', 'employment.view', 'partners.view', 'governance.view', 'delegates.view', 'reports.view')
WHERE r.name = 'lecture_seule';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.pages.manage', 'cms.publish', 'cms.media.manage', 'cms.forms.manage', 'cms.settings.manage')
WHERE r.name = 'admin_site';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.pages.manage', 'cms.publish', 'cms.media.manage')
WHERE r.name = 'editeur';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.pages.manage')
WHERE r.name = 'redacteur';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name IN (
    'cms.media.manage')
WHERE r.name = 'gest_medias';
