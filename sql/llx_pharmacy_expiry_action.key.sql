-- modPharmacy: index for llx_pharmacy_expiry_action

ALTER TABLE llx_pharmacy_expiry_action ADD INDEX idx_pharmacy_expiry_action_batch (fk_product, batch, fk_entrepot);
