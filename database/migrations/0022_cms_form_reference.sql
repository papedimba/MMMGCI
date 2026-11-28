-- Numéro de référence des formulaires reçus (MSG-AAAA-NNNNN) : seul identifiant écrit dans le journal d'audit et les e-mails de notification.
ALTER TABLE cms_form_submissions
    ADD COLUMN reference VARCHAR(30) NULL AFTER form_id,
    ADD UNIQUE KEY uq_cms_fs_reference (reference);
