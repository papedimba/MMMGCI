-- L3 tâche 7 (correctif) : version de la copie de travail des contenus à circuit simple ; la publication exige la version que le publicateur a vue.
ALTER TABLE cms_documents ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE cms_galleries ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE cms_banners ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE cms_testimonials ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
