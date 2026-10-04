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
 * \file    htdocs/custom/pharmacy/report_expiry_action.php
 * \ingroup pharmacy
 * \brief   Expiry disposition ledger: near-expiry batches with the disposition
 *          they carry, plus the full trail of scrap / block / unblock actions.
 *
 * Design notes:
 * - The batch side of the page deliberately reuses PharmacyExpiryAlert::collect()
 *   instead of re-querying: the alert page, the FEFO guard and this ledger must
 *   never disagree on what "expired" or "blocked" means.
 * - The action trail comes from llx_pharmacy_expiry_action, which is
 *   append-only, so this page is read-only too.
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
dol_include_once('/pharmacy/class/pharmacyexpiryalert.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("pharmacy@pharmacy"));

if (!$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$P = $db->prefix();

/**
 * Human label for a llx_pharmacy_expiry_action.op code.
 *
 * @param	string	$op	SCRAP | BLOCK | UNBLOCK
 * @return	string
 */
function pharmacy_expiry_op_label($op)
{
	global $langs;
	$map = array(
		'SCRAP' => $langs->trans("PharmacyExpiryOpScrap"),
		'BLOCK' => $langs->trans("PharmacyExpiryOpBlock"),
		'UNBLOCK' => $langs->trans("PharmacyExpiryOpUnblock"),
	);
	return isset($map[$op]) ? $map[$op] : (string) $op;
}

// ---- Filters ------------------------------------------------------------------
$windowDays = GETPOSTINT('search_window');
if ($windowDays <= 0) {
	$windowDays = 90;
}
$searchOp = GETPOST('search_op', 'aZ09');
if (!in_array($searchOp, array('SCRAP', 'BLOCK', 'UNBLOCK'), true)) {
	$searchOp = '';
}
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
if ($dateFrom <= 0 && $dateTo <= 0) {
	// Default to the same horizon as the near-expiry board below it.
	$dateFrom = dol_time_plus_duree(dol_now(), -$windowDays, 'd');
	$dateTo = dol_now();
}
$action = GETPOST('action', 'aZ09');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$windowDays = 90;
	$searchOp = '';
	$dateFrom = dol_time_plus_duree(dol_now(), -90, 'd');
	$dateTo = dol_now();
}

// ---- Near-expiry batches and their current disposition -----------------------
$alert = new PharmacyExpiryAlert($db);
$batches = $alert->collect($windowDays, (int) getDolGlobalString('PHARMACY_WAREHOUSE_ID'));
if (!is_array($batches)) {
	$batches = array();
}
$nbBlocked = 0;
$nbOpen = 0;
$endOfToday = strtotime(date('Y-m-d').' 23:59:59');
foreach ($batches as $b) {
	if (!empty($b['blocked'])) {
		$nbBlocked++;
	} else {
		$nbOpen++;
	}
}

// ---- Action trail -------------------------------------------------------------
$from = " FROM ".$P."pharmacy_expiry_action AS a";
$from .= " LEFT JOIN ".$P."product AS p ON p.rowid = a.fk_product";
$from .= " LEFT JOIN ".$P."entrepot AS w ON w.rowid = a.fk_entrepot";
$from .= " LEFT JOIN ".$P."user AS u ON u.rowid = a.fk_user_creat";

$where = " WHERE a.entity = ".((int) $conf->entity);
if ($dateFrom > 0) {
	$where .= " AND a.date_creation >= '".$db->idate($dateFrom)."'";
}
if ($dateTo > 0) {
	$where .= " AND a.date_creation <= '".$db->idate($dateTo)."'";
}
if ($searchOp !== '') {
	$where .= " AND a.op = '".$db->escape($searchOp)."'";
}

$sql = "SELECT COUNT(*) AS nb_action, COALESCE(SUM(CASE WHEN a.op = 'SCRAP' THEN a.qty ELSE 0 END),0) AS qty_scrap";
$sql .= ", COALESCE(SUM(CASE WHEN a.op = 'BLOCK' THEN 1 ELSE 0 END),0) AS nb_block";
$sql .= ", COALESCE(SUM(CASE WHEN a.op = 'UNBLOCK' THEN 1 ELSE 0 END),0) AS nb_unblock";
$sql .= ", COUNT(DISTINCT CONCAT(a.fk_product, '#', a.batch)) AS nb_batch";
$sql .= $from.$where;
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$totals = $db->fetch_object($resql);
$db->free($resql);

$sql = "SELECT a.rowid, a.date_creation, a.op, a.qty, a.batch, a.note";
$sql .= ", p.ref AS product_ref, p.label AS product_label";
$sql .= ", w.lieu AS warehouse_lieu";
$sql .= ", CONCAT_WS(' ', u.lastname, u.firstname) AS user_label";
$sql .= $from.$where." ORDER BY a.date_creation DESC, a.rowid DESC";
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$actions = array();
while ($o = $db->fetch_object($resql)) {
	$actions[] = $o;
}
$nbAction = count($actions);
$db->free($resql);

// ---- CSV (must run before any output) ----------------------------------------
if ($action === 'export') {
	$csvName = 'pharmacy_expiry_action_'.dol_now('%Y%m%d%H%M%S');
	$csvDir = empty($conf->pharmacy->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->pharmacy->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		// UTF-8 BOM so Excel reads the Chinese header and values correctly.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans("DateCreation"),
			$langs->trans("PharmacyExpiryOp"),
			$langs->trans("PharmacyExpiryProduct"),
			$langs->trans("PharmacyExpiryBatch"),
			$langs->trans("PharmacyWarehouse"),
			$langs->trans("PharmacyTraceQty"),
			$langs->trans("PharmacyExpiryOpUser"),
			$langs->trans("PharmacyColNote"),
		));
		foreach ($actions as $r) {
			fputcsv($fh, array(
				dol_print_date($db->jdate($r->date_creation), 'dayhour'),
				pharmacy_expiry_op_label((string) $r->op),
				trim($r->product_label.' ['.$r->product_ref.']'),
				(string) $r->batch,
				(string) $r->warehouse_lieu,
				number_format((float) $r->qty, 2, '.', ''),
				(string) $r->user_label,
				(string) $r->note,
			));
		}
		fclose($fh);
		top_httphead('text/csv; charset=UTF-8');
		header('Content-Description: File Transfer');
		header('Content-Disposition: attachment; filename="'.$csvName.'.csv"');
		header('Cache-Control: Public, must-revalidate');
		header('Pragma: public');
		readfile($csvFile);
		exit;
	}
	setEventMessages($langs->trans("PharmacyReportExportFailed"), null, 'errors');
}

