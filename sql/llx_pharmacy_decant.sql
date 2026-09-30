-- modPharmacy: herbal-drug decanting log (GSP art. 164 (9): "不同批号的饮片
-- 装斗前应当清斗并记录" — bins must be cleared and the clearing recorded
-- before filling a different batch). Append-only: the code offers no UPDATE
-- and no DELETE path, records are kept as GSP trace. op = CLEAR (清斗) or
-- FILL (装斗); bin = 斗位 label (free text, e.g. "甘草-01").

CREATE TABLE llx_pharmacy_decant(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	fk_product			integer NOT NULL,
	batch				varchar(128) NOT NULL,
	binloc				varchar(60) NOT NULL,
	op					varchar(10) NOT NULL,
	date_op				date NOT NULL,
	checker				varchar(60) DEFAULT NULL,
	note				text DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
