-- Adhésion en ligne (L4) : pièces téléversées pendant une demande pas encore envoyée, liées au jeton du brouillon (session).
-- À l'envoi, les lignes du jeton passent dans membership_application_files puis sont supprimées ; la tâche cron
-- site.cleanup_apply_drafts supprime les pièces abandonnées de plus de 24 h (ligne, fichier en base puis fichier disque).
CREATE TABLE apply_draft_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(30) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_adf_file (file_id),
    KEY idx_adf_token (token),
    KEY idx_adf_created (created_at),
    CONSTRAINT fk_adf_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_adf_type FOREIGN KEY (type) REFERENCES member_document_types (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
