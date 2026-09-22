-- modPharmacy: one dispense sheet per signed prescription (spec §3.1).
-- status 0 pending, 1 dispensed, 9 returned. Never deleted: a returned sheet
-- stays as the trace of the reverse stock movements. fk_prescription is
-- unique among non-returned sheets (enforced in Dispense::create, cannot be
-- expressed as a plain unique index because returns must allow re-issue).

CREATE TABLE llx_pharmacy_dispense(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	ref					varchar(32) NOT NULL,
	fk_prescription		integer NOT NULL,
	fk_patient			integer NOT NULL,
	fk_warehouse		integer NOT NULL,
	status				smallint DEFAULT 0 NOT NULL,
	date_dispense		datetime DEFAULT NULL,
	fk_user_dispense	integer DEFAULT NULL,
	return_reason		varchar(255) DEFAULT NULL,
	note				text DEFAULT NULL,
	model_pdf			varchar(255) DEFAULT NULL,
	last_main_doc		varchar(255) DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
