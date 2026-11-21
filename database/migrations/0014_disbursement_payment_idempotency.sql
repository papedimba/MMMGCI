-- Jeton d'idempotence du paiement de décaissement (rejeu du même formulaire = même paiement). NULL toléré pour les lignes
-- de test antérieures ; DisbursementService le renseigne toujours.
ALTER TABLE disbursement_payments
    ADD COLUMN idempotency_key VARCHAR(100) NULL AFTER proof_file_id,
    ADD UNIQUE KEY uq_dp_idempotency (idempotency_key);
