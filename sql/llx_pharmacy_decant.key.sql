-- Copyright (C) 2026  modPharmacy contributors
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.

ALTER TABLE llx_pharmacy_decant ADD INDEX idx_pharmacy_decant_date (entity, date_op);
ALTER TABLE llx_pharmacy_decant ADD INDEX idx_pharmacy_decant_product (fk_product);
ALTER TABLE llx_pharmacy_decant ADD INDEX idx_pharmacy_decant_op (op);
