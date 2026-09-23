<?php
/* Copyright (C) 2026  modPharmacy contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/pharmacy/class/dispense.class.php
 * \ingroup pharmacy
 * \brief   One dispense sheet for one signed prescription (spec-pharmacy
 *          §3.3). Never deleted; returned sheets are the trace of the
 *          reverse stock movements. Phase 1 skeleton: fetch / search /
 *          lines. The confirm (FEFO, idempotent) and return transactions
 *          land in phase 2.
 */

dol_include_once('/pharmacy/class/pharmacynumbering.class.php');
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * Sentinel raised inside confirm() when FEFO allocation finds no stock.
 * The catch block maps it back to the user-facing 'PharmacyErrStockShort'
 * translated key instead of leaking the internal message.
 */
class PharmacyStockShortageException extends RuntimeException {}

/**
 * Class Dispense
 */
class Dispense extends CommonObject
{
	/** @var string Element type (for hooks / REST) */
	public $element = 'dispense';

	/** @var string */
	public $table_element = 'pharmacy_dispense';

	/** @var int */
	public $id;

	public $entity;
	public $ref;
	/** @var int Prescription rowid */
	public $fk_prescription;
	/** @var int Patient profile rowid */
	public $fk_patient;
	/** @var int Warehouse rowid */
	public $fk_warehouse;
	/** @var int 0 pending, 1 dispensed, 9 returned */
	public $status = PHARMACY_STATUS_PENDING;
	/** @var int|null Unix timestamp */
	public $date_dispense;
	/** @var int|null */
	public $fk_user_dispense;
	/** @var string */
	public $return_reason;
	/** @var string */
	public $note;
	public $model_pdf;
	/** @var string Relative path of the last generated PDF, filled by generateDocument */
	public $last_main_doc;
	/** @var int */
	public $fk_user_creat;
	/** @var int Unix timestamp */
	public $date_creation;
	/** @var string Warehouse label filled on fetch */
	public $warehouse_label;
	/** @var string Prescription ref filled on fetch */
	public $presc_ref;

	/** @var string Patient name filled on fetch */
	public $patient_name;
	/** @var string Patient card no filled on fetch */
	public $card_no;
	/** @var string Dispatcher full name filled by preparePdfContext */
	public $dispenser_name = '';

	/**
	 * Dispensed lines: {fk_prescription_line, position, fk_product,
	 * product_ref, label, qty, qty_unit, is_stock, batch_note}
	 * @var array<int,array>
	 */
	public $lines = array();

	/** @var string Last error (translated key or db error) */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	int	$id	Rowid
	 * @return	int	1 ok, 0 not found, -1 error
	 */
	public function fetch($id)
	{
		$sql = "SELECT d.rowid, d.entity, d.ref, d.fk_prescription, d.fk_patient, d.fk_warehouse, d.status,";
		$sql .= " d.date_dispense, d.fk_user_dispense, d.return_reason, d.note, d.model_pdf, d.last_main_doc, d.fk_user_creat, d.date_creation,";
		$sql .= " w.lieu as warehouse_lieu, w.ref as warehouse_label, p.ref as presc_ref";
		$sql .= " FROM ".$this->db->prefix()."pharmacy_dispense as d";
		$sql .= " LEFT JOIN ".$this->db->prefix()."entrepot as w ON w.rowid = d.fk_warehouse";
		$sql .= " LEFT JOIN ".$this->db->prefix()."prescription as p ON p.rowid = d.fk_prescription";
		$sql .= " WHERE d.rowid = ".((int) $id);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->entity = (int) $obj->entity;
		$this->ref = $obj->ref;
		$this->fk_prescription = (int) $obj->fk_prescription;
		$this->fk_patient = (int) $obj->fk_patient;
		$this->fk_warehouse = (int) $obj->fk_warehouse;
		$this->status = (int) $obj->status;
		$this->date_dispense = $obj->date_dispense ? $this->db->jdate($obj->date_dispense) : null;
		$this->fk_user_dispense = $obj->fk_user_dispense !== null ? (int) $obj->fk_user_dispense : null;
		$this->return_reason = (string) $obj->return_reason;
		$this->note = (string) $obj->note;
		$this->model_pdf = (string) $obj->model_pdf;
		$this->last_main_doc = isset($obj->last_main_doc) ? (string) $obj->last_main_doc : '';
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->date_creation = $this->db->jdate($obj->date_creation);
		$this->warehouse_label = trim((string) $obj->warehouse_lieu.(empty($obj->warehouse_label) ? '' : ' - '.$obj->warehouse_label));
		$this->presc_ref = (string) $obj->presc_ref;
		return $this->fetchLines();
	}

