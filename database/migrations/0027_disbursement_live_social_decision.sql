-- Filet de base de données : au plus un décaissement « vivant » (ni rejeté ni annulé) par décision sociale (DisbursementService::create le contrôle déjà sous verrou de la décision).
-- Colonne générée STORED + UNIQUE, comme 0015 : les NULL (décaissement ordinaire, rejeté ou annulé) peuvent se répéter. Syntaxe valable sur MariaDB >= 10.2.7 et MySQL >= 8.0.16.
-- Si la base contient déjà deux décaissements vivants pour la même décision, la création de l'index échoue (« Duplicate entry … for key 'uq_dr_live_social_decision' ») :
-- rejeter ou annuler alors le décaissement en trop avant de relancer bin/migrate.php.
ALTER TABLE disbursement_requests
    ADD COLUMN live_social_decision_id BIGINT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('rejete', 'annule'), NULL, social_decision_id)) STORED,
    ADD UNIQUE KEY uq_dr_live_social_decision (live_social_decision_id);
