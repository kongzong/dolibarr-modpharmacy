-- modPharmacy: daily number sequence (FY-YYYYMMDD-NNN), same pattern as
-- modPatient / modMedRecord / modPrescription. Kept on module disable.
CREATE TABLE IF NOT EXISTS llx_pharmacy_dispense_sequence (
	ref_prefix	varchar(16) NOT NULL,
	last_value	bigint NOT NULL DEFAULT 0,
	PRIMARY KEY (ref_prefix)
) ENGINE=innodb;
