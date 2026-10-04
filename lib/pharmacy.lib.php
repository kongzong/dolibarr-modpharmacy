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
 * \file    htdocs/custom/pharmacy/lib/pharmacy.lib.php
 * \ingroup pharmacy
 * \brief   Shared helpers: dispense status labels, warehouse options,
 *          lists by prescription / patient. Audit goes through modPatient's
 *          patient_audit() with PHARMACY_* actions (spec §5.4).
 */

dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/prescription/lib/prescription.lib.php');

/** Dispense status values (spec §3.4) */
define('PHARMACY_STATUS_PENDING', 0);
define('PHARMACY_STATUS_DISPENSED', 1);
define('PHARMACY_STATUS_RETURNED', 9);

/**
 * @param	int		$status		Status value
 * @return	string				Translated label
 */
function pharmacy_status_label($status)
{
	global $langs;
	$langs->load('pharmacy@pharmacy');
	switch ((int) $status) {
		case PHARMACY_STATUS_DISPENSED:
			return $langs->trans('PharmacyStatusDispensed');
		case PHARMACY_STATUS_RETURNED:
			return $langs->trans('PharmacyStatusReturned');
		default:
			return $langs->trans('PharmacyStatusPending');
	}
}

/**
 * @param	int		$status		Status value
 * @return	string				Badge HTML
 */
function pharmacy_status_badge($status)
{
	$cls = array(PHARMACY_STATUS_PENDING => 'badge-status0', PHARMACY_STATUS_DISPENSED => 'badge-status4',
		PHARMACY_STATUS_RETURNED => 'badge-status9');
	$status = (int) $status;
	return '<span class="badge '.(isset($cls[$status]) ? $cls[$status] : 'badge-status0').'">'.pharmacy_status_label($status).'</span>';
}

/**
 * Warehouses the user can dispense from (open ones of this entity).
 *
 * @param	DoliDB	$db			Database handler
 * @return	array<int,string>	rowid => label, empty when the stock module has none
 */