	/**
	 * @return	int	1 ok, -1 error
	 */
	private function fetchLines()
	{
		$this->lines = array();
		$sql = "SELECT rowid, fk_prescription_line, position, fk_product, product_ref, label, qty, qty_unit, is_stock, batch_note";
		$sql .= " FROM ".$this->db->prefix()."pharmacy_dispense_line";
		$sql .= " WHERE fk_dispense = ".((int) $this->id);
		$sql .= $this->db->order('position', 'ASC');
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$this->lines[] = array(
				'id' => (int) $o->rowid,
				'fk_prescription_line' => $o->fk_prescription_line !== null ? (int) $o->fk_prescription_line : null,
				'position' => (int) $o->position,
				'fk_product' => $o->fk_product !== null ? (int) $o->fk_product : null,
				'product_ref' => $o->product_ref,
				'label' => $o->label,
				'qty' => (float) $o->qty,
				'qty_unit' => $o->qty_unit,
				'is_stock' => (int) $o->is_stock,
				'batch_note' => $o->batch_note,
			);
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Paged list. Returned sheets hidden unless status is set to 9 (or a
	 * specific status is requested).
	 *
	 * @param	array	$f		Filters: q (ref/prescription ref/card no/name), status (-1 = non-returned), from, to
	 * @param	int		$limit	Page size
	 * @param	int		$offset	Offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f, $limit = 25, $offset = 0)
	{
		global $conf;

		$from = " FROM ".$this->db->prefix()."pharmacy_dispense as d";
		$from .= " INNER JOIN ".$this->db->prefix()."prescription as p ON p.rowid = d.fk_prescription";
		$from .= " INNER JOIN ".$this->db->prefix()."patient_profile as pp ON pp.rowid = d.fk_patient";
		$from .= " INNER JOIN ".$this->db->prefix()."societe as s ON s.rowid = pp.fk_soc";
		$from .= " LEFT JOIN ".$this->db->prefix()."entrepot as w ON w.rowid = d.fk_warehouse";
		$where = " WHERE d.entity = ".((int) $conf->entity);
		if (!empty($f['q'])) {
			$like = "'%".$this->db->escape(trim($f['q']))."%'";
			$where .= " AND (d.ref LIKE ".$like." OR p.ref LIKE ".$like." OR pp.card_no LIKE ".$like." OR s.nom LIKE ".$like.")";
		}
		if (isset($f['status']) && (int) $f['status'] >= 0) {
			$where .= " AND d.status = ".((int) $f['status']);
		} else {
			$where .= " AND d.status <> ".PHARMACY_STATUS_RETURNED;
		}
		if (!empty($f['from'])) {
			$where .= " AND d.date_creation >= '".$this->db->idate((int) $f['from'])."'";
		}
		if (!empty($f['to'])) {
			$where .= " AND d.date_creation <= '".$this->db->idate((int) $f['to'])."'";
		}

		$sql = "SELECT COUNT(*) as n".$from.$where;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$total = (int) $this->db->fetch_object($resql)->n;
		$this->db->free($resql);

		$sql = "SELECT d.rowid, d.ref, d.fk_prescription, d.fk_patient, d.fk_warehouse, d.status, d.date_creation,";
		$sql .= " p.ref as presc_ref, pp.card_no, s.nom as patient_name,";
		$sql .= " CONCAT_WS(' - ', w.lieu, w.ref) as warehouse_label";
		$sql .= $from.$where;
		$sql .= $this->db->order('d.rowid', 'DESC');
		$sql .= $this->db->plimit((int) $limit, (int) $offset);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($o = $this->db->fetch_object($resql)) {
			$o->status = (int) $o->status;
			$rows[] = $o;
		}
		$this->db->free($resql);
		return array('total' => $total, 'rows' => $rows);
	}

