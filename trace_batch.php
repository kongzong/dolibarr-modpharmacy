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
 * \file    htdocs/custom/pharmacy/trace_batch.php
 * \ingroup pharmacy
 * \brief   Batch traceability: follow one batch from goods receipt to the
 *          patients who received it, and reconcile the arithmetic.
 *
 * Design notes:
 * - Inbound rows come from llx_receptiondet_batch (the receiving side records
 *   the batch, its dates and the purchase order it belongs to).
 * - Outbound rows come from llx_stock_mouvement, NOT from
 *   pharmacy_dispense_line.batch_note: the movement table has a real `batch`
 *   column with one row per batch actually consumed (so a sheet spanning
 *   several batches is split correctly instead of guessed), and it also carries
 *   the "Return {ref}" rows Dispense::returnSheet() writes when goods go back
 *   to the shelf. The label is the only link to the sheet, see
 *   pharmacy_movement_sheet_ref().
 * - Reconciliation: receipts - dispensed + returned - scrapped = on hand. The
 *   return has to be added back, otherwise returning a box looks like a loss.
 * - Without a single receipt row there is nothing to reconcile against: the
 *   balance comes from an opening stock import, so a "difference" badge would
 *   be a false alarm.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/pharmacybatchaction.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "pharmacy@pharmacy"));

if (!$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$P = $db->prefix();

$fkProduct = GETPOSTINT('product');
$batch = trim(GETPOST('batch', 'alpha'));
$wid = GETPOSTINT('wid');

// Product picker: only batch-managed products can be traced at all.
$productOptions = array();
$resql = $db->query("SELECT p.rowid, p.ref, p.label FROM ".$P."product AS p"
	." WHERE p.entity IN (".getEntity('product').") AND p.tobatch = 1"
	." ORDER BY p.ref");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$productOptions[(string) $o->rowid] = trim($o->label.' ['.$o->ref.']');
	}
	$db->free($resql);
}

$info = null;
$receipts = array();
$issues = array();
$qtyIn = 0.0;
$qtyOut = 0.0;
$qtyBack = 0.0;
$qtyScrap = 0.0;
$qtyOnHand = 0.0;
$nbIssue = 0;
$nbBack = 0;

