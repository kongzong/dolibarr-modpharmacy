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

use Luracast\Restler\RestException;

/**
 * \file    htdocs/custom/pharmacy/class/api_pharmacy.class.php
 * \ingroup pharmacy
 * \brief   REST API for pharmacy dispensing (spec §3.7).
 *          URL resource is "dispenses" (plural, per spec); API class is
 *          "Pharmacy" while the business class is "Dispense" (no case clash,
 *          since ucwords('pharmacy') = 'Pharmacy' != 'Dispense').
 *
 *          Every endpoint calls the Dispense / PrescriptionSheet / PharmacyExpiryAlert
 *          classes, so the same state machine, FEFO allocation, audit trail and
 *          allergy/issue gates as the UI apply. No endpoint exposes the patient's
 *          identity document (only card_no / name via patient_get_summary).
 */

dol_include_once('/pharmacy/class/dispense.class.php');
dol_include_once('/pharmacy/class/pharmacyexpiryalert.class.php');
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/prescription/class/prescriptionsheet.class.php');
dol_include_once('/prescription/lib/prescription.lib.php');
dol_include_once('/patient/lib/patient.lib.php');

/**
 * API class for Pharmacy module
 *
 * @url     GET /dispenses
 * @access  protected
 * @class   DolibarrApiAccess {@requires user,external}
 */