	// ------------------------------------------------------------ phase 2: transactions

	/**
	 * Create a pending sheet from a signed prescription (spec §3.3 step 1).
	 * Snapshot lines; no stock movement yet. The gate is a conditional check
	 * for an existing non-returned sheet for the same prescription, inside
	 * the same transaction as the insert, so double-submits cannot create
	 * two sheets.
	 *
	 * @param	User				$user	Acting user (pharmacy write)
	 * @param	PrescriptionSheet	$presc	Prescription loaded and issued
	 * @param	int					$warehouseId	Warehouse rowid (> 0)
	 * @param	string				$note	Note
	 * @return	int							1 ok, -2 refused (not issued / already pending), -1 error
	 */
	public function createFromPrescription(User $user, PrescriptionSheet $presc, $warehouseId, $note = '')
	{
		$this->error = '';
		$warehouseId = (int) $warehouseId;
		if ((int) $presc->status !== PRESCRIPTION_STATUS_ISSUED || $presc->id <= 0) {
			$this->error = 'PharmacyErrNotIssued';
			return -2;
		}
		if ($warehouseId <= 0) {
			$this->error = 'PharmacyErrWarehouseRequired';
			return -2;
		}
		if (empty($presc->lines)) {
			$this->error = 'PrescriptionErrNoLines';
			return -2;
		}

		$this->db->begin();
		try {
			// Lock the prescription row: serializes concurrent creators for
			// the same prescription and re-checks it is still issued.
			$resql = $this->db->query("SELECT status FROM ".$this->db->prefix()."prescription WHERE rowid = ".((int) $presc->id)." FOR UPDATE");
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			$row = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if (!$row || (int) $row->status !== PRESCRIPTION_STATUS_ISSUED) {
				$this->db->rollback();
				$this->error = 'PharmacyErrNotIssued';
				return -2;
			}

			// Gate: only one non-returned sheet per prescription.
			$resql = $this->db->query("SELECT COUNT(*) as n FROM ".$this->db->prefix()."pharmacy_dispense"
				." WHERE fk_prescription = ".((int) $presc->id)." AND status <> ".PHARMACY_STATUS_RETURNED);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			$pending = (int) $this->db->fetch_object($resql)->n;
			$this->db->free($resql);
			if ($pending > 0) {
				$this->db->rollback();
				$this->error = 'PharmacyErrAlreadyPending';
				return -2;
			}

			$ref = (new PharmacyNumbering($this->db))->nextReference(PharmacyNumbering::prefixFor());
			$now = dol_now();
			$sql = "INSERT INTO ".$this->db->prefix()."pharmacy_dispense (entity, ref, fk_prescription, fk_patient, fk_warehouse, status, note, fk_user_creat, date_creation)";
			$sql .= " VALUES (".((int) $presc->entity).", '".$this->db->escape($ref)."', ".((int) $presc->id).", ".((int) $presc->fk_patient).", ".$warehouseId.", ".PHARMACY_STATUS_PENDING.", '".$this->db->escape($note)."', ".((int) $user->id).", '".$this->db->idate($now)."')";
			if (!$this->db->query($sql)) {
				throw new RuntimeException($this->db->lasterror());
			}
			$this->id = (int) $this->db->db->insert_id;

			$position = 0;
			foreach ($presc->lines as $l) {
				$isStock = !empty($l['fk_product']) ? 1 : 0;
				$sql = "INSERT INTO ".$this->db->prefix()."pharmacy_dispense_line (fk_dispense, fk_prescription_line, position, fk_product, product_ref, label, qty, qty_unit, is_stock)";
				$sql .= " VALUES (".$this->id.", 0, ".$position.", ".(!empty($l['fk_product']) ? (int) $l['fk_product'] : 'NULL');
				$sql .= ", ".($l['product_ref'] !== null ? "'".$this->db->escape($l['product_ref'])."'" : 'NULL');
				$sql .= ", '".$this->db->escape($l['label'])."'";
				$sql .= ", ".($l['qty'] !== null ? price2num($l['qty'], 'MS') : 'NULL');
				$sql .= ", ".($l['qty_unit'] !== null ? "'".$this->db->escape($l['qty_unit'])."'" : 'NULL');
				$sql .= ", ".$isStock.")";
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}
				$position++;
			}

			patient_audit($this->db, $presc->fk_patient, 'PHARMACY_CREATE', $user, array('ref' => $ref, 'dispense' => $this->id, 'prescription' => $presc->id, 'warehouse' => $warehouseId));
			$this->db->commit();
		} catch (Throwable $e) {
			while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
				$this->db->rollback();
			}
			$this->error = $e->getMessage();
			dol_syslog('Dispense::createFromPrescription failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		$this->ref = $ref;
		$this->fk_prescription = (int) $presc->id;
		$this->fk_patient = (int) $presc->fk_patient;
		$this->fk_warehouse = $warehouseId;
		$this->status = PHARMACY_STATUS_PENDING;
		$this->note = $note;
		$this->date_creation = $now;
		$this->lines = array();
		foreach ($presc->lines as $l) {
			$this->lines[] = array(
				'fk_prescription_line' => null,
				'position' => count($this->lines),
				'fk_product' => !empty($l['fk_product']) ? (int) $l['fk_product'] : null,
				'product_ref' => $l['product_ref'],
				'label' => $l['label'],
				'qty' => $l['qty'] !== null ? (float) $l['qty'] : null,
				'qty_unit' => $l['qty_unit'],
				'is_stock' => !empty($l['fk_product']) ? 1 : 0,
				'batch_note' => null,
			);
		}
		return 1;
	}

