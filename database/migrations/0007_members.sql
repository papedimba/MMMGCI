-- Adhérents (L5, 04-base-de-donnees.md §3.3). Clés utilisateur (verified_by, changed_by, reviewer_id, printed_by) sans FK, comme au socle ; delegate_id sans FK (table du lot L10).

CREATE TABLE member_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_member_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE maritime_professions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_maritime_professions_parent (parent_id),
    CONSTRAINT fk_mp_parent FOREIGN KEY (parent_id) REFERENCES maritime_professions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- parent_id : la profession dont la spécialité relève (facultatif).
CREATE TABLE maritime_specialties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_maritime_specialties_parent (parent_id),
    CONSTRAINT fk_ms_parent FOREIGN KEY (parent_id) REFERENCES maritime_professions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'localite',
    parent_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_zones_parent (parent_id),
    CONSTRAINT fk_zones_parent FOREIGN KEY (parent_id) REFERENCES zones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_statuses (
    code VARCHAR(30) NOT NULL PRIMARY KEY,
    label VARCHAR(80) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_proposed TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_document_types (
    code VARCHAR(30) NOT NULL PRIMARY KEY,
    label VARCHAR(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_number VARCHAR(30) NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    first_names VARCHAR(160) NOT NULL,
    sex VARCHAR(10) NULL,
    birth_date DATE NULL,
    birth_place VARCHAR(120) NULL,
    nationality VARCHAR(80) NULL,
    phone VARCHAR(30) NULL,
    phone_alt VARCHAR(30) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    zone_id BIGINT UNSIGNED NULL,
    photo_file_id BIGINT UNSIGNED NULL,
    id_document_type VARCHAR(30) NULL,
    id_document_number VARCHAR(60) NULL,
    category_id BIGINT UNSIGNED NULL,
    profession_id BIGINT UNSIGNED NULL,
    specialty_id BIGINT UNSIGNED NULL,
    employment_status VARCHAR(60) NULL,
    employer VARCHAR(160) NULL,
    seaman_book_number VARCHAR(60) NULL,
    seaman_book_expiry DATE NULL,
    joined_at DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'actif',
    status_changed_at TIMESTAMP NULL,
    delegate_id BIGINT UNSIGNED NULL,
    anonymized_at TIMESTAMP NULL,
    deleted_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_members_number (member_number),
    KEY idx_members_name (last_name, first_names),
    KEY idx_members_status (status),
    KEY idx_members_zone (zone_id),
    KEY idx_members_phone (phone),
    FULLTEXT KEY ft_members_name (last_name, first_names),
    CONSTRAINT fk_members_zone FOREIGN KEY (zone_id) REFERENCES zones (id),
    CONSTRAINT fk_members_photo FOREIGN KEY (photo_file_id) REFERENCES files (id),
    CONSTRAINT fk_members_category FOREIGN KEY (category_id) REFERENCES member_categories (id),
    CONSTRAINT fk_members_profession FOREIGN KEY (profession_id) REFERENCES maritime_professions (id),
    CONSTRAINT fk_members_specialty FOREIGN KEY (specialty_id) REFERENCES maritime_specialties (id),
    CONSTRAINT fk_members_status FOREIGN KEY (status) REFERENCES member_statuses (code),
    CONSTRAINT fk_members_idtype FOREIGN KEY (id_document_type) REFERENCES member_document_types (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_beneficiaries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    last_name VARCHAR(120) NOT NULL,
    first_names VARCHAR(160) NOT NULL,
    relationship VARCHAR(60) NOT NULL,
    birth_date DATE NULL,
    phone VARCHAR(30) NULL,
    file_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_mb_member (member_id),
    CONSTRAINT fk_mb_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_mb_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(30) NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    verified_by BIGINT UNSIGNED NULL,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_md_member (member_id),
    CONSTRAINT fk_md_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_md_type FOREIGN KEY (type) REFERENCES member_document_types (code),
    CONSTRAINT fk_md_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajout seul : aucune route ne modifie ni ne supprime une ligne.
CREATE TABLE member_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NOT NULL,
    reason TEXT NULL,
    decision_ref VARCHAR(100) NULL,
    changed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_msh_member (member_id, created_at),
    CONSTRAINT fk_msh_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_applications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'soumise',
    reviewer_id BIGINT UNSIGNED NULL,
    rejection_reason TEXT NULL,
    member_id BIGINT UNSIGNED NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'saisie', -- en_ligne, saisie, delegue
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ma_reference (reference),
    KEY idx_ma_status (status),
    CONSTRAINT fk_ma_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_application_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(30) NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    KEY idx_maf_application (application_id),
    CONSTRAINT fk_maf_application FOREIGN KEY (application_id) REFERENCES membership_applications (id),
    CONSTRAINT fk_maf_type FOREIGN KEY (type) REFERENCES member_document_types (code),
    CONSTRAINT fk_maf_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE membership_cards (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    card_number VARCHAR(30) NOT NULL,
    verify_token CHAR(64) NOT NULL, -- 32 octets aléatoires en hexadécimal
    issued_at TIMESTAMP NULL,
    expires_at DATE NULL,
    revoked_at TIMESTAMP NULL,
    printed_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mc_token (verify_token),
    UNIQUE KEY uq_mc_number (card_number),
    KEY idx_mc_member (member_id),
    CONSTRAINT fk_mc_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rattachement des comptes : zone (périmètre des adhérents) et fiche adhérent (delegate_id reste sans FK).
ALTER TABLE users
    ADD COLUMN zone_id BIGINT UNSIGNED NULL AFTER delegate_id,
    ADD CONSTRAINT fk_users_zone FOREIGN KEY (zone_id) REFERENCES zones (id),
    ADD CONSTRAINT fk_users_member FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE SET NULL;

-- Graines : art. 5 (catégories) et art. 6 (pièces). Statuts « proposés » : à valider par les instances. Aucune profession ni zone.
INSERT INTO member_categories (name) VALUES
    ('Marins de la marine marchande'), ('Gens de mer'), ('Personnels navigants et assimilés'), ('Anciens marins');

INSERT INTO member_statuses (code, label, is_active, is_proposed) VALUES
    ('actif', 'Actif', 1, 1), ('suspendu', 'Suspendu', 1, 1), ('radie', 'Radié', 1, 1), ('decede', 'Décédé', 1, 1), ('ancien_marin', 'Ancien marin', 1, 1);

INSERT INTO member_document_types (code, label) VALUES
    ('identite', 'Pièce d''identité'), ('livret_maritime', 'Livret maritime'), ('photo', 'Photographie'), ('autre', 'Autre pièce');
