ALTER TABLE llx_pharmacy_stock_count ADD UNIQUE INDEX uk_pharmacy_stock_count_ref (ref);
ALTER TABLE llx_pharmacy_stock_count ADD INDEX idx_pharmacy_stock_count_wh (fk_entrepot);
ALTER TABLE llx_pharmacy_stock_count ADD INDEX idx_pharmacy_stock_count_status (status);
ALTER TABLE llx_pharmacy_stock_count ADD INDEX idx_pharmacy_stock_count_entity (entity);

ALTER TABLE llx_pharmacy_stock_count_line ADD INDEX idx_pharmacy_stock_count_line_count (fk_count);
ALTER TABLE llx_pharmacy_stock_count_line ADD INDEX idx_pharmacy_stock_count_line_product (fk_product);
-- one line per product/batch per sheet
ALTER TABLE llx_pharmacy_stock_count_line ADD UNIQUE INDEX uk_pharmacy_stock_count_line (fk_count, fk_product, batch);