	/**
	 * Confirm the dispensing (spec §3.3 step 2): the only place stock moves.
	 * Idempotency gate = conditional UPDATE status 0 -> 1 (affected rows must
	 * be 1). FEFO allocation per stock line, one MouvementStock::livraison()
	 * per batch, then the prescription bridge markDispensed(). Any failure
	 * rolls the whole transaction back: no partial dispensing.
	 *
	 * @param	User	$user	Acting user (pharmacy dispense permission)
	 * @return	int				1 ok, -2 refused (not pending / prescription changed), -1 error (this->error)
	 */
	public function confirm(User $user)
	{
		$this->error = '';
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'PharmacyErrNotPending';
			return -2;
		}
		if ((int) $this->status === PHARMACY_STATUS_DISPENSED) {
			return 1; // idempotent: already dispensed, no stock moves again
		}
		if ((int) $this->status !== PHARMACY_STATUS_PENDING) {
			$this->error = 'PharmacyErrNotPending';
			return -2;
		}

		$this->db->begin();
		try {
			// Lock the prescription and re-check it is still issued (no
			// void/create race between our fetch and this transaction).
			$resql = $this->db->query("SELECT status FROM ".$this->db->prefix()."prescription WHERE rowid = ".((int) $this->fk_prescription)." FOR UPDATE");
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			$row = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if (!$row || (int) $row->status !== PRESCRIPTION_STATUS_ISSUED) {
				$this->db->rollback();
				$this->error = 'PharmacyErrNotIssued';
				return -2;
			}

			// Idempotency gate: exactly one writer flips 0 -> 1.
			$sql = "UPDATE ".$this->db->prefix()."pharmacy_dispense SET status = ".PHARMACY_STATUS_DISPENSED.", date_dispense = '".$this->db->idate(dol_now())."', fk_user_dispense = ".((int) $user->id);
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".PHARMACY_STATUS_PENDING;
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			if ($this->db->affected_rows($resql) < 1) {
				// A concurrent writer dispensed between our fetch and the gate.
				$this->db->rollback();
				$this->status = PHARMACY_STATUS_DISPENSED;
				return 1;
			}

			// Stock movements + batch snapshot per stock line (FEFO).
			require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
			$movement = new MouvementStock($this->db);
			$stockReservations = array();
			foreach ($this->lines as $lineKey => $l) {
				if (!$l['is_stock'] || empty($l['fk_product'])) {
					continue;
				}
				$allocation = $this->allocateFefo((int) $l['fk_product'], (int) $this->fk_warehouse, (float) $l['qty'], $stockReservations);
				if ($allocation === null) {
					throw new PharmacyStockShortageException();
				}
				$notes = array();
				foreach ($allocation as $a) {
					$result = $movement->livraison($user, (int) $l['fk_product'], (int) $this->fk_warehouse, $a['qty'], 0, 'Dispense '.$this->ref, dol_now(), $a['eatby'], $a['sellby'], $a['batch']);
					if ($result < 0) {
						throw new RuntimeException('stock movement failed: '.$movement->error);
					}
					$notes[] = $a['batch'].($a['sellby'] ? '/'.dol_print_date($a['sellby'], 'day') : '');
					$stockReservations[$a['batch']] = (isset($stockReservations[$a['batch']]) ? $stockReservations[$a['batch']] : 0) + $a['qty'];
				}
				$sql = "UPDATE ".$this->db->prefix()."pharmacy_dispense_line SET batch_note = '".$this->db->escape(implode(', ', $notes))."'";
				$sql .= " WHERE fk_dispense = ".((int) $this->id)." AND position = ".$lineKey;
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}
				$this->lines[$lineKey]['batch_note'] = implode(', ', $notes);
			}