class Pharmacy extends DolibarrApi
{
	/**
	 * @var DoliDB $db Database object
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @url GET /
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * List dispensing sheets.
	 *
	 * @url	GET dispenses
	 *
	 * @param	string	$q				Ref / prescription ref / card no. / patient name
	 * @param	int		$patient		Patient profile rowid
	 * @param	int		$prescription	Prescription rowid
	 * @param	int		$status			-1 all non-returned (default), 0 pending, 1 dispensed, 9 returned
	 * @param	string	$from			Sheet date from (YYYY-MM-DD)
	 * @param	string	$to				Sheet date to (YYYY-MM-DD)
	 * @param	int		$limit			Page size (max 100)
	 * @param	int		$page			Page (0-based)
	 * @return	array					Paginated list: total + rows
	 * @throws RestException 403 Not allowed
	 */
	public function index($q = '', $patient = 0, $prescription = 0, $status = -1, $from = '', $to = '', $limit = 25, $page = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('pharmacy', 'read')) {
			throw new RestException(403);
		}
		$limit = max(1, min(100, (int) $limit));
		$page = max(0, (int) $page);
		$filters = array(
			'q' => (string) $q,
			'patient' => (int) $patient,
			'fk_prescription' => (int) $prescription,
			'status' => (int) $status,
			'from' => $from !== '' ? $this->dateToTs($from, false) : 0,
			'to' => $to !== '' ? $this->dateToTs($to, true) : 0,
		);
		$dao = new Dispense($this->db);
		$result = $dao->search($filters, $limit, $limit * $page);
		if ($result === null) {
			throw new RestException(500, 'Search failed: '.$dao->error);
		}
		$rows = array();
		foreach ($result['rows'] as $r) {
			$rows[] = $this->listRow($r);
		}
		return array('total' => (int) $result['total'], 'rows' => $rows);
	}

	/**
	 * Get one dispensing sheet with its lines (audited as PHARMACY_READ).
	 *
	 * @url	GET dispenses/{id}
	 *
	 * @param	int		$id		Dispense rowid
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 */
	public function get($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('pharmacy', 'read')) {
			throw new RestException(403);
		}
		$d = $this->load($id);
		patient_audit($this->db, $d->fk_patient, 'PHARMACY_READ', DolibarrApiAccess::$user, array('ref' => $d->ref, 'dispense' => $d->id, 'via' => 'api'));
		return $this->fields($d);
	}

	/**
	 * Create a pending sheet from a signed prescription (spec §3.3 step 1).
	 * No stock movement yet.
	 *
	 * Body: { "prescription": 10, "warehouse": 1, "note": "..." }
	 *
	 * @url	POST dispenses
	 *
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 400 Bad parameters
	 * @throws RestException 404 Prescription not found
	 * @throws RestException 409 Prescription not issued / already pending
	 */
	public function post($request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('pharmacy', 'write')) {
			throw new RestException(403);
		}
		$data = is_array($request_data) ? $request_data : array();
		$prescId = isset($data['prescription']) ? (int) $data['prescription'] : 0;
		$warehouse = isset($data['warehouse']) ? (int) $data['warehouse'] : 0;
		if ($prescId <= 0) {
			throw new RestException(400, 'prescription required');
		}
		$presc = new PrescriptionSheet($this->db);
		if ($presc->fetch($prescId) <= 0) {
			throw new RestException(404, 'PrescriptionSheet not found');
		}
		if ((int) $presc->status !== PRESCRIPTION_STATUS_ISSUED) {
			throw new RestException(409, 'Prescription not issued');
		}
		$note = isset($data['note']) ? (string) $data['note'] : '';
		$d = new Dispense($this->db);
		$result = $d->createFromPrescription(DolibarrApiAccess::$user, $presc, $warehouse, $note);
		if ($result > 0) {
			$d->fetch($d->id);
			return $this->fields($d);
		}
		if ($d->error === 'PharmacyErrNotIssued') {
			throw new RestException(409, 'Prescription not issued');
		}
		if ($d->error === 'PharmacyErrWarehouseRequired') {
			throw new RestException(400, 'Warehouse required');
		}
		if ($d->error === 'PharmacyErrAlreadyPending') {
			throw new RestException(409, 'A sheet already exists for this prescription');
		}
		throw new RestException(400, 'Create failed: '.$d->error);
	}

	/**
	 * Confirm dispensing: FEFO stock deduction + PDF generation (spec §3.3 step 2).
	 * Idempotent: an already-dispensed sheet returns its current state.
	 *
	 * @url	POST dispenses/{id}/confirm
	 *
	 * @param	int		$id		Dispense rowid
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 409 Stock shortage (see message)
	 * @throws RestException 400 Other failure
	 */
	public function confirm($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('pharmacy', 'dispense')) {
			throw new RestException(403);
		}
		$d = $this->load($id); // fetch() loads $this->lines
		if ((int) $d->status === PHARMACY_STATUS_DISPENSED) {
			return $this->fields($d); // idempotent
		}
		$result = $d->confirm(DolibarrApiAccess::$user);
		if ($result > 0) {
			$this->reload($d);
			return $this->fields($d);
		}
		if ($result === -1) {
			throw new RestException(409, 'Stock shortage: '.$d->error);
		}
		throw new RestException(400, 'Confirm failed: '.$d->error);
	}

	/**
	 * Return a dispensed sheet: reverse stock movement + prescription bridge
	 * back to issued (spec §3.3 step 3). Body: { "reason": "..." } (required).
	 *
	 * @url	POST dispenses/{id}/return
	 *
	 * @param	int		$id				Dispense rowid
	 * @param	array	$request_data	Body
	 * @return	array
	 * @throws RestException 403 Not allowed
	 * @throws RestException 404 Not found
	 * @throws RestException 400 Reason missing / return failed
	 * @throws RestException 409 Not dispensed / already returned
	 */
	public function return($id, $request_data = null)
	{
		if (!DolibarrApiAccess::$user->hasRight('pharmacy', 'return')) {
			throw new RestException(403);
		}
		$d = $this->load($id);
		$reason = is_array($request_data) && isset($request_data['reason']) ? (string) $request_data['reason'] : '';
		if ($reason === '') {
			throw new RestException(400, 'Return reason required');
		}
		$result = $d->returnSheet(DolibarrApiAccess::$user, $reason);
		if ($result > 0) {
			$this->reload($d);
			return $this->fields($d);
		}
		if ($result === -1) {
			throw new RestException(400, 'Return failed: '.$d->error);
		}
		throw new RestException(409, 'Cannot return: '.$d->error);
	}

	/**
	 * Expiry alert list (spec §3.6). Read-only.
	 *
	 * @url	GET expiry
	 *
	 * @param	int		$days		Window in days (default 90)
	 * @param	int		$warehouse	Warehouse rowid (0 = all)
	 * @return	array
	 * @throws RestException 403 Not allowed
	 */
	public function expiry($days = 90, $warehouse = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('pharmacy', 'read')) {
			throw new RestException(403);
		}
		$alert = new PharmacyExpiryAlert($this->db);
		return $alert->collect((int) $days, (int) $warehouse);
	}

	// ------------------------------------------------------------ helpers

	/**
	 * @param	int		$id		Dispense rowid
	 * @return	Dispense
	 * @throws	RestException 404
	 */
	private function load($id)
	{
		$d = new Dispense($this->db);
		if ((int) $id <= 0 || $d->fetch((int) $id) <= 0) {
			throw new RestException(404, 'Dispense not found');
		}
		return $d;
	}

	/**
	 * Re-fetch after a state change so the response reflects the stored row.
	 *
	 * @param	Dispense	$d	Object
	 * @return	void
	 */
	private function reload(Dispense $d)
	{
		$d->fetch($d->id);
	}

	/**
	 * One list row. No patient identity document.
	 *
	 * @param	object	$r	Row from Dispense::search
	 * @return	array
	 */
	private function listRow($r)
	{
		return array(
			'id' => (int) $r->rowid,
			'ref' => $r->ref,
			'fk_prescription' => (int) $r->fk_prescription,
			'fk_patient' => (int) $r->fk_patient,
			'card_no' => $r->card_no ?: null,
			'patient_name' => $r->patient_name ?: null,
			'fk_warehouse' => (int) $r->fk_warehouse,
			'warehouse_label' => $r->warehouse_label ?: null,
			'status' => (int) $r->status,
			'date_creation' => dol_print_date($this->db->jdate($r->date_creation), 'dayhourrfc'),
		);
	}

	/**
	 * Full fields. No patient identity document (card_no / name only,
	 * through patient_get_summary).
	 *
	 * @param	Dispense	$d	Loaded object
	 * @return	array
	 */
	private function fields(Dispense $d)
	{
		$summary = function_exists('patient_get_summary') ? patient_get_summary($this->db, $d->fk_patient) : null;
		return array(
			'id' => (int) $d->id,
			'ref' => $d->ref,
			'fk_prescription' => (int) $d->fk_prescription,
			'fk_patient' => (int) $d->fk_patient,
			'card_no' => $summary ? $summary['card_no'] : null,
			'patient_name' => $summary ? $summary['name'] : null,
			'fk_warehouse' => (int) $d->fk_warehouse,
			'status' => (int) $d->status,
			'date_creation' => $d->date_creation ? dol_print_date($d->date_creation, 'dayhourrfc') : null,
			'date_dispense' => $d->date_dispense ? dol_print_date($d->date_dispense, 'dayhourrfc') : null,
			'return_reason' => $d->return_reason ?: null,
			'note' => $d->note ?: null,
			'lines' => $d->lines,
		);
	}

	/**
	 * @param	string	$s		YYYY-MM-DD[ HH:MM[:SS]]
	 * @param	bool	$endOfDay	Use 23:59:59 when no time given
	 * @return	int				Timestamp or 0
	 */
	private function dateToTs($s, $endOfDay)
	{
		if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[ T]([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?)?$/', trim($s), $m)) {
			return 0;
		}
		$h = isset($m[4]) ? (int) $m[4] : ($endOfDay ? 23 : 0);
		$i = isset($m[5]) ? (int) $m[5] : ($endOfDay ? 59 : 0);
		$sec = isset($m[6]) ? (int) $m[6] : ($endOfDay ? 59 : 0);
		return (int) dol_mktime($h, $i, $sec, (int) $m[2], (int) $m[3], (int) $m[1]);
	}
}
