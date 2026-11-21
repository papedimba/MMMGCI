-- L3 tâche 6 : circuit de publication des articles, calqué sur les pages (0017/0018).
-- published_body (NULL = pas de version conservée) et published_* gardent la version en ligne pendant la reprise d'un article publié ;
-- content_version est la version de la copie de travail ; author_label : auteur affiché libre (vide = nom du rédacteur).
ALTER TABLE cms_articles
    ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN author_label VARCHAR(80) NULL AFTER author_id,
    ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER author_label,
    ADD COLUMN published_by BIGINT UNSIGNED NULL AFTER submitted_by,
    ADD COLUMN published_body MEDIUMTEXT NULL AFTER archived_at,
    ADD COLUMN published_title VARCHAR(200) NULL AFTER published_body,
    ADD COLUMN published_excerpt TEXT NULL AFTER published_title,
    ADD COLUMN published_seo_title VARCHAR(70) NULL AFTER published_excerpt,
    ADD COLUMN published_seo_description VARCHAR(200) NULL AFTER published_seo_title,
    ADD COLUMN published_featured_file_id BIGINT UNSIGNED NULL AFTER published_seo_description,
    ADD COLUMN published_og_file_id BIGINT UNSIGNED NULL AFTER published_featured_file_id,
    ADD COLUMN published_canonical_url VARCHAR(255) NULL AFTER published_og_file_id,
    ADD COLUMN published_version_at DATETIME NULL AFTER published_canonical_url,
    ADD CONSTRAINT fk_cms_articles_pub_featured FOREIGN KEY (published_featured_file_id) REFERENCES files (id),
    ADD CONSTRAINT fk_cms_articles_pub_og FOREIGN KEY (published_og_file_id) REFERENCES files (id);