llxHeader('', $langs->trans("PharmacyExpiryActionReport"));

print load_fiche_titre(
	$langs->trans("PharmacyExpiryActionReport"),
	'<a class="butAction" href="'.dol_buildpath('/pharmacy/expiry.php', 1).'"><span class="fa fa-clock fa-fw valignmiddle"></span>'.$langs->trans("PharmacyExpiry").'</a>',
	'fa-clipboard-list'
);

$form = new Form($db);

// ---- Filter form --------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formexpiryfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="search_window" value="'.(int) $windowDays.'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans("PharmacyReportFilter").'</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("PharmacyLedgerDate").'</td><td class="nowrap">';
print $form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - ';
print $form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td class="nowrap">'.$langs->trans("PharmacyExpiryOp").'</td><td class="nowrap">';
$opOptions = array(
	'' => $langs->trans("PharmacyReportFilterAll"),
	'SCRAP' => pharmacy_expiry_op_label('SCRAP'),
	'BLOCK' => pharmacy_expiry_op_label('BLOCK'),
	'UNBLOCK' => pharmacy_expiry_op_label('UNBLOCK'),
);
print $form->selectarray('search_op', $opOptions, $searchOp, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td></tr>';
print '<tr>';
print '<td colspan="4" class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="action" value="export">'.$langs->trans("PharmacyReportExport").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Summary ------------------------------------------------------------------
print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status2">'.$langs->trans("PharmacyExpiryBatches").' '.count($batches).'</span> ';
print '<span class="badge badge-status9">'.$langs->trans("PharmacyExpiryBlocked").' '.$nbBlocked.'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("PharmacyExpiryOpen").' '.$nbOpen.'</span> ';
print '<span class="badge badge-status4">'.$langs->trans("PharmacyExpiryActionCount").' '.(int) $totals->nb_action.'</span> ';
print '<span class="badge badge-status8">'.pharmacy_expiry_op_label('SCRAP').' '.$langs->trans("PharmacyTraceQty").' '.price((float) $totals->qty_scrap).'</span> ';
print '<span class="badge badge-status5">'.pharmacy_expiry_op_label('BLOCK').' '.(int) $totals->nb_block.' / '.pharmacy_expiry_op_label('UNBLOCK').' '.(int) $totals->nb_unblock.'</span>';
print '</div>';

// ---- Near-expiry batches ------------------------------------------------------
print '<div class="fichecenter margin-top"><span class="opacitymedium">';
print $langs->trans("PharmacyExpiryBatchesTitle", $windowDays).' ('.count($batches).')';
print '</span></div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryProduct").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryBatch").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyExpiryDate").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyTraceQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyWarehouse").'</th>';
print '<th class="liste_titre center">'.$langs->trans("PharmacyExpiryState").'</th>';
print '<th class="liste_titre"></th>';
print '</tr>';

if (empty($batches)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyExpiryNone").'</span></td></tr>';
}
foreach ($batches as $b) {
	// Same rule as the dashboard: sell-by wins over eat-by.
	$limit = $b['sellby'] > 0 ? $b['sellby'] : $b['eatby'];
	$isExpired = ($limit > 0 && $limit <= $endOfToday);
	print '<tr class="oddeven'.($isExpired ? ' error' : '').'">';
	print '<td>'.dol_escape_htmltag($b['product']).'</td>';
	print '<td><a href="'.dol_buildpath('/pharmacy/trace_batch.php', 1).'?product='.(int) $b['fk_product'].'&batch='.urlencode((string) $b['batch']).'&wid='.(int) $b['fk_entrepot'].'">'.dol_escape_htmltag((string) $b['batch']).'</a></td>';
	print '<td class="center nowrap">'.($limit > 0 ? dol_print_date($limit, 'day') : '').'</td>';
	print '<td class="right">'.(float) $b['qty'].'</td>';
	print '<td>'.dol_escape_htmltag((string) $b['warehouse']).'</td>';
	if (!empty($b['blocked'])) {
		print '<td class="center"><span class="badge badge-status8">'.$langs->trans("PharmacyExpiryBlockedShort").'</span></td>';
	} else {
		print '<td class="center"><span class="opacitymedium">'.$langs->trans("PharmacyExpiryOpenShort").'</span></td>';
	}
	print '<td class="right"><a href="'.dol_buildpath('/pharmacy/expiry.php', 1).'?product='.(int) $b['fk_product'].'&batch='.urlencode((string) $b['batch']).'&qty='.(float) $b['qty'].'&wid='.(int) $b['fk_entrepot'].'">'.$langs->trans("PharmacyExpiryDispose").'</a></td>';
	print '</tr>';
}
print '</table></div>';

// ---- Action trail -------------------------------------------------------------
print '<div class="fichecenter margin-top"><span class="opacitymedium">';
print $langs->trans("PharmacyExpiryActionTitle").' ('.$nbAction.')';
print '</span></div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre center nowrap">'.$langs->trans("DateCreation").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryOp").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryProduct").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryBatch").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyWarehouse").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyTraceQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryOpUser").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyColNote").'</th>';
print '</tr>';

if (empty($actions)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("PharmacyExpiryActionNone").'</span></td></tr>';
}
foreach ($actions as $r) {
	$label = trim((string) $r->product_label.' ['.$r->product_ref.']');
	print '<tr class="oddeven">';
	print '<td class="center nowrap">'.dol_print_date($db->jdate($r->date_creation), 'dayhour').'</td>';
	print '<td>'.pharmacy_expiry_op_label((string) $r->op).'</td>';
	print '<td>'.dol_escape_htmltag($label).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->batch).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->warehouse_lieu).'</td>';
	print '<td class="right">'.(float) $r->qty.'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->user_label).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->note).'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