function pharmacy_warehouse_options($db)
{
	$out = array();
	$sql = "SELECT w.rowid, w.lieu, w.ref FROM ".$db->prefix()."entrepot as w";
	$sql .= " WHERE w.entity IN (".getEntity('stock').") AND w.statut = 1";
	$sql .= $db->order('lieu', 'ASC');
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[(int) $o->rowid] = trim((string) $o->lieu.(empty($o->ref) ? '' : ' - '.$o->ref));
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Non-returned dispense sheets of one prescription (V0.1: at most one can
 * exist; the create() gate refuses a second while this returns a row).
 *
 * @param	DoliDB	$db				Database handler
 * @param	int		$fkPrescription	Prescription rowid
 * @return	array<int,object>			Rows (ref, status, date) newest first
 */
function pharmacy_list_by_prescription($db, $fkPrescription)
{
	$out = array();
	$sql = "SELECT d.rowid, d.ref, d.status, d.date_dispense, d.date_creation, d.fk_warehouse, w.ref as warehouse_label";
	$sql .= " FROM ".$db->prefix()."pharmacy_dispense as d";
	$sql .= " LEFT JOIN ".$db->prefix()."entrepot as w ON w.rowid = d.fk_warehouse";
	$sql .= " WHERE d.fk_prescription = ".((int) $fkPrescription)." AND d.status <> ".PHARMACY_STATUS_RETURNED;
	$sql .= $db->order('d.rowid', 'DESC');
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Dispense timeline of one patient (patient card tab, hook page).
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	Patient profile rowid
 * @param	int		$limit		Max rows
 * @return	array<int,object>	Rows (prescription ref join included)
 */
function pharmacy_list_by_patient($db, $fkPatient, $limit = 50)
{
	$out = array();
	$sql = "SELECT d.rowid, d.ref, d.fk_prescription, d.status, d.date_dispense, d.date_creation, p.ref as presc_ref, p.fk_medrecord, m.ref as medrecord_ref";
	$sql .= " FROM ".$db->prefix()."pharmacy_dispense as d";
	$sql .= " INNER JOIN ".$db->prefix()."prescription as p ON p.rowid = d.fk_prescription";
	// Owner visit via the prescription (design §5.1): pharmacy has no direct
	// fk_medrecord; the prescription is the bridge (dispense requires one).
	$sql .= " LEFT JOIN ".$db->prefix()."medrecord as m ON m.rowid = p.fk_medrecord";
	$sql .= " WHERE d.fk_patient = ".((int) $fkPatient);
	$sql .= $db->order('d.rowid', 'DESC');
	$sql .= $db->plimit((int) $limit);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Run the OTC retail chain for a walk-in customer, one step at a time:
 * internal OTC prescription -> dispense (+stock, FEFO) -> charge bill (paid).
 *
 * Each class owns its own transaction, so a failure leaves the already
 * created documents in place: $info['partial'] is set and the caller shows
 * the references so the counter can finish the remaining step by hand. No
 * stock is ever moved twice for the same cart because each call builds a
 * fresh prescription.
 *
 * @param	DoliDB	$db				Database handler
 * @param	User	$user			Acting user
 * @param	array	$cart			Cart lines: {fk_product, qty}
 * @param	int		$warehouseId	Warehouse to dispense from
 * @param	string	$channel		Payment channel (CASH|SCAN)
 * @param	string	$channelRef		Scan reference (required for SCAN)
 * @param	array	$info			Out: refs/ids of every created document
 * @return	int						1 ok, -1 walk-in/prescription, -2 issue, -3 dispense, -4 stock, -5 bill, -6 payment
 */
function pharmacy_retail_checkout($db, $user, array $cart, $warehouseId, $channel, $channelRef, &$info = array())
{
	global $langs;

	$info = array(
		'error' => '', 'partial' => false,
		'walkin' => 0, 'prescription' => 0, 'prescription_ref' => '',
		'dispense' => 0, 'dispense_ref' => '', 'bill' => 0, 'bill_ref' => '',
	);
	dol_include_once('/prescription/class/prescriptionsheet.class.php');
	dol_include_once('/clinicpay/class/paybill.class.php');
	$langs->load('pharmacy@pharmacy');

	$note = $langs->trans('PharmacyRetailUsageNote');

	$walkin = patient_ensure_walkin($db, $user);
	if ($walkin <= 0) {
		$info['error'] = 'PharmacyRetailErrWalkin';
		return -1;
	}
	$info['walkin'] = $walkin;

	// 1. Internal OTC prescription (no doctor, no visit, walk-in patient)
	$ps = new PrescriptionSheet($db);
	$ps->presc_type = PRESCRIPTION_TYPE_OTC;
	$ps->fk_patient = $walkin;
	$ps->fk_doctor = 0;
	$ps->fk_medrecord = 0;
	$ps->date_presc = dol_now();
	$ps->usage_note = $note;
	$lines = array();
	foreach ($cart as $c) {
		$fkProduct = isset($c['fk_product']) ? (int) $c['fk_product'] : 0;
		$qty = isset($c['qty']) ? (float) $c['qty'] : 0;
		if ($fkProduct <= 0 || $qty <= 0) {
			continue;
		}
		$lines[] = array('fk_product' => $fkProduct, 'label' => (string) $c['label'], 'product_ref' => (string) $c['ref'], 'qty' => $qty);
	}
	if (empty($lines)) {
		$info['error'] = 'PharmacyRetailErrEmptyCart';
		return -1;
	}
	$ps->lines = $lines;
	if ($ps->create($user) <= 0) {
		$info['error'] = $ps->error ? $ps->error : 'PharmacyRetailErrPrescription';
		return -1;
	}
	$info['prescription'] = (int) $ps->id;
	$info['prescription_ref'] = $ps->ref;

	// 2. Issue it so the pharmacy module accepts the dispense
	if ($ps->issue($user) <= 0) {
		$info['error'] = $ps->error ? $ps->error : 'PharmacyRetailErrIssue';
		$info['partial'] = true;
		return -2;
	}

	// 3. Dispense sheet + confirm (reverse stock movements with FEFO batches)
	$disp = new Dispense($db);
	if ($disp->createFromPrescription($user, $ps, (int) $warehouseId, $note) <= 0) {
		$info['error'] = $disp->error ? $disp->error : 'PharmacyRetailErrDispense';
		$info['partial'] = true;
		return -3;
	}
	$info['dispense'] = (int) $disp->id;
	$info['dispense_ref'] = $disp->ref;
	if ($disp->confirm($user) <= 0) {
		$info['error'] = $disp->error ? $disp->error : 'PharmacyRetailErrDispenseConfirm';
		$info['partial'] = true;
		return -4;
	}

	// 4. Charge bill, now payable, traced back to the dispense line by line
	$billLines = array();
	foreach ($ps->lines as $l) {
		if (empty($l['fk_product']) || empty($l['qty'])) {
			continue;
		}
		$billLines[] = array(
			'fk_product' => (int) $l['fk_product'],
			'qty' => (float) $l['qty'],
			'fk_prescription' => (int) $ps->id,
			'fk_dispense' => (int) $disp->id,
		);
	}
	$pb = new Paybill($db);
	if ($pb->create($user, array('fk_patient' => $walkin, 'lines' => $billLines, 'note' => $note)) <= 0) {
		$info['error'] = $pb->error ? $pb->error : 'PharmacyRetailErrBill';
		$info['partial'] = true;
		return -5;
	}
	$info['bill'] = (int) $pb->id;
	$info['bill_ref'] = $pb->ref;

	// 5. Take the money (native invoice + payment, one transaction)
	if ($pb->confirm($user, $channel, $channelRef) <= 0) {
		$info['error'] = $pb->error ? $pb->error : 'PharmacyRetailErrPayment';
		$info['partial'] = true;
		return -6;
	}
	return 1;
}

/**
 * Parse the batch list Dispense::confirm() writes into
 * pharmacy_dispense_line.batch_note ("BATCH/2027-10-03, BATCH2/...").
 *
 * Kept as a helper because the note is free text: any consumer that needs the
 * batches of a dispensing line has to split it the same way, and the format is
 * defined in exactly one place (Dispense::confirm()).
 *
 * @param	string	$note		Value of pharmacy_dispense_line.batch_note
 * @return	string[]			Batch numbers, in the order they were written
 */
function pharmacy_parse_batch_note($note)
{
	$out = array();
	foreach (explode(',', (string) $note) as $chunk) {
		$parts = explode('/', trim($chunk));
		$batch = trim($parts[0]);
		if ($batch !== '') {
			$out[] = $batch;
		}
	}
	return $out;
}

/**
 * Read the dispensing sheet reference out of a stock movement label.
 *
 * Dispense::confirm() writes "Dispense {ref}" on the outbound movements and
 * Dispense::returnSheet() writes "Return {ref}" on the ones that put the goods
 * back, so the label is the only link from a stock movement to its sheet.
 *
 * @param	string	$label		llx_stock_mouvement.label
 * @return	string				Sheet ref, or '' when the label is not ours
 */
function pharmacy_movement_sheet_ref($label)
{
	$label = trim((string) $label);
	foreach (array('Dispense ', 'Return ') as $prefix) {
		if (strpos($label, $prefix) === 0) {
			return trim(substr($label, strlen($prefix)));
		}
	}
	return '';
}

/**
 * @param	string	$label		llx_stock_mouvement.label
 * @return	int					1 outbound, -1 returned to stock, 0 not a dispensing movement
 */
function pharmacy_movement_direction($label)
{
	$label = trim((string) $label);
	if (strpos($label, 'Dispense ') === 0) {
		return 1;
	}
	if (strpos($label, 'Return ') === 0) {
		return -1;
	}
	return 0;
}

/**
 * Stock position of one product for the prescription screen: how much is on
 * hand and which batch FEFO would pick first.
 *
 * The batch query mirrors Dispense::allocateFefo() on purpose (same ordering,
 * same exclusions): expired lots and lots under a sales hold (BLOCK) are not
 * stock the dispenser can use, so showing them would promise something the
 * pharmacy cannot hand over.
 *
 * Read-only: no stock is touched.
 *
 * @param	DoliDB		$db				Database handler
 * @param	int			$fkProduct		Product id
 * @param	int			$warehouseId	0 = every warehouse
 * @return	array{reel:float,batch:string,sellby:int,eatby:int,batch_qty:float}|null	null when the product is unknown
 */
function pharmacy_stock_available($db, $fkProduct, $warehouseId = 0)
{
	$fkProduct = (int) $fkProduct;
	if ($fkProduct <= 0) {
		return null;
	}
	$P = $db->prefix();

	$sql = "SELECT COALESCE(SUM(ps.reel), 0) AS reel FROM ".$P."product_stock AS ps";
	$sql .= " WHERE ps.fk_product = ".$fkProduct;
	if ($warehouseId > 0) {
		$sql .= " AND ps.fk_entrepot = ".(int) $warehouseId;
	}
	$resql = $db->query($sql);
	$reel = $resql ? (float) $db->fetch_object($resql)->reel : 0.0;
	if ($resql) {
		$db->free($resql);
	}

	$out = array('reel' => $reel, 'batch' => '', 'sellby' => 0, 'eatby' => 0, 'batch_qty' => 0.0);
	if ($reel <= 0) {
		return $out;
	}

	// FEFO: soonest sell-by first, lots without a date last.
	$sql = "SELECT pb.batch, pb.qty, pl.eatby, pl.sellby FROM ".$P."product_batch AS pb";
	$sql .= " INNER JOIN ".$P."product_stock AS ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " LEFT JOIN ".$P."product_lot AS pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch";
	// Same join the dispenser uses, so "first available batch" here is exactly
	// the batch allocateFefo() would pick (excludes BLOCK / restarted lots).
	dol_include_once('/pharmacy/class/pharmacybatchaction.class.php');
	$sql .= PharmacyBatchAction::latestOpJoin('ps.fk_product', 'pb.batch');
	$sql .= " WHERE ps.fk_product = ".$fkProduct." AND pb.qty > 0";
	if ($warehouseId > 0) {
		$sql .= " AND ps.fk_entrepot = ".(int) $warehouseId;
	}
	$sql .= " AND (pl.sellby IS NULL OR pl.sellby > '".$db->escape(date('Y-m-d'))."')";
	$sql .= " AND (pl.eatby IS NULL OR pl.eatby > '".$db->escape(date('Y-m-d'))."')";
	$sql .= " AND (ea.op IS NULL OR ea.op <> 'BLOCK')";
	$sql .= " ORDER BY (pl.sellby IS NULL) ASC, pl.sellby ASC, (pl.eatby IS NULL) ASC, pl.eatby ASC, pb.batch ASC";
	$resql = $db->query($sql);
	$first = null;
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$first = $o;
			break;
		}
		$db->free($resql);
	}
	if ($first) {
		$out['batch'] = (string) $first->batch;
		$out['batch_qty'] = (float) $first->qty;
		$out['sellby'] = $first->sellby ? (int) strtotime((string) $first->sellby) : 0;
		$out['eatby'] = $first->eatby ? (int) strtotime((string) $first->eatby) : 0;
	}
	return $out;
}

/**
 * Link a returned dispensing sheet to the money side.
 *
 * A return puts the goods back on the shelf, but the patient already paid: the
 * bill has to be credited back or the books do not balance. This prepares that
 * credit as a DRAFT through Paybill::createRefundDraft() and leaves the
 * execution to the cashier, because how much to refund (and whether the shop
 * refunds at all) is a business decision, not something a stock return may
 * decide on its own.
 *
 * Only sheets whose bill is PAID and already invoiced can be drafted, which is
 * exactly what createRefundDraft() enforces; the other outcomes are counted in
 * $info so the page can say something useful.
 *
 * @param	DoliDB		$db			Database handler
 * @param	User		$user			Actor
 * @param	int			$dispenseId		Returned sheet
 * @param	string		$dispenseRef	Its ref, for the reason text
 * @param	array		$info			Out: drafts[], unpaid, exists, failed, noclinicpay
 * @return	int							Number of drafts created
 */
function pharmacy_return_draft_refund($db, $user, $dispenseId, $dispenseRef, &$info = array())
{
	$info = array('drafts' => array(), 'unpaid' => 0, 'exists' => 0, 'failed' => 0);
	if (!isModEnabled('clinicpay')) {
		$info['noclinicpay'] = 1;
		return 0;
	}
	dol_include_once('/clinicpay/class/paybill.class.php');

	$P = $db->prefix();
	$sql = "SELECT fk_bill, COALESCE(SUM(subprice_total),0) AS amount FROM ".$P."clinicpay_bill_line";
	$sql .= " WHERE fk_dispense = ".(int) $dispenseId." GROUP BY fk_bill";
	$resql = $db->query($sql);
	$bills = array();
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$bills[] = $o;
		}
		$db->free($resql);
	}
	if (empty($bills)) {
		return 0;
	}

	foreach ($bills as $b) {
		$bill = new Paybill($db);
		if ($bill->fetch((int) $b->fk_bill) <= 0) {
			$info['failed']++;
			continue;
		}
		if ((int) $bill->status !== CLINICPAY_BILL_PAID) {
			$info['unpaid']++;
			continue;
		}
		if ($bill->findRefundDraft() > 0) {
			$info['exists']++;
			continue;
		}
		$rc = $bill->createRefundDraft($user, 'Drug return '.$dispenseRef, (float) $b->amount);
		if ($rc > 0) {
			$info['drafts'][] = array('id' => (int) $bill->id, 'ref' => $bill->ref, 'amount' => (float) $b->amount);
		} else {
			$info['failed']++;
		}
	}
	return count($info['drafts']);
}
