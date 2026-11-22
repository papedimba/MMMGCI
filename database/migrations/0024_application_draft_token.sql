-- Adhésion en ligne (L4) : jeton du brouillon consommé en base. Unique : un même brouillon ne crée qu'une demande,
-- même sans verrou de session (second envoi simultané : transaction annulée, même accusé). NULL pour les demandes saisies.
ALTER TABLE membership_applications
    ADD COLUMN draft_token CHAR(64) NULL AFTER ip,
    ADD UNIQUE KEY uq_ma_draft_token (draft_token);
