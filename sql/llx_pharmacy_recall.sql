-- modPharmacy: drug recall (召回). One sheet per recalled batch.
-- The patient list is generated from llx_stock_mouvement at creation time:
-- every "Dispense {ref}" row for that product/batch is a patient who received
-- the goods, which is exactly the question a recall has to answer.
-- status 0 = registered, 1 = patients notified, 2 = closed.
-- Creating a recall also writes a BLOCK row into llx_pharmacy_expiry_action,
-- so the batch stops being dispensable through the existing sales-hold path
-- (and shows up as "blocked" on the expiry board) instead of a second,
-- parallel mechanism.

CREATE TABLE llx_pharmacy_recall(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	ref					varchar(30) NOT NULL,
	fk_product			integer NOT NULL,
	product_ref			varchar(128) DEFAULT NULL,	-- snapshot
	product_label		varchar(255) DEFAULT NULL,
	batch				varchar(128) NOT NULL,
	fk_entrepot			integer DEFAULT 0 NOT NULL,
	level				integer DEFAULT 1 NOT NULL,	-- 1/2/3, mirrors the GSP recall levels
	reason				text DEFAULT NULL,
	status				integer DEFAULT 0 NOT NULL,
	date_creation		datetime NOT NULL,
	fk_user_creat		integer DEFAULT NULL,
	fk_user_close		integer DEFAULT NULL,
	date_close			datetime DEFAULT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

CREATE TABLE llx_pharmacy_recall_line(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_recall			integer NOT NULL,
	fk_dispense			integer DEFAULT 0 NOT NULL,
	dispense_ref		varchar(64) DEFAULT NULL,	-- snapshot of the sheet ref
	date_dispense		datetime DEFAULT NULL,
	fk_patient			integer DEFAULT 0 NOT NULL,
	card_no				varchar(64) DEFAULT NULL,	-- snapshot for the paper list
	patient_name		varchar(255) DEFAULT NULL,
	qty					double DEFAULT 0 NOT NULL,	-- quantity of this batch they received
	notified			integer DEFAULT 0 NOT NULL,	-- 0 no, 1 yes
	date_notified		datetime DEFAULT NULL,
	note				text DEFAULT NULL
) ENGINE=innodb;