			// Prescription bridge: issued -> dispensed (same transaction;
			// nested begin/commit are depth-counted by DoliDB).
			$presc = new PrescriptionSheet($this->db);
			if ($presc->fetch($this->fk_prescription) <= 0) {
				throw new RuntimeException('prescription not found');
			}
			$bridge = $presc->markDispensed($user, array('dispense' => $this->ref));
			if ($bridge === -2) {
				$this->error = 'PharmacyErrNotIssued';
				throw new RuntimeException('prescription bridge refused');
			}
			if ($bridge < 0) {
				throw new RuntimeException('prescription bridge failed');
			}

			patient_audit($this->db, $this->fk_patient, 'PHARMACY_DISPENSE', $user, array('ref' => $this->ref, 'dispense' => $this->id, 'prescription' => $this->fk_prescription, 'lines' => count($this->lines)));
			$this->db->commit();
		} catch (Throwable $e) {
			while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
				$this->db->rollback();
			}
			$this->error = $e instanceof PharmacyStockShortageException ? 'PharmacyErrStockShort' : $e->getMessage();
			dol_syslog('Dispense::confirm failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		$this->status = PHARMACY_STATUS_DISPENSED;
		$this->date_dispense = dol_now();
		$this->fk_user_dispense = (int) $user->id;

		// Auto-generate the dispense sheet PDF (spec §3.3 step 2). A failure
		// here must not fail the dispensing itself: stock has already moved
		// and committed, the PDF is a view artifact the user can regenerate
		// from the pdf.php page.
		$this->generateDocument();
		return 1;
	}

	// ------------------------------------------------------------ phase 3: PDF

	/**
	 * Load patient summary + dispenser name for the PDF (spec §3.6 header).
	 *
	 * @return	void
	 */
	private function preparePdfContext()
	{
		$summary = patient_get_summary($this->db, $this->fk_patient);
		if (is_array($summary)) {
			$this->patient_name = isset($summary['name']) ? $summary['name'] : '';
			$this->card_no = isset($summary['card_no']) ? $summary['card_no'] : '';
		}
		$this->dispenser_name = '';
		if (!empty($this->fk_user_dispense)) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$u = new User($this->db);
			if ($u->fetch($this->fk_user_dispense) > 0) {
				$this->dispenser_name = trim($u->lastname.' '.$u->firstname);
				if ($this->dispenser_name === '') {
					$this->dispenser_name = $u->login;
				}
			}
		}
	}

	/**
	 * Build the dispense-sheet PDF (model 'fy') through the core generator
	 * lookup (module_parts['models'] = 1, mirroring modPrescription).
	 *
	 * @param	Translate|null	$outputlangs	Lang
	 * @return	int							1 ok, <0 error (this->error set)
	 */
	public function generateDocument($outputlangs = null)
	{
		global $conf, $langs;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		if (empty($conf->pharmacy->dir_output)) {
			$this->error = 'PHARMACY_OUTPUTDIR undefined (module not enabled?)';
			return -1;
		}

		$this->preparePdfContext();
		$this->model_pdf = 'fy';

		// The PDF view is a derived artifact; a failure here must not fail
		// the dispensing itself (stock has already moved and committed).
		$result = $this->commonGenerateDocument('core/modules/pharmacy/doc/', 'fy', $outputlangs, 0, 0, 0, null);
		if ($result <= 0) {
			dol_syslog('Dispense::generateDocument failed for '.$this->ref.': '.(is_array($this->errors) ? implode(' / ', $this->errors) : (string) $this->error), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * @return	string	Absolute path of the PDF file (may not exist yet)
	 */
	public function pdfPath()
	{
		global $conf;
		$ref = dol_sanitizeFileName($this->ref);
		return (empty($conf->pharmacy->dir_output) ? '' : $conf->pharmacy->dir_output).'/'.$ref.'/'.$ref.'.pdf';
	}

	/**
	 * FEFO allocation for one product in one warehouse: batches by sell-by
	 * date ascending (no-date lots last), quantity per batch capped by what
	 * is still needed. Rows are locked FOR UPDATE inside the caller's
	 * transaction. Returns null when stock is insufficient (fail-closed).
	 *
	 * @param	int		$fkProduct	Product rowid
	 * @param	int		$warehouseId	Warehouse rowid
	 * @param	float	$qtyNeeded	Quantity to allocate
	 * @param	array	$inMemory	Batch reservations already booked by earlier
	 * 								lines of the same sheet (productbatch rows are
	 * 								committed at the outer transaction's end, so
	 * 								they are invisible to later SELECTs).
	 * @return	array<int,array{rowid:int,batch:string,eatby:int,sellby:int,qty:float}>|null
	 * 								Allocation or null on shortage
	 */
	private function allocateFefo($fkProduct, $warehouseId, $qtyNeeded, array $inMemory = array())
	{
		// Total stock available for this product/warehouse. Inside the
		// confirm() transaction product_stock.reel already reflects the
		// decrement made by earlier lines' MouvementStock::livraison()
		// calls (core always writes it, even with productbatch disabled).
		// The inMemory reservations are only used to cap the FEFO batch
		// allocation itself (product_batch rows are not updated when
		// productbatch is disabled), so do NOT subtract them from reel.
		$sqlReel = "SELECT reel FROM ".$this->db->prefix()."product_stock"
			." WHERE fk_product = ".((int) $fkProduct)." AND fk_entrepot = ".((int) $warehouseId)." LIMIT 1";
		$resReel = $this->db->query($sqlReel);
		if (!$resReel) {
			throw new RuntimeException($this->db->lasterror());
		}
		$reelObj = $this->db->fetch_object($resReel);
		$this->db->free($resReel);
		$reel = $reelObj && $reelObj->reel !== null ? (float) $reelObj->reel : 0.0;
		if ($reel < $qtyNeeded - 0.0000001) {
			return null; // shortage: the whole sheet fails
		}

		// FEFO allocation: batches in sell-by order (no-date lots last),
		// capped by remaining needed and by each batch's current qty minus
		// what earlier lines of this same sheet already reserved (batch rows
		// are authoritative only when productbatch is enabled, so track
		// in-memory reservations regardless of that setting).
		$sql = "SELECT pb.rowid, pb.batch, pl.eatby, pl.sellby, pb.qty";
		$sql .= " FROM ".$this->db->prefix()."product_batch as pb";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_lot as pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch";
		$sql .= " WHERE ps.fk_product = ".((int) $fkProduct)." AND ps.fk_entrepot = ".((int) $warehouseId)." AND pb.qty > 0";
		$sql .= " ORDER BY (pl.sellby IS NULL) ASC, pl.sellby ASC, (pl.eatby IS NULL) ASC, pl.eatby ASC, pb.batch ASC";
		$sql .= " FOR UPDATE";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RuntimeException($this->db->lasterror());
		}
		$allocation = array();
		$remaining = (float) $qtyNeeded;
		while ($o = $this->db->fetch_object($resql)) {
			if ($remaining <= 0) {
				break;
			}
			$batchKey = $o->batch;
			$reserved = 0.0;
			if (isset($inMemory[$batchKey])) {
				$reserved = (float) $inMemory[$batchKey];
			}
			$batchAvailable = (float) $o->qty - $reserved;
			if ($batchAvailable <= 0) {
				continue;
			}
			$take = min($batchAvailable, $remaining);
			$allocation[] = array('rowid' => (int) $o->rowid, 'batch' => $o->batch, 'eatby' => $o->eatby ? (int) $o->eatby : 0, 'sellby' => $o->sellby ? (int) $o->sellby : 0, 'qty' => $take);
			$remaining -= $take;
		}
		$this->db->free($resql);
		if ($remaining > 0.0000001) {
			return null; // batch rows can't cover the need (data inconsistency)
		}
		return $allocation;
	}

	/**
	 * Return a dispensed sheet (spec §3.3 step 3): reverse stock per
	 * batch_note, mark the prescription issued again, keep the sheet as
	 * status 9 with the mandatory reason. Nothing deleted.
	 *
	 * @param	User	$user	Acting user (pharmacy return permission)
	 * @param	string	$reason	Reason (required)
	 * @return	int				1 ok, -2 refused, -1 error
	 */
	public function returnSheet(User $user, $reason)
	{
		$this->error = '';
		$reason = trim((string) $reason);
		if ($this->id <= 0 || $this->fetch($this->id) <= 0) {
			$this->error = 'PharmacyErrNotDispensed';
			return -2;
		}
		if ((int) $this->status !== PHARMACY_STATUS_DISPENSED) {
			$this->error = 'PharmacyErrNotDispensed';
			return -2;
		}
		if ($reason === '') {
			$this->error = 'PharmacyErrReturnReasonRequired';
			return -1;
		}

		$this->db->begin();
		try {
			// Gate: exactly one writer flips 1 -> 9.
			$sql = "UPDATE ".$this->db->prefix()."pharmacy_dispense SET status = ".PHARMACY_STATUS_RETURNED.", return_reason = '".$this->db->escape(dol_substr($reason, 0, 255))."'";
			$sql .= " WHERE rowid = ".((int) $this->id)." AND status = ".PHARMACY_STATUS_DISPENSED;
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			if ($this->db->affected_rows($resql) < 1) {
				$this->db->rollback();
				$this->error = 'PharmacyErrNotDispensed';
				return -2;
			}

			// Authoritative allocation: the outbound movements this sheet
			// created (label = 'Dispense {ref}'), grouped by product/batch.
			$sql = "SELECT fk_product, batch, eatby, sellby, SUM(-value) as qty";
			$sql .= " FROM ".$this->db->prefix()."stock_mouvement";
			$sql .= " WHERE label = 'Dispense ".$this->db->escape($this->ref)."' AND type_mouvement = 2 AND batch <> ''";
			$sql .= " GROUP BY fk_product, batch, eatby, sellby";
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			$movementTotals = array();
			while ($o = $this->db->fetch_object($resql)) {
				$movementTotals[] = $o;
			}
			$this->db->free($resql);

			require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
			$movement = new MouvementStock($this->db);
			foreach ($movementTotals as $t) {
				$result = $movement->reception($user, (int) $t->fk_product, (int) $this->fk_warehouse, (float) $t->qty, 0, 'Return '.$this->ref, (int) $t->eatby, (int) $t->sellby, $t->batch);
				if ($result < 0) {
					throw new RuntimeException('reverse stock movement failed: '.$movement->error);
				}
			}

			// Prescription bridge: dispensed -> issued again (nested tx).
			$presc = new PrescriptionSheet($this->db);
			if ($presc->fetch($this->fk_prescription) <= 0) {
				throw new RuntimeException('prescription not found');
			}
			$bridge = $presc->markDispenseUndone($user, $reason, array('dispense' => $this->ref));
			if ($bridge === -2) {
				$this->error = 'PharmacyErrNotDispensed';
				throw new RuntimeException('prescription bridge refused');
			}
			if ($bridge < 0) {
				throw new RuntimeException('prescription bridge failed');
			}

			patient_audit($this->db, $this->fk_patient, 'PHARMACY_RETURN', $user, array('ref' => $this->ref, 'dispense' => $this->id, 'prescription' => $this->fk_prescription, 'reason' => dol_substr($reason, 0, 100)));
			$this->db->commit();
		} catch (Throwable $e) {
			while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
				$this->db->rollback();
			}
			$this->error = $e->getMessage();
			dol_syslog('Dispense::returnSheet failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}

		$this->status = PHARMACY_STATUS_RETURNED;
		$this->return_reason = $reason;
		return 1;
	}
}
