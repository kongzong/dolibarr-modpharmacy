-- modPharmacy: stock count sheets. One sheet per (warehouse, counting day).
-- status 0 = counting (draft, editable), 1 = posted (posted adjustments are
-- written to llx_stock_mouvement, the sheet becomes read-only).
-- The line table stores the book quantity as a SNAPSHOT taken when the sheet
-- was generated: that is what makes the difference meaningful even if stock
-- moves later. qty_counted NULL = the line has not been counted yet.

CREATE TABLE llx_pharmacy_stock_count(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	ref					varchar(30) NOT NULL,
	fk_entrepot			integer NOT NULL,
	date_count			date NOT NULL,		-- business day of the count
	status				integer DEFAULT 0 NOT NULL,	-- 0 draft, 1 posted
	note				text DEFAULT NULL,
	qty_book			double DEFAULT 0 NOT NULL,	-- sum of the book snapshot
	qty_counted			double DEFAULT 0 NOT NULL,	-- sum of what was counted
	qty_diff			double DEFAULT 0 NOT NULL,	-- counted - book
	fk_user_creat		integer DEFAULT NULL,
	fk_user_valid		integer DEFAULT NULL,	-- who posted it (GSP: separate from the counter)
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;

CREATE TABLE llx_pharmacy_stock_count_line(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_count			integer NOT NULL,
	fk_product			integer NOT NULL,
	product_ref			varchar(128) DEFAULT NULL,	-- snapshot for the printed sheet
	product_label		varchar(255) DEFAULT NULL,
	batch				varchar(128) NOT NULL,
	eatby				date DEFAULT NULL,
	sellby				date DEFAULT NULL,
	qty_book			double DEFAULT 0 NOT NULL,
	qty_counted			double DEFAULT NULL,	-- NULL = not counted yet
	qty_diff			double DEFAULT 0 NOT NULL,
	note				text DEFAULT NULL
) ENGINE=innodb;
