-- CMS du site public (L3, 04-base-de-donnees.md §3.2). Clés utilisateur sans FK (comme au socle) ; FK vers files en RESTRICT.
-- Ajouts au §3.2 : cms_pages.submitted_by/published_by/updated_at/published_blocks (version publiée conservée pendant une relecture),
-- cms_page_redirects, cms_media.credit, réglages site.*. Aucun contenu inventé : seules les deux lignes structurelles de cms_menus.

CREATE TABLE cms_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_categories_slug (slug),
    UNIQUE KEY uq_cms_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_tags_slug (slug),
    UNIQUE KEY uq_cms_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    template VARCHAR(40) NOT NULL DEFAULT 'default',
    blocks JSON NULL,
    published_blocks JSON NULL,
    excerpt TEXT NULL,
    featured_file_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    published_at DATETIME NULL,
    seo_title VARCHAR(70) NULL,
    seo_description VARCHAR(200) NULL,
    og_file_id BIGINT UNSIGNED NULL,
    canonical_url VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    author_id BIGINT UNSIGNED NULL,
    submitted_by BIGINT UNSIGNED NULL,
    published_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_pages_slug (slug),
    KEY idx_cms_pages_status_published (status, published_at),
    KEY idx_cms_pages_parent (parent_id),
    CONSTRAINT fk_cms_pages_parent FOREIGN KEY (parent_id) REFERENCES cms_pages (id),
    CONSTRAINT fk_cms_pages_featured FOREIGN KEY (featured_file_id) REFERENCES files (id),
    CONSTRAINT fk_cms_pages_og FOREIGN KEY (og_file_id) REFERENCES files (id),
    CONSTRAINT ck_cms_pages_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive')),
    CONSTRAINT ck_cms_pages_blocks CHECK (blocks IS NULL OR JSON_VALID(blocks)),
    CONSTRAINT ck_cms_pages_pblocks CHECK (published_blocks IS NULL OR JSON_VALID(published_blocks))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_page_redirects (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    old_slug VARCHAR(80) NOT NULL,
    page_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_page_redirects_old (old_slug),
    KEY idx_cms_page_redirects_page (page_id),
    CONSTRAINT fk_cms_redirects_page FOREIGN KEY (page_id) REFERENCES cms_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_menus (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    location VARCHAR(30) NOT NULL,
    UNIQUE KEY uq_cms_menus_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Emplacements structurels (aucun contenu) : les éléments de menu sont saisis par l'administration.
INSERT IGNORE INTO cms_menus (name, location) VALUES ('Menu principal', 'header'), ('Pied de page', 'footer');

CREATE TABLE cms_menu_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_id BIGINT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    label VARCHAR(120) NOT NULL,
    page_id BIGINT UNSIGNED NULL,
    url VARCHAR(255) NULL,
    target VARCHAR(10) NOT NULL DEFAULT '_self',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_cms_menu_items_order (menu_id, sort_order),
    KEY idx_cms_menu_items_parent (parent_id),
    KEY idx_cms_menu_items_page (page_id),
    CONSTRAINT fk_cms_mi_menu FOREIGN KEY (menu_id) REFERENCES cms_menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_mi_parent FOREIGN KEY (parent_id) REFERENCES cms_menu_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_mi_page FOREIGN KEY (page_id) REFERENCES cms_pages (id),
    CONSTRAINT ck_cms_mi_target CHECK (target IN ('_self', '_blank'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_articles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    body MEDIUMTEXT NULL,
    excerpt TEXT NULL,
    featured_file_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    author_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    published_at DATETIME NULL,
    archived_at DATETIME NULL,
    seo_title VARCHAR(70) NULL,
    seo_description VARCHAR(200) NULL,
    og_file_id BIGINT UNSIGNED NULL,
    canonical_url VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_articles_slug (slug),
    KEY idx_cms_articles_status_published (status, published_at),
    KEY idx_cms_articles_category (category_id),
    CONSTRAINT fk_cms_articles_featured FOREIGN KEY (featured_file_id) REFERENCES files (id),
    CONSTRAINT fk_cms_articles_og FOREIGN KEY (og_file_id) REFERENCES files (id),
    CONSTRAINT fk_cms_articles_category FOREIGN KEY (category_id) REFERENCES cms_categories (id),
    CONSTRAINT ck_cms_articles_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_article_tag (
    article_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    KEY idx_cms_article_tag_tag (tag_id),
    CONSTRAINT fk_cms_at_article FOREIGN KEY (article_id) REFERENCES cms_articles (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_at_tag FOREIGN KEY (tag_id) REFERENCES cms_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_media (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    alt VARCHAR(255) NULL,
    caption VARCHAR(255) NULL,
    credit VARCHAR(160) NULL,
    folder VARCHAR(80) NULL,
    uploaded_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_media_file (file_id),
    KEY idx_cms_media_folder (folder),
    CONSTRAINT fk_cms_media_file FOREIGN KEY (file_id) REFERENCES files (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    description TEXT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NULL,
    location VARCHAR(200) NULL,
    featured_file_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_events_slug (slug),
    KEY idx_cms_events_status_published (status, published_at),
    KEY idx_cms_events_starts (starts_at),
    CONSTRAINT fk_cms_events_featured FOREIGN KEY (featured_file_id) REFERENCES files (id),
    CONSTRAINT ck_cms_events_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_documents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    category VARCHAR(80) NULL,
    file_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cms_documents_status_published (status, published_at),
    KEY idx_cms_documents_file (file_id),
    CONSTRAINT fk_cms_documents_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT ck_cms_documents_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_galleries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_galleries_slug (slug),
    KEY idx_cms_galleries_status_published (status, published_at),
    CONSTRAINT ck_cms_galleries_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_gallery_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gallery_id BIGINT UNSIGNED NOT NULL,
    media_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_cms_gi_order (gallery_id, sort_order),
    KEY idx_cms_gi_media (media_id),
    CONSTRAINT fk_cms_gi_gallery FOREIGN KEY (gallery_id) REFERENCES cms_galleries (id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_gi_media FOREIGN KEY (media_id) REFERENCES cms_media (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_banners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(160) NOT NULL,
    body VARCHAR(255) NULL,
    link_url VARCHAR(255) NULL,
    file_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cms_banners_status (status, sort_order),
    CONSTRAINT fk_cms_banners_file FOREIGN KEY (file_id) REFERENCES files (id),
    CONSTRAINT ck_cms_banners_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_testimonials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_name VARCHAR(120) NOT NULL,
    author_role VARCHAR(120) NULL,
    body TEXT NOT NULL,
    consent_file_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'brouillon',
    published_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cms_testimonials_status_published (status, published_at),
    CONSTRAINT fk_cms_testimonials_consent FOREIGN KEY (consent_file_id) REFERENCES files (id),
    CONSTRAINT ck_cms_testimonials_status CHECK (status IN ('brouillon', 'en_relecture', 'publie', 'programme', 'archive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_forms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_key VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_cms_forms_key (form_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cms_form_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    form_id BIGINT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'nouveau',
    assigned_to BIGINT UNSIGNED NULL,
    history JSON NULL,
    ip VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cms_fs_form_status (form_id, status),
    KEY idx_cms_fs_created (created_at),
    CONSTRAINT fk_cms_fs_form FOREIGN KEY (form_id) REFERENCES cms_forms (id),
    CONSTRAINT ck_cms_fs_status CHECK (status IN ('nouveau', 'en_cours', 'traite', 'archive')),
    CONSTRAINT ck_cms_fs_payload CHECK (JSON_VALID(payload))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réglages publics : vides et non validés (aucune coordonnée inventée).
INSERT IGNORE INTO settings (`group`, `key`, `value`, status) VALUES
    ('site', 'contact_email', NULL, 'non_configure'),
    ('site', 'contact_phone', NULL, 'non_configure'),
    ('site', 'address', NULL, 'non_configure'),
    ('site', 'social_facebook', NULL, 'non_configure'),
    ('site', 'social_whatsapp', NULL, 'non_configure'),
    ('site', 'consent_text', NULL, 'non_configure'),
    ('site', 'adhesion_open', NULL, 'non_configure'),
    ('site', 'testimonials_enabled', NULL, 'non_configure'),
    ('site', 'forms_retention_months', NULL, 'non_configure');
