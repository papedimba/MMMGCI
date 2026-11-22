-- Demandes d'assistance sociale (CDC art. 9-10) : schéma. Aucune règle inventée : tous les paramètres des types sont NULL (non configuré).
CREATE TABLE social_request_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL,
    name VARCHAR(80) NOT NULL,
    action_text VARCHAR(255) NOT NULL,
    criteria_text TEXT NULL,
    ceiling_amount DECIMAL(15,2) NULL,
    waiting_period_days INT UNSIGNED NULL,
    deciding_body VARCHAR(160) NULL,
    allowed_aid_kinds JSON NULL,
    params_decision_ref VARCHAR(60) NULL,
    params_validated_by BIGINT UNSIGNED NULL,
    params_validated_at TIMESTAMP NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_srt_code (code),
    CONSTRAINT fk_srt_validated_by FOREIGN KEY (params_validated_by) REFERENCES users (id),
    CONSTRAINT ck_srt_ceiling CHECK (ceiling_amount IS NULL OR ceiling_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(30) NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    beneficiary_id BIGINT UNSIGNED NULL,
    type_id BIGINT UNSIGNED NOT NULL,
    received_at DATE NOT NULL,
    channel VARCHAR(20) NOT NULL,
    description TEXT NOT NULL,
    priority VARCHAR(10) NOT NULL DEFAULT 'normale',
    assigned_to BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'recue',
    documents_requested_text TEXT NULL,
    contains_medical_data TINYINT(1) NOT NULL DEFAULT 0,
    closed_at TIMESTAMP NULL,
    closure_note TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sr_reference (reference),
    KEY idx_sr_status (status, priority),
    KEY idx_sr_member (member_id),
    KEY idx_sr_assigned (assigned_to),
    KEY idx_sr_type (type_id),
    KEY idx_sr_beneficiary (beneficiary_id),
    KEY idx_sr_created_by (created_by),
    CONSTRAINT fk_sr_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_sr_beneficiary FOREIGN KEY (beneficiary_id) REFERENCES member_beneficiaries (id),
    CONSTRAINT fk_sr_type FOREIGN KEY (type_id) REFERENCES social_request_types (id),
    CONSTRAINT fk_sr_assigned FOREIGN KEY (assigned_to) REFERENCES users (id),
    CONSTRAINT fk_sr_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT ck_sr_channel CHECK (channel IN ('guichet', 'delegue', 'telephone', 'courrier', 'email')),
    CONSTRAINT ck_sr_priority CHECK (priority IN ('normale', 'urgente')),
    CONSTRAINT ck_sr_status CHECK (status IN ('recue', 'en_instruction', 'en_attente_pieces', 'soumise_decision', 'accordee', 'refusee', 'en_paiement', 'cloturee'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Données de santé, isolées de la demande (lecture réservée à social.medical.view).
CREATE TABLE social_request_medical (
    social_request_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    details TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_srm_updated_by (updated_by),
    CONSTRAINT fk_srm_request FOREIGN KEY (social_request_id) REFERENCES social_requests (id),
    CONSTRAINT fk_srm_updated_by FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_request_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_request_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(10) NOT NULL DEFAULT 'piece',
    label VARCHAR(160) NULL,
    is_medical TINYINT(1) NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_srd_request (social_request_id),
    KEY idx_srd_file (file_id),
    KEY idx_srd_uploaded_by (uploaded_by),
    CONSTRAINT fk_srd_request FOREIGN KEY (social_request_id) REFERENCES social_requests (id),
    CONSTRAINT fk_srd_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT fk_srd_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id),
    CONSTRAINT ck_srd_kind CHECK (kind IN ('piece', 'decision'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_request_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_request_id BIGINT UNSIGNED NOT NULL,
    reviewer_id BIGINT UNSIGNED NOT NULL,
    opinion VARCHAR(12) NOT NULL,
    recommended_amount DECIMAL(15,2) NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_srr_request (social_request_id),
    KEY idx_srr_reviewer (reviewer_id),
    CONSTRAINT fk_srr_request FOREIGN KEY (social_request_id) REFERENCES social_requests (id),
    CONSTRAINT fk_srr_reviewer FOREIGN KEY (reviewer_id) REFERENCES users (id),
    CONSTRAINT ck_srr_opinion CHECK (opinion IN ('favorable', 'defavorable', 'reserve')),
    CONSTRAINT ck_srr_amount CHECK (recommended_amount IS NULL OR recommended_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- governing_body_id et meeting_id seront ajoutés par L10 (gouvernance) avec leurs clés étrangères.
CREATE TABLE social_decisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_request_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(10) NOT NULL,
    aid_kind VARCHAR(20) NULL,
    amount DECIMAL(15,2) NULL,
    deciding_body VARCHAR(160) NOT NULL,
    decision_ref VARCHAR(60) NOT NULL,
    reason TEXT NOT NULL,
    member_message TEXT NOT NULL,
    decided_at DATE NOT NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sd_request (social_request_id),
    KEY idx_sd_recorded_by (recorded_by),
    CONSTRAINT fk_sd_request FOREIGN KEY (social_request_id) REFERENCES social_requests (id),
    CONSTRAINT fk_sd_recorded_by FOREIGN KEY (recorded_by) REFERENCES users (id),
    CONSTRAINT ck_sd_decision CHECK (decision IN ('accordee', 'refusee')),
    CONSTRAINT ck_sd_aid_kind CHECK (aid_kind IS NULL OR aid_kind IN ('financiere', 'orientation', 'accompagnement', 'materielle')),
    CONSTRAINT ck_sd_amount CHECK (amount IS NULL OR amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_request_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_request_id BIGINT UNSIGNED NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NOT NULL,
    action VARCHAR(30) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sre_request (social_request_id, id),
    KEY idx_sre_user (user_id),
    CONSTRAINT fk_sre_request FOREIGN KEY (social_request_id) REFERENCES social_requests (id),
    CONSTRAINT fk_sre_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE social_request_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    social_request_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_srn_request (social_request_id),
    KEY idx_srn_author (author_id),
    CONSTRAINT fk_srn_request FOREIGN KEY (social_request_id) REFERENCES social_requests (id),
    CONSTRAINT fk_srn_author FOREIGN KEY (author_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE disbursement_requests
    ADD KEY idx_dr_social_decision (social_decision_id),
    ADD CONSTRAINT fk_dr_social_decision FOREIGN KEY (social_decision_id) REFERENCES social_decisions (id);
