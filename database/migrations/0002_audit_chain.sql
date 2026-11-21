-- Ligne unique verrouillée par log() pour sérialiser la chaîne d'audit (sans verrou de plage sur audit_logs).
CREATE TABLE audit_chain (
    id TINYINT UNSIGNED PRIMARY KEY,
    last_id BIGINT UNSIGNED NULL,
    last_hash CHAR(64) NOT NULL,
    row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Amorcée depuis le journal existant s'il n'est pas vide, sinon depuis la genèse.
INSERT INTO audit_chain (id, last_id, last_hash, row_count) SELECT 1,
    (SELECT MAX(id) FROM audit_logs),
    COALESCE((SELECT hash FROM audit_logs ORDER BY id DESC LIMIT 1), REPEAT('0', 64)),
    (SELECT COUNT(*) FROM audit_logs);

-- Le texte JSON est haché tel que stocké : MySQL 8 réécrit le type JSON (ordre des clés, espaces), pas LONGTEXT.
ALTER TABLE audit_logs
    MODIFY old_values LONGTEXT NULL,
    MODIFY new_values LONGTEXT NULL,
    ADD CONSTRAINT chk_audit_old_json CHECK (old_values IS NULL OR JSON_VALID(old_values)),
    ADD CONSTRAINT chk_audit_new_json CHECK (new_values IS NULL OR JSON_VALID(new_values));
