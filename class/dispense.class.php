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
 * Class Dispense
 */
class Dispense
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
	/** @var int */
	public $fk_user_creat;
	/** @var int Unix timestamp */
	public $date_creation;
	/** @var string Warehouse label filled on fetch */
	public $warehouse_label;
	/** @var string Prescription ref filled on fetch */
	public $presc_ref;

	/**
	 * Dispensed lines: {fk_prescription_line, position, fk_product,
	 * product_ref, label, qty, qty_unit, is_stock, batch_note}
	 * @var array<int,array>
	 */
	public $lines = array();

	/** @var string Last error (translated key or db error) */
	public $error = '';

	/** @var DoliDB */
	private $db;

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
		$sql .= " d.date_dispense, d.fk_user_dispense, d.return_reason, d.note, d.model_pdf, d.fk_user_creat, d.date_creation,";
		$sql .= " w.lieu as warehouse_lieu, w.label as warehouse_label, p.ref as presc_ref";
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
		$sql .= " CONCAT_WS(' - ', w.lieu, w.label) as warehouse_label";
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
}
