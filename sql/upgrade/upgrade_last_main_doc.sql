-- modPharmacy upgrade: add the core last_main_doc column used by
-- commonGenerateDocument (the writer keeps the relative path of the last
-- generated PDF on the main object, spec §3.6 / §7.F). Bare ALTER, same
-- convention as the prescription module's last_main_doc upgrade.
ALTER TABLE llx_pharmacy_dispense ADD COLUMN last_main_doc varchar(255) DEFAULT NULL;