if ($fkProduct > 0 && $batch !== '') {
	// ---- Current state of the batch -------------------------------------------
	$sql = "SELECT ps.fk_entrepot, w.lieu AS warehouse_lieu, w.ref AS warehouse_ref";
	$sql .= ", pb.qty AS batch_qty, pl.eatby AS lot_eatby, pl.sellby AS lot_sellby";
	$sql .= ", pb.eatby AS batch_eatby, pb.sellby AS batch_sellby, ea.op AS block_op";
	$sql .= ", p.ref AS product_ref, p.label AS product_label";
	$sql .= " FROM ".$P."product_batch AS pb";
	$sql .= " INNER JOIN ".$P."product_stock AS ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " LEFT JOIN ".$P."product_lot AS pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch";
	$sql .= " LEFT JOIN ".$P."product AS p ON p.rowid = ps.fk_product";
	$sql .= " LEFT JOIN ".$P."entrepot AS w ON w.rowid = ps.fk_entrepot";
	$sql .= PharmacyBatchAction::latestOpJoin('ps.fk_product', 'pb.batch');
	$sql .= " WHERE ps.fk_product = ".((int) $fkProduct)." AND pb.batch = '".$db->escape($batch)."'";
	if ($wid > 0) {
		$sql .= " AND ps.fk_entrepot = ".((int) $wid);
	}
	$resql = $db->query($sql);
	if (!$resql) {
		dol_print_error($db);
		exit;
	}
	$info = array('warehouses' => array());
	while ($o = $db->fetch_object($resql)) {
		// One batch can sit in several warehouses; the header sums them.
		$info['product_ref'] = (string) $o->product_ref;
		$info['product_label'] = (string) $o->product_label;
		$info['block_op'] = (string) $o->block_op;
		$qtyOnHand += (float) $o->batch_qty;
		if (!empty($o->warehouse_lieu)) {
			$info['warehouses'][] = trim((string) $o->warehouse_lieu.(empty($o->warehouse_ref) ? '' : ' - '.$o->warehouse_ref));
		}
		// product_lot holds DATE, product_batch holds DATETIME; prefer the lot.
		$info['eatby'] = !empty($o->lot_eatby) ? $o->lot_eatby : $o->batch_eatby;
		$info['sellby'] = !empty($o->lot_sellby) ? $o->lot_sellby : $o->batch_sellby;
	}
	$db->free($resql);

	// ---- Inbound --------------------------------------------------------------
	$sql = "SELECT rb.rowid, rb.datec, rb.qty, rb.eatby, rb.sellby, rb.fk_reception";
	$sql .= ", r.ref AS reception_ref, r.date_creation AS reception_date, s.nom AS supplier";
	$sql .= " FROM ".$P."receptiondet_batch AS rb";
	$sql .= " LEFT JOIN ".$P."reception AS r ON r.rowid = rb.fk_reception";
	$sql .= " LEFT JOIN ".$P."societe AS s ON s.rowid = r.fk_soc";
	$sql .= " WHERE rb.fk_product = ".((int) $fkProduct)." AND rb.batch = '".$db->escape($batch)."'";
	$sql .= " ORDER BY rb.datec DESC, rb.rowid DESC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$receipts[] = $o;
			$qtyIn += (float) $o->qty;
		}
		$db->free($resql);
	}

	// ---- Outbound and returns -------------------------------------------------
	// Stock movements are the authoritative record: one row per batch actually
	// moved, with the sheet ref inside the label.
	$sql = "SELECT m.rowid, m.value, m.label, m.datem";
	$sql .= ", d.rowid AS fk_dispense, d.ref AS dispense_ref, d.date_dispense, d.fk_patient, d.status";
	$sql .= ", pr.ref AS presc_ref, pp.card_no, s.nom AS patient_name";
	$sql .= " FROM ".$P."stock_mouvement AS m";
	$sql .= " LEFT JOIN ".$P."pharmacy_dispense AS d";
	$sql .= " ON m.label = CONCAT('Dispense ', d.ref) OR m.label = CONCAT('Return ', d.ref)";
	$sql .= " LEFT JOIN ".$P."prescription AS pr ON pr.rowid = d.fk_prescription";
	$sql .= " LEFT JOIN ".$P."patient_profile AS pp ON pp.rowid = d.fk_patient";
	$sql .= " LEFT JOIN ".$P."societe AS s ON s.rowid = pp.fk_soc";
	$sql .= " WHERE m.fk_product = ".((int) $fkProduct)." AND m.batch = '".$db->escape($batch)."'";
	$sql .= " AND (m.label LIKE 'Dispense %' OR m.label LIKE 'Return %')";
	$sql .= " ORDER BY m.datem DESC, m.rowid DESC";
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$dir = pharmacy_movement_direction((string) $o->label);
			if ($dir === 0) {
				continue;
			}
			$o->direction = $dir;
			$o->sheet_ref = pharmacy_movement_sheet_ref((string) $o->label);
			$o->qty = abs((float) $o->value);
			$issues[] = $o;
			if ($dir > 0) {
				$qtyOut += $o->qty;
				$nbIssue++;
			} else {
				$qtyBack += $o->qty;
				$nbBack++;
			}
		}
		$db->free($resql);
	}

	// ---- Scrapped -------------------------------------------------------------
	$sql = "SELECT COALESCE(SUM(qty),0) AS q FROM ".$P."pharmacy_expiry_action";
	$sql .= " WHERE entity = ".((int) $conf->entity)." AND op = 'SCRAP'";
	$sql .= " AND fk_product = ".((int) $fkProduct)." AND batch = '".$db->escape($batch)."'";
	$resql = $db->query($sql);
	if ($resql) {
		$qtyScrap = (float) $db->fetch_object($resql)->q;
		$db->free($resql);
	}
}

$hasReceipts = !empty($receipts);
$expected = $qtyIn - $qtyOut + $qtyBack - $qtyScrap;
$delta = $qtyOnHand - $expected;
$reconciled = ($hasReceipts && abs($delta) < 0.0005);

llxHeader('', $langs->trans("PharmacyTraceBatch"));

