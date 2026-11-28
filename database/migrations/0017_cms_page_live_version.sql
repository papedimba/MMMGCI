-- L3 tâche 4 : version en ligne d'une page modifiée après publication.
-- published_blocks (0016) garde les blocs en ligne ; ces colonnes gardent le reste de la version en ligne
-- (titre, extrait, SEO, images, date) tant qu'un publicateur n'a pas republié la copie de travail.
ALTER TABLE cms_pages
    ALTER COLUMN template SET DEFAULT 'standard',
    ADD COLUMN published_title VARCHAR(200) NULL AFTER published_blocks,
    ADD COLUMN published_excerpt TEXT NULL AFTER published_title,
    ADD COLUMN published_seo_title VARCHAR(70) NULL AFTER published_excerpt,
    ADD COLUMN published_seo_description VARCHAR(200) NULL AFTER published_seo_title,
    ADD COLUMN published_featured_file_id BIGINT UNSIGNED NULL AFTER published_seo_description,
    ADD COLUMN published_og_file_id BIGINT UNSIGNED NULL AFTER published_featured_file_id,
    ADD COLUMN published_canonical_url VARCHAR(255) NULL AFTER published_og_file_id,
    ADD COLUMN published_version_at DATETIME NULL AFTER published_canonical_url,
    ADD CONSTRAINT fk_cms_pages_pub_featured FOREIGN KEY (published_featured_file_id) REFERENCES files (id),
    ADD CONSTRAINT fk_cms_pages_pub_og FOREIGN KEY (published_og_file_id) REFERENCES files (id);
