ALTER TABLE llx_pharmacy_dispense ADD UNIQUE INDEX uk_pharmacy_dispense_ref (ref);
ALTER TABLE llx_pharmacy_dispense ADD INDEX idx_pharmacy_dispense_presc (fk_prescription);
ALTER TABLE llx_pharmacy_dispense ADD INDEX idx_pharmacy_dispense_patient (fk_patient);
ALTER TABLE llx_pharmacy_dispense ADD INDEX idx_pharmacy_dispense_warehouse (fk_warehouse);
ALTER TABLE llx_pharmacy_dispense ADD INDEX idx_pharmacy_dispense_status (status);
ALTER TABLE llx_pharmacy_dispense ADD INDEX idx_pharmacy_dispense_entity (entity);

ALTER TABLE llx_pharmacy_dispense_line ADD INDEX idx_pharmacy_dispense_line_disp (fk_dispense);
ALTER TABLE llx_pharmacy_dispense_line ADD INDEX idx_pharmacy_dispense_line_product (fk_product);