print load_fiche_titre(
	$langs->trans("PharmacyTraceBatch"),
	'<a class="butAction" href="'.dol_buildpath('/pharmacy/report_stock.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("PharmacyReportStock").'</a>',
	'fa-search-plus'
);

$form = new Form($db);

// ---- Picker -------------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formtrace">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans("PharmacyTracePick").'</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("PharmacyExpiryProduct").'</td><td class="nowrap">';
print $form->selectarray('product', $productOptions, $fkProduct > 0 ? (string) $fkProduct : '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
print '</td>';
print '<td class="nowrap">'.$langs->trans("PharmacyExpiryBatch").'</td><td class="nowrap">';
print '<input name="batch" class="minwidth200" value="'.dol_escape_htmltag($batch).'">';
print '</td></tr>';
print '<tr>';
print '<td colspan="4" class="center">';
print '<button type="submit" class="button" name="submitsearch" value="1">'.$langs->trans("Search").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

if (!empty($info)) {
	$warehouses = array_unique($info['warehouses']);
	$eatby = !empty($info['eatby']) ? $info['eatby'] : null;
	$sellby = !empty($info['sellby']) ? $info['sellby'] : null;

	// ---- Batch header ---------------------------------------------------------
	print '<div class="fichecenter marginbottomonly">';
	print '<span class="badge badge-status4">'.trim($info['product_label'].' ['.$info['product_ref'].']').'</span> ';
	print '<span class="badge badge-status1">'.$langs->trans("PharmacyExpiryBatch").' '.dol_escape_htmltag($batch).'</span> ';
	if ($warehouses) {
		print '<span class="badge badge-status2">'.implode(' / ', $warehouses).'</span> ';
	}
	print '<span class="badge badge-status3">'.$langs->trans("PharmacyTraceOnHand").' '.number_format($qtyOnHand, 3, '.', '').'</span> ';
	if ($sellby) {
		print '<span class="badge badge-status2">'.$langs->trans("PharmacyReportStockColSellBy").' '.dol_print_date($db->jdate($sellby), 'day').'</span> ';
	}
	if ($eatby) {
		print '<span class="badge badge-status2">'.$langs->trans("PharmacyReportStockColEatBy").' '.dol_print_date($db->jdate($eatby), 'day').'</span> ';
	}
	if ($info['block_op'] === 'BLOCK') {
		print '<span class="badge badge-status8">'.$langs->trans("PharmacyExpiryBlockedShort").'</span>';
	}
	print '</div>';

	// ---- Reconciliation -------------------------------------------------------
	print '<div class="fichecenter marginbottomonly">';
	print '<span class="opacitymedium">'.$langs->trans("PharmacyTraceRecon").'</span> ';
	print '<span class="badge badge-status1">'.$langs->trans("PharmacyTraceIn").' '.number_format($qtyIn, 3, '.', '').'</span> ';
	print '<span class="badge badge-status8">- '.$langs->trans("PharmacyTraceOut").' '.number_format($qtyOut, 3, '.', '').'</span> ';
	print '<span class="badge badge-status5">+ '.$langs->trans("PharmacyTraceBack").' '.number_format($qtyBack, 3, '.', '').'</span> ';
	print '<span class="badge badge-status8">- '.$langs->trans("PharmacyExpiryOpScrap").' '.number_format($qtyScrap, 3, '.', '').'</span> ';
	if ($hasReceipts) {
		print '<span class="badge badge-status2">= '.$langs->trans("PharmacyTraceExpected").' '.number_format($expected, 3, '.', '').'</span> ';
		if ($reconciled) {
			print '<span class="badge badge-status4">'.$langs->trans("PharmacyTraceOk").'</span>';
		} else {
			print '<span class="badge badge-status1">'.$langs->trans("PharmacyTraceDiff").' '.number_format($delta, 3, '.', '').'</span>';
		}
	} else {
		print '<span class="badge badge-status2">'.$langs->trans("PharmacyTraceNoReceipts").'</span>';
	}
	print '</div>';
	if (!$hasReceipts) {
		print '<div class="opacitymedium clearboth">'.$langs->trans("PharmacyTraceNoReceiptsHint").'</div>';
	}

	// ---- Inbound --------------------------------------------------------------
	print '<div class="fichecenter margin-top"><span class="opacitymedium">'.$langs->trans("PharmacyTraceInbound").' ('.count($receipts).')</span></div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th class="liste_titre center nowrap">'.$langs->trans("DateCreation").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PharmacyTraceSupplier").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PharmacyTraceOrder").'</th>';
	print '<th class="liste_titre right">'.$langs->trans("PharmacyTraceQty").'</th>';
	print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyReportStockColEatBy").'</th>';
	print '</tr>';
	if (empty($receipts)) {
		print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans("PharmacyTraceNoInbound").'</span></td></tr>';
	}
	foreach ($receipts as $r) {
		print '<tr class="oddeven">';
		print '<td class="center nowrap">'.dol_print_date($db->jdate($r->datec), 'dayhour').'</td>';
		print '<td>'.dol_escape_htmltag((string) $r->supplier).'</td>';
		print '<td>'.dol_escape_htmltag((string) $r->reception_ref).'</td>';
		print '<td class="right">'.(float) $r->qty.'</td>';
		print '<td class="center nowrap">'.(!empty($r->sellby) ? dol_print_date($db->jdate($r->sellby), 'day') : (!empty($r->eatby) ? dol_print_date($db->jdate($r->eatby), 'day') : '')).'</td>';
		print '</tr>';
	}
	print '</table></div>';

	// ---- Outbound and returns -------------------------------------------------
	print '<div class="fichecenter margin-top"><span class="opacitymedium">';
	print $langs->trans("PharmacyTraceOutbound").' ('.$nbIssue.' + '.$langs->trans("PharmacyTraceBack").' '.$nbBack.')';
	print '</span></div>';

	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyDateDispense").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PharmacyTraceColDirection").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PharmacyTraceSheet").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PharmacyPrescription").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PatientCardNo").'</th>';
	print '<th class="liste_titre">'.$langs->trans("ThirdPartyName").'</th>';
	print '<th class="liste_titre right">'.$langs->trans("PharmacyTraceQty").'</th>';
	print '</tr>';
	if (empty($issues)) {
		print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyTraceNoMovements").'</span></td></tr>';
	}
	foreach ($issues as $r) {
		print '<tr class="oddeven">';
		print '<td class="center nowrap">'.($r->date_dispense ? dol_print_date($db->jdate($r->date_dispense), 'dayhour') : dol_print_date($db->jdate($r->datem), 'dayhour')).'</td>';
		if ($r->direction > 0) {
			print '<td><span class="badge badge-status8">'.$langs->trans("PharmacyTraceMoveOut").'</span></td>';
		} else {
			print '<td><span class="badge badge-status4">'.$langs->trans("PharmacyTraceMoveBack").'</span></td>';
		}
		$sheetCell = ((int) $r->fk_dispense > 0)
			? '<a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.(int) $r->fk_dispense.'">'.dol_escape_htmltag((string) $r->dispense_ref).'</a>'
			: '<span class="opacitymedium">'.dol_escape_htmltag((string) $r->sheet_ref).'</span>';
		print '<td>'.$sheetCell.'</td>';
		print '<td>'.(!empty($r->presc_ref) ? dol_escape_htmltag((string) $r->presc_ref) : '<span class="opacitymedium">-</span>').'</td>';
		print '<td>'.((int) $r->fk_patient > 0 ? '<a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $r->fk_patient.'">'.dol_escape_htmltag((string) $r->card_no).'</a>' : '').'</td>';
		print '<td>'.dol_escape_htmltag((string) $r->patient_name).'</td>';
		print '<td class="right">'.number_format((float) $r->qty, 3, '.', '').'</td>';
		print '</tr>';
	}
	print '</table></div>';
	if (empty($issues)) {
		print '<div class="opacitymedium clearboth">'.$langs->trans("PharmacyTraceNoMovementsHint").'</div>';
	}
} elseif ($fkProduct > 0 && $batch !== '') {
	print '<div class="warning clearboth">'.$langs->trans("PharmacyTraceNotFound").'</div>';
}

llxFooter();
$db->close();
