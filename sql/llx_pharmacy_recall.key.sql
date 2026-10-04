ALTER TABLE llx_pharmacy_recall ADD UNIQUE INDEX uk_pharmacy_recall_ref (ref);
ALTER TABLE llx_pharmacy_recall ADD INDEX idx_pharmacy_recall_product (fk_product, batch);
ALTER TABLE llx_pharmacy_recall ADD INDEX idx_pharmacy_recall_status (status);
ALTER TABLE llx_pharmacy_recall ADD INDEX idx_pharmacy_recall_entity (entity);

ALTER TABLE llx_pharmacy_recall_line ADD INDEX idx_pharmacy_recall_line_recall (fk_recall);
ALTER TABLE llx_pharmacy_recall_line ADD INDEX idx_pharmacy_recall_line_patient (fk_patient);
-- one line per dispensing sheet inside a recall
ALTER TABLE llx_pharmacy_recall_line ADD UNIQUE INDEX uk_pharmacy_recall_line (fk_recall, fk_dispense);
