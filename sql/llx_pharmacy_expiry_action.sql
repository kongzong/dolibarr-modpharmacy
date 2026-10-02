-- modPharmacy: expiry disposition log. Append-only: the code offers no
-- UPDATE and no DELETE path. op = SCRAP (报废，库存清零) or BLOCK (停售，
-- 禁止出库) or UNBLOCK (恢复). The effective state of a batch is the op of
-- its highest rowid record (append-only state machine).

CREATE TABLE llx_pharmacy_expiry_action(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	fk_product			integer NOT NULL,
	batch				varchar(128) NOT NULL,
	fk_entrepot			integer DEFAULT 0 NOT NULL,
	op					varchar(10) NOT NULL,
	qty					double DEFAULT 0 NOT NULL,	-- scrapped quantity (SCRAP only)
	note				text DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
