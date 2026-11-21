-- Cotisations et finances (L6, 04-base-de-donnees.md §3.4). Clés utilisateur sans FK (comme au socle) ; budgets, budget_lines et commitments reportés.
-- Ajouts au §3.4 pour les tâches suivantes : contribution_calls.cancelled_at/cancel_reason, payments.cancelled_at/cancel_reason,
-- disbursement_requests.internal_note (champ non substantiel), financial_attachments.uploaded_by.

CREATE TABLE financial_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'general',
    currency CHAR(3) NOT NULL DEFAULT 'XOF',
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_financial_accounts_name (name),
    CONSTRAINT ck_fa_type CHECK (type IN ('general', 'solidarity_fund', 'bank', 'cash', 'mobile_money'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_financial_categories (direction, name),
    KEY idx_financial_categories_parent (parent_id),
    CONSTRAINT fk_fc_parent FOREIGN KEY (parent_id) REFERENCES financial_categories (id),
    CONSTRAINT ck_fc_direction CHECK (direction IN ('recette', 'depense'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contribution_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    amount DECIMAL(15,2) NULL,
    periodicity VARCHAR(30) NULL,
    authorization_ref VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'non_configure',
    affects_account_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contribution_types_name (name),
    KEY idx_contribution_types_account (affects_account_id),
    CONSTRAINT fk_ct_account FOREIGN KEY (affects_account_id) REFERENCES financial_accounts (id),
    CONSTRAINT ck_ct_kind CHECK (kind IN ('droit_adhesion', 'periodique', 'exceptionnelle')),
    CONSTRAINT ck_ct_status CHECK (status IN ('non_configure', 'actif')),
    CONSTRAINT ck_ct_amount CHECK (amount IS NULL OR amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contribution_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id BIGINT UNSIGNED NOT NULL,
    contribution_type_id BIGINT UNSIGNED NOT NULL,
    period_label VARCHAR(60) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    amount_due DECIMAL(15,2) NOT NULL,
    due_date DATE NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'appelee',
    cancelled_at TIMESTAMP NULL,
    cancel_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_contribution_calls (member_id, contribution_type_id, period_start),
    KEY idx_contribution_calls_type (contribution_type_id),
    KEY idx_contribution_calls_status_due (status, due_date),
    CONSTRAINT fk_cc_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_cc_type FOREIGN KEY (contribution_type_id) REFERENCES contribution_types (id),
    CONSTRAINT ck_cc_status CHECK (status IN ('appelee', 'partielle', 'payee', 'annulee')),
    CONSTRAINT ck_cc_amount CHECK (amount_due > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    direction VARCHAR(10) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    occurred_at DATETIME NOT NULL,
    source_type VARCHAR(30) NULL,
    source_id BIGINT UNSIGNED NULL,
    donor_partner_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NULL,
    authorization_ref VARCHAR(100) NULL,
    reversal_of_id BIGINT UNSIGNED NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ft_reference (reference),
    UNIQUE KEY uq_ft_reversal (reversal_of_id),
    KEY idx_ft_account_date (account_id, occurred_at),
    KEY idx_ft_category (category_id),
    KEY idx_ft_source (source_type, source_id),
    CONSTRAINT fk_ft_account FOREIGN KEY (account_id) REFERENCES financial_accounts (id),
    CONSTRAINT fk_ft_category FOREIGN KEY (category_id) REFERENCES financial_categories (id),
    CONSTRAINT fk_ft_reversal FOREIGN KEY (reversal_of_id) REFERENCES financial_transactions (id),
    CONSTRAINT ck_ft_amount CHECK (amount > 0),
    CONSTRAINT ck_ft_direction CHECK (direction IN ('recette', 'depense'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    contribution_call_id BIGINT UNSIGNED NULL,
    amount DECIMAL(15,2) NOT NULL,
    method VARCHAR(20) NOT NULL,
    external_ref VARCHAR(100) NULL,
    paid_at DATETIME NOT NULL,
    received_by BIGINT UNSIGNED NULL,
    financial_transaction_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    cancelled_at TIMESTAMP NULL,
    cancel_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payments_reference (reference),
    UNIQUE KEY uq_payments_idempotency (idempotency_key),
    KEY idx_payments_member (member_id),
    KEY idx_payments_call (contribution_call_id),
    KEY idx_payments_transaction (financial_transaction_id),
    CONSTRAINT fk_pay_member FOREIGN KEY (member_id) REFERENCES members (id),
    CONSTRAINT fk_pay_call FOREIGN KEY (contribution_call_id) REFERENCES contribution_calls (id),
    CONSTRAINT fk_pay_transaction FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id),
    CONSTRAINT ck_pay_amount CHECK (amount > 0),
    CONSTRAINT ck_pay_method CHECK (method IN ('especes', 'mobile_money', 'virement', 'cheque'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    receipt_number VARCHAR(40) NOT NULL,
    file_id BIGINT UNSIGNED NULL,
    issued_at DATETIME NOT NULL,
    cancelled_at TIMESTAMP NULL,
    cancel_reason TEXT NULL,
    UNIQUE KEY uq_receipts_payment (payment_id),
    UNIQUE KEY uq_receipts_number (receipt_number),
    KEY idx_receipts_file (file_id),
    CONSTRAINT fk_rcp_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_rcp_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE financial_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attachable_type VARCHAR(30) NOT NULL,
    attachable_id BIGINT UNSIGNED NOT NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(160) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fatt_target (attachable_type, attachable_id),
    KEY idx_fatt_file (file_id),
    CONSTRAINT fk_fatt_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disbursement_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference VARCHAR(40) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    beneficiary_name VARCHAR(160) NOT NULL,
    beneficiary_member_id BIGINT UNSIGNED NULL,
    purpose TEXT NOT NULL,
    internal_note TEXT NULL,
    social_decision_id BIGINT UNSIGNED NULL,
    commitment_id BIGINT UNSIGNED NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    status VARCHAR(10) NOT NULL DEFAULT 'brouillon',
    initiated_by BIGINT UNSIGNED NOT NULL,
    submitted_at TIMESTAMP NULL,
    rejected_by BIGINT UNSIGNED NULL,
    rejection_reason TEXT NULL,
    cancelled_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dr_reference (reference),
    KEY idx_dr_status (status),
    KEY idx_dr_account (account_id),
    KEY idx_dr_category (category_id),
    KEY idx_dr_member (beneficiary_member_id),
    CONSTRAINT fk_dr_account FOREIGN KEY (account_id) REFERENCES financial_accounts (id),
    CONSTRAINT fk_dr_category FOREIGN KEY (category_id) REFERENCES financial_categories (id),
    CONSTRAINT fk_dr_member FOREIGN KEY (beneficiary_member_id) REFERENCES members (id),
    CONSTRAINT ck_dr_amount CHECK (amount > 0),
    CONSTRAINT ck_dr_status CHECK (status IN ('brouillon', 'soumis', 'approuve_1', 'approuve_2', 'rejete', 'annule', 'paye'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disbursement_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disbursement_request_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    changed_by BIGINT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drev (disbursement_request_id, revision),
    CONSTRAINT fk_drev_request FOREIGN KEY (disbursement_request_id) REFERENCES disbursement_requests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disbursement_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disbursement_request_id BIGINT UNSIGNED NOT NULL,
    revision INT UNSIGNED NOT NULL,
    approver_id BIGINT UNSIGNED NOT NULL,
    decision VARCHAR(10) NOT NULL,
    comment VARCHAR(500) NULL,
    decided_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invalidated_at TIMESTAMP NULL,
    UNIQUE KEY uq_da (disbursement_request_id, revision, approver_id),
    CONSTRAINT fk_da_request FOREIGN KEY (disbursement_request_id) REFERENCES disbursement_requests (id),
    CONSTRAINT ck_da_decision CHECK (decision IN ('approve', 'reject'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE disbursement_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    disbursement_request_id BIGINT UNSIGNED NOT NULL,
    paid_at DATETIME NOT NULL,
    method VARCHAR(20) NOT NULL,
    external_ref VARCHAR(100) NULL,
    paid_by BIGINT UNSIGNED NOT NULL,
    financial_transaction_id BIGINT UNSIGNED NULL,
    proof_file_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dp_request (disbursement_request_id),
    KEY idx_dp_transaction (financial_transaction_id),
    KEY idx_dp_proof (proof_file_id),
    CONSTRAINT fk_dp_request FOREIGN KEY (disbursement_request_id) REFERENCES disbursement_requests (id),
    CONSTRAINT fk_dp_transaction FOREIGN KEY (financial_transaction_id) REFERENCES financial_transactions (id),
    CONSTRAINT fk_dp_proof FOREIGN KEY (proof_file_id) REFERENCES files (id),
    CONSTRAINT ck_dp_method CHECK (method IN ('especes', 'mobile_money', 'virement', 'cheque'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    min_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    max_amount DECIMAL(15,2) NULL,
    approvals_required INT UNSIGNED NOT NULL DEFAULT 2,
    eligible_permission VARCHAR(100) NOT NULL DEFAULT 'disbursement.approve',
    decision_ref VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT ck_ar_required CHECK (approvals_required >= 2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Graines : ressources de l'art. 13 (recettes) ; aucune catégorie de dépense, aucun compte, aucun montant.
INSERT INTO financial_categories (name, direction) VALUES
    ('Droits d''adhésion', 'recette'),
    ('Cotisations périodiques', 'recette'),
    ('Contributions exceptionnelles', 'recette'),
    ('Dons, legs et subventions', 'recette'),
    ('Contributions des partenaires', 'recette'),
    ('Produits d''activités autorisées', 'recette');

-- Règle par défaut technique : deux approbations, sans décision d'instance (decision_ref NULL).
INSERT INTO approval_rules (min_amount, max_amount, approvals_required, eligible_permission, decision_ref)
VALUES (0, NULL, 2, 'disbursement.approve', NULL);
