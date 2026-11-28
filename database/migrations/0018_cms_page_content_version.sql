-- L3 tâche 4 (correctifs) : numéro de version de la copie de travail d'une page, incrémenté à chaque modification enregistrée.
-- Publier ou programmer transmet la version relue : une page modifiée entre-temps est refusée.
ALTER TABLE cms_pages ADD COLUMN content_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER status;
