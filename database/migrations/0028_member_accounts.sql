-- L15 : comptes adhérents (Espace adhérent), séparés de `users` (deux mondes d'authentification).
-- Écarts voulus au §3.10 de 04-base-de-données : pas de login_phone/login_email (lus dans la fiche à la connexion),
-- pas de failed_attempts/locked_until (limitation par login_attempts, comme L1), pas d'état « verrouille ».
-- Extension des tables sociales de L7 (0025, non modifiée) : un compte adhérent peut être l'auteur d'une demande,
-- d'une pièce ou d'un événement. Testé sur MariaDB 10.11 (base neuve et mise à niveau depuis 0027 avec lignes sociales) ;
-- `MODIFY … NULL` sur une colonne portant une clé étrangère passe sans retirer la clé (voir task-1-report.md).
-- MySQL 8.0.16 non essayé : `DROP CONSTRAINT <check>` n'y existe qu'à partir de 8.0.19 (avant : `DROP CHECK`).
CREATE TABLE member_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    password VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'en_attente',
    session_version INT UNSIGNED NOT NULL DEFAULT 0,
    activated_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_macc_member (member_id),
    KEY idx_macc_created_by (created_by),
    CONSTRAINT fk_macc_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_macc_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_macc_status CHECK (status IN ('en_attente', 'actif', 'desactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_activation_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_account_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    purpose VARCHAR(20) NOT NULL,
    delivery VARCHAR(10) NOT NULL,
    issued_by BIGINT UNSIGNED NULL,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mac_hash (code_hash),
    KEY idx_mac_account (member_account_id, used_at),
    KEY idx_mac_issued_by (issued_by),
    CONSTRAINT fk_mac_account FOREIGN KEY (member_account_id) REFERENCES member_accounts (id),
    CONSTRAINT fk_mac_issued_by FOREIGN KEY (issued_by) REFERENCES users (id),
    CONSTRAINT ck_mac_purpose CHECK (purpose IN ('activation', 'reinitialisation', 'oubli')),
    CONSTRAINT ck_mac_delivery CHECK (delivery IN ('email', 'lettre'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_change_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    member_account_id BIGINT UNSIGNED NOT NULL,
    fields JSON NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'soumise',
    member_message TEXT NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mcr_member (member_id, status),
    KEY idx_mcr_status (status, created_at),
    KEY idx_mcr_account (member_account_id),
    KEY idx_mcr_reviewed_by (reviewed_by),
    CONSTRAINT fk_mcr_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_mcr_account FOREIGN KEY (member_account_id) REFERENCES member_accounts (id),
    CONSTRAINT fk_mcr_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id),
    CONSTRAINT ck_mcr_status CHECK (status IN ('soumise', 'acceptee', 'refusee'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(30) NOT NULL,
    subject_ref VARCHAR(30) NULL,
    label VARCHAR(40) NOT NULL,
    link VARCHAR(120) NOT NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_mm_member (member_id, read_at),
    CONSTRAINT fk_mm_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT ck_mm_kind CHECK (kind IN ('demande_avancee', 'correction_traitee'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extension sociale : demande (auteur personnel ou compte adhérent, canal espace_adherent).
ALTER TABLE social_requests MODIFY created_by BIGINT UNSIGNED NULL;

ALTER TABLE social_requests ADD COLUMN created_by_account_id BIGINT UNSIGNED NULL AFTER created_by;

ALTER TABLE social_requests ADD KEY idx_sr_created_by_account (created_by_account_id);

ALTER TABLE social_requests ADD CONSTRAINT fk_sr_created_by_account FOREIGN KEY (created_by_account_id) REFERENCES member_accounts (id);

ALTER TABLE social_requests ADD CONSTRAINT ck_sr_author CHECK (created_by IS NOT NULL OR created_by_account_id IS NOT NULL);

ALTER TABLE social_requests DROP CONSTRAINT ck_sr_channel;

ALTER TABLE social_requests ADD CONSTRAINT ck_sr_channel2 CHECK (channel IN ('guichet', 'delegue', 'telephone', 'courrier', 'email', 'espace_adherent'));

-- Extension sociale : pièces (exactement un auteur).
ALTER TABLE social_request_documents MODIFY uploaded_by BIGINT UNSIGNED NULL;

ALTER TABLE social_request_documents ADD COLUMN uploaded_by_account_id BIGINT UNSIGNED NULL AFTER uploaded_by;

ALTER TABLE social_request_documents ADD KEY idx_srd_uploaded_by_account (uploaded_by_account_id);

ALTER TABLE social_request_documents ADD CONSTRAINT fk_srd_uploaded_by_account FOREIGN KEY (uploaded_by_account_id) REFERENCES member_accounts (id);

ALTER TABLE social_request_documents ADD CONSTRAINT ck_srd_author CHECK ((uploaded_by IS NULL) <> (uploaded_by_account_id IS NULL));

-- Extension sociale : événements (au moins un acteur).
ALTER TABLE social_request_events MODIFY user_id BIGINT UNSIGNED NULL;

ALTER TABLE social_request_events ADD COLUMN member_account_id BIGINT UNSIGNED NULL AFTER user_id;

ALTER TABLE social_request_events ADD KEY idx_sre_account (member_account_id);

ALTER TABLE social_request_events ADD CONSTRAINT fk_sre_account FOREIGN KEY (member_account_id) REFERENCES member_accounts (id);

ALTER TABLE social_request_events ADD CONSTRAINT ck_sre_actor CHECK (user_id IS NOT NULL OR member_account_id IS NOT NULL);
