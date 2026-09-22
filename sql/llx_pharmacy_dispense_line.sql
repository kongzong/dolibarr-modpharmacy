-- modPharmacy dispense sheet lines. qty is the dispensed quantity (V0.1 =
-- the prescription line quantity, whole-sheet dispensing). is_stock marks
-- lines that actually hit the stock (linked product); batch_note snapshots
-- the FEFO allocation ("batch/eatby" pairs) written at confirm time.

CREATE TABLE llx_pharmacy_dispense_line(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_dispense			integer NOT NULL,
	fk_prescription_line integer DEFAULT NULL,
	position			smallint DEFAULT 0 NOT NULL,
	fk_product			integer DEFAULT NULL,
	product_ref			varchar(128) DEFAULT NULL,
	label				varchar(255) NOT NULL,
	qty					decimal(10,3) DEFAULT NULL,
	qty_unit			varchar(16) DEFAULT NULL,
	is_stock			smallint DEFAULT 0 NOT NULL,
	batch_note			varchar(255) DEFAULT NULL
) ENGINE=innodb;
