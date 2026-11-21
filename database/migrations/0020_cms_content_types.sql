-- L3 tâche 7 : évènements (circuit complet), documents officiels, galeries, bannières, témoignages.
-- Évènements : version de la copie de travail, soumetteur, publicateur (« déjà en ligne »), date d'archivage ; pas de version conservée (voir EventService).
ALTER TABLE cms_events
    ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN submitted_by BIGINT UNSIGNED NULL AFTER published_at,
    ADD COLUMN published_by BIGINT UNSIGNED NULL AFTER submitted_by,
    ADD COLUMN archived_at DATETIME NULL AFTER published_by;
-- Documents : date du document.
ALTER TABLE cms_documents ADD COLUMN document_date DATE NULL AFTER category;
-- Éléments de galerie : légende et crédit (l'alt vient du média).
ALTER TABLE cms_gallery_items
    ADD COLUMN caption VARCHAR(200) NULL AFTER media_id,
    ADD COLUMN credit VARCHAR(120) NULL AFTER caption;
-- Témoignages : un brouillon peut exister sans fichier de consentement ; seule la publication l'exige.
ALTER TABLE cms_testimonials MODIFY consent_file_id BIGINT UNSIGNED NULL;
-- Bannières : date de première publication (une bannière jamais publiée peut être supprimée).
ALTER TABLE cms_banners ADD COLUMN published_at DATETIME NULL AFTER ends_at;
