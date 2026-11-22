-- Permissions des demandes sociales (L7) ; social.view/manage/decide/medical.view existent depuis 0005.
INSERT INTO permissions (name, label, module) VALUES
    ('social.create', 'Enregistrer une demande d''assistance', 'social'),
    ('social.export', 'Exporter les demandes d''assistance', 'social');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'social.create'
WHERE r.name IN ('gest_social', 'delegue', 'point_focal');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.name = 'social.export'
WHERE r.name = 'gest_social';

-- Paramètres non configurés : aucun délai, purge ni contrôle d'éligibilité n'est appliqué tant qu'ils ne sont pas validés.
INSERT IGNORE INTO settings (`group`, `key`, `value`, status) VALUES
    ('social', 'reference_format', '"AS-{YYYY}-{NNNN}"', 'non_configure'),
    ('social', 'processing_deadline_days', NULL, 'non_configure'),
    ('social', 'retention_months', NULL, 'non_configure'),
    ('social', 'eligibility_rule', NULL, 'non_configure');

-- Domaines de l'article 9 (texte du cahier des charges) ; tous les paramètres restent NULL.
INSERT INTO social_request_types (code, name, action_text) VALUES
    ('sante', 'Santé', 'Information, orientation sanitaire et dispositifs d''accès aux soins.'),
    ('deces', 'Décès', 'Soutien à la famille du membre décédé selon les conditions prévues.'),
    ('maladie', 'Maladie', 'Assistance exceptionnelle après examen du dossier.'),
    ('famille', 'Famille', 'Accompagnement des ayants droit dans les limites des règles adoptées.'),
    ('emploi', 'Emploi', 'Mise en relation avec les armements et diffusion d''opportunités.'),
    ('formation', 'Formation', 'Information et orientation vers les formations professionnelles.'),
    ('retraite', 'Retraite', 'Accompagnement des anciens marins.'),
    ('urgences', 'Urgences', 'Étude des demandes d''aide en situation sociale urgente.');
