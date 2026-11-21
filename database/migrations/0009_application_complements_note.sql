-- Dernière note « compléments demandés » d'une demande d'adhésion (l'historique complet reste dans le journal d'audit).
ALTER TABLE membership_applications ADD COLUMN complements_note TEXT NULL AFTER rejection_reason;
