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
 * \file    htdocs/custom/pharmacy/report.php
 * \ingroup pharmacy
 * \brief   Drug and consumable outflow report: dispensing lines aggregated
 *          per product (times dispensed, total quantity, amount), plus the
 *          flat detail showing the batch note of each dispensing sheet.
 *          Filters: date range on llx_pharmacy_dispense.date_dispense and
 *          product. Read only.
 *
 * ---------------------------------------------------------------------------
 * AMOUNT CALIBRE (口径), decided after checking the live data:
 *   amount = product.price (standard unit price) * dispense_line.qty
 *   The alternative "lookup llx_clinicpay_bill_line.subprice_total via
 *   fk_dispense + fk_product" was rejected because on this dataset it does
 *   not describe the outflow:
 *     - llx_pharmacy_dispense_line #1 dispenses 600 g of 金银花 (product 301)
 *       while the linked bill line only carries 238 g -> subprice_total
 *       (35.70) covers less than half of what actually left the shelf;
 *     - several dispensing lines (e.g. the 296/298 lines of dispensing sheets
 *       #4 and #5) have no bill line at all, so the amount would be 0;
 *     - bill lines with a NULL fk_dispense are legitimately unlinked.
 *   product.price is the same figure used by llx_clinicpay_bill_line.price_unit
 *   (0.15 for these herbs), so the column stays comparable with the billed
 *   amount and works for unbilled dispensing too. Return lines (negative qty)
 *   are kept negative so 出库总量 nets out.
 * ---------------------------------------------------------------------------
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
dol_include_once('/pharmacy/lib/pharmacy.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("products", "stocks", "pharmacy@pharmacy"));

if (!$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

// ---- Filters ------------------------------------------------------------------
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
$searchFkProduct = GETPOSTINT('search_fk_product');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$dateFrom = '';
	$dateTo = '';
	$searchFkProduct = 0;
}

$action = GETPOST('action', 'aZ09');

/**
 * Shared WHERE fragment for dispensing queries.
 *
 * A dispensing sheet with an empty date_dispense (draft, not yet dispensed)
 * is only kept when no date range is asked for.
 *
 * @return string SQL conditions
 */
function pharmacyReportWhere($dateFrom, $dateTo, $searchFkProduct)
{
	global $db, $conf;

	$sql = " WHERE d.entity = ".((int) $conf->entity);
	if ($dateFrom || $dateTo) {
		$sql .= " AND d.date_dispense IS NOT NULL";
		if ($dateFrom) {
			$sql .= " AND d.date_dispense >= '".$db->idate($dateFrom)."'";
		}
		if ($dateTo) {
			$sql .= " AND d.date_dispense <= '".$db->idate($dateTo)."'";
		}
	}
	if ($searchFkProduct > 0) {
		$sql .= " AND dl.fk_product = ".((int) $searchFkProduct);
	}
	return $sql;
}

// ---- Per-product aggregate ----------------------------------------------------
$sql = "SELECT dl.fk_product, p.ref AS pref, p.label AS plabel";
$sql .= ", COUNT(DISTINCT d.rowid) AS nb_dispense";
$sql .= ", SUM(dl.qty) AS total_qty";
// See the AMOUNT CALIBRE note on top: standard unit price * dispensed qty.
$sql .= ", SUM(COALESCE(p.price, 0) * dl.qty) AS total_amount";
$sql .= ", MIN(d.date_dispense) AS first_dispense, MAX(d.date_dispense) AS last_dispense";
$sql .= " FROM ".MAIN_DB_PREFIX."pharmacy_dispense AS d";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."pharmacy_dispense_line AS dl ON dl.fk_dispense = d.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = dl.fk_product";
$sql .= pharmacyReportWhere($dateFrom, $dateTo, $searchFkProduct);
$sql .= " GROUP BY dl.fk_product, p.ref, p.label";
$sql .= " ORDER BY total_amount DESC, total_qty DESC, p.ref";

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$rows = array();
$totals = array('nb_dispense' => 0, 'total_qty' => 0, 'total_amount' => 0);
while ($o = $db->fetch_object($resql)) {
	$rows[] = $o;
	$totals['nb_dispense'] += (int) $o->nb_dispense;
	$totals['total_qty'] += (float) $o->total_qty;
	$totals['total_amount'] += (float) $o->total_amount;
}
$db->free($resql);

// ---- Detail: one row per dispensing line, batch note included -------------------
$sql = "SELECT d.rowid, d.ref, d.date_dispense, d.status";
$sql .= ", dl.rowid AS lineid, dl.fk_product, dl.product_ref, dl.label AS llabel, dl.qty, dl.qty_unit, dl.batch_note";
$sql .= ", pp.rowid AS fk_patient, pp.card_no, s.nom AS patient_name";
$sql .= " FROM ".MAIN_DB_PREFIX."pharmacy_dispense AS d";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."pharmacy_dispense_line AS dl ON dl.fk_dispense = d.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."patient_profile AS pp ON pp.rowid = d.fk_patient";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe AS s ON s.rowid = pp.fk_soc";
$sql .= pharmacyReportWhere($dateFrom, $dateTo, $searchFkProduct);
$sql .= " ORDER BY d.date_dispense DESC, d.rowid DESC, dl.position";

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$details = array();
while ($o = $db->fetch_object($resql)) {
	$details[] = $o;
}
$nbDetail = count($details);
$db->free($resql);

// ---- CSV (must run before any output) ----------------------------------------
if ($action === 'export') {
	$csvName = 'pharmacy_report_outflow'.dol_now('%Y%m%d%H%M%S');
	// Module temp dir: always inside the open_basedir whitelist (d:/dolibarr/...).
	$csvDir = empty($conf->pharmacy->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->pharmacy->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		// UTF-8 BOM so Excel reads the Chinese header/values correctly.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans('Product'),
			$langs->trans('PharmacyReportColProductRef'),
			$langs->trans('PharmacyReportColTimes'),
			$langs->trans('PharmacyReportColQty'),
			$langs->trans('PharmacyReportColUnit'),
			$langs->trans('PharmacyReportColAmount'),
			$langs->trans('PharmacyReportColFirst'),
			$langs->trans('PharmacyReportColLast'),
		));
		foreach ($rows as $r) {
			fputcsv($fh, array(
				(string) $r->plabel,
				(string) $r->pref,
				(int) $r->nb_dispense,
				number_format((float) $r->total_qty, 3, '.', ''),
				(string) $r->qty_unit,
				number_format((float) $r->total_amount, 2, '.', ''),
				(string) $r->first_dispense,
				(string) $r->last_dispense,
			));
		}
		fputcsv($fh, array(
			$langs->trans('PharmacyReportTotal'),
			'',
			$totals['nb_dispense'],
			number_format($totals['total_qty'], 3, '.', ''),
			'',
			number_format($totals['total_amount'], 2, '.', ''),
			'',
			'',
		));
		fclose($fh);
		top_httphead('text/csv; charset=UTF-8');
		header('Content-Description: File Transfer');
		header('Content-Disposition: attachment; filename="'.$csvName.'.csv"');
		header('Cache-Control: Public, must-revalidate');
		header('Pragma: public');
		readfile($csvFile);
		exit;
	}
	setEventMessages($langs->trans('PharmacyReportExportFailed'), null, 'errors');
}

llxHeader('', $langs->trans("PharmacyReport"));

print load_fiche_titre(
	$langs->trans("PharmacyReport"),
	'<a class="butAction" href="'.dol_buildpath('/pharmacy/list.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("PharmacyReportBackToList").'</a>',
	'fa-box-open'
);

$form = new Form($db);

// ---- Product filter options ---------------------------------------------------
$products = array();
$resql = $db->query("SELECT DISTINCT dl.fk_product FROM ".MAIN_DB_PREFIX."pharmacy_dispense_line AS dl"
	." INNER JOIN ".MAIN_DB_PREFIX."pharmacy_dispense AS d ON d.rowid = dl.fk_dispense"
	." WHERE d.entity = ".((int) $conf->entity)." AND dl.fk_product > 0 ORDER BY dl.fk_product");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$products[(int) $o->fk_product] = (int) $o->fk_product;
	}
	$db->free($resql);
}
foreach ($products as $pid => $unused) {
	$productStatic = new Product($db);
	if ($productStatic->fetch($pid) > 0) {
		$products[$pid] = $productStatic->ref.' - '.$productStatic->label;
	} else {
		$products[$pid] = '#'.$pid;
	}
}
ksort($products);

// ---- Filter form --------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formreportfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans("PharmacyReportFilter").'</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("PharmacyReportDate").'</td><td class="nowrap">';
print $form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - ';
print $form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0);
print '</td>';
print '<td class="nowrap">'.$langs->trans("Product").'</td><td class="nowrap">';
print '<select name="search_fk_product" class="minwidth300">';
print '<option value="0">'.$langs->trans("PharmacyReportFilterAll").'</option>';
foreach ($products as $pid => $plabel) {
	print '<option value="'.(int) $pid.'"'.($searchFkProduct == $pid ? ' selected' : '').'>'.dol_escape_htmltag($plabel).'</option>';
}
print '</select></td>';
print '</tr><tr>';
print '<td colspan="2"></td>';
print '<td colspan="2" class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="action" value="export">'.$langs->trans("PharmacyReportExport").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Stat pills ---------------------------------------------------------------
print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status4">'.$langs->trans("PharmacyReportColTimes").' '.$totals['nb_dispense'].'</span> ';
print '<span class="badge badge-status0">'.$langs->trans("PharmacyReportColQty").' '.number_format($totals['total_qty'], 3).'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("PharmacyReportColAmount").' '.price($totals['total_amount']).'</span> ';
print '<span class="badge badge-status4">'.$langs->trans("PharmacyReportDetail").' '.$nbDetail.'</span>';
print '</div>';

// ---- Aggregate table ----------------------------------------------------------
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("Product").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportColTimes").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportColQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyReportColUnit").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportColAmount").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyReportColFirst").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyReportColLast").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyReportNoData").'</span></td></tr>';
}
foreach ($rows as $r) {
	print '<tr class="oddeven">';
	print '<td class="nowrap"><a href="'.dol_buildpath('/product/card.php', 1).'?id='.((int) $r->fk_product).'">'.dol_escape_htmltag((string) $r->plabel).'</a></td>';
	print '<td class="right">'.(int) $r->nb_dispense.'</td>';
	print '<td class="right">'.number_format((float) $r->total_qty, 3).'</td>';
	// The unit is per line (g for herbs, 盒/支/袋 for patent medicines): list the
	// distinct units seen for this product so the mixed-scale total is readable.
	$units = pharmacyReportUnits($db, $r->fk_product);
	print '<td class="nowrap">'.dol_escape_htmltag(implode(' / ', $units)).'</td>';
	print '<td class="right">'.price((float) $r->total_amount).'</td>';
	print '<td class="center nowrap">'.($r->first_dispense ? dol_print_date($db->jdate($r->first_dispense), 'day') : '').'</td>';
	print '<td class="center nowrap">'.($r->last_dispense ? dol_print_date($db->jdate($r->last_dispense), 'day') : '').'</td>';
	print '</tr>';
}
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("PharmacyReportTotal").'</th>';
print '<th class="right">'.$totals['nb_dispense'].'</th>';
print '<th class="right">'.number_format($totals['total_qty'], 3).'</th>';
print '<th></th>';
print '<th class="right">'.price($totals['total_amount']).'</th>';
print '<th colspan="2"></th>';
print '</tr>';
print '</table></div>';

// ---- Detail table -------------------------------------------------------------
print '<div class="fichecenter margin-top">';
print '<span class="opacitymedium">'.$langs->trans("PharmacyReportDetail").' ('.$nbDetail.')</span>';
print '</div>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyRef").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyDispenseDate").'</th>';
print '<th class="liste_titre">'.$langs->trans("ThirdPartyName").'</th>';
print '<th class="liste_titre">'.$langs->trans("Product").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportColQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyReportColUnit").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyReportColBatchNote").'</th>';
print '</tr>';

if (empty($details)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyReportDetailEmpty").'</span></td></tr>';
}
foreach ($details as $r) {
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.((int) $r->rowid).'">'.dol_escape_htmltag($r->ref).'</a></td>';
	print '<td class="center nowrap">'.($r->date_dispense ? dol_print_date($db->jdate($r->date_dispense), 'dayhour') : '').'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->patient_name).'</td>';
	print '<td class="nowrap"><a href="'.dol_buildpath('/product/card.php', 1).'?id='.((int) $r->fk_product).'">'.dol_escape_htmltag((string) $r->llabel).'</a></td>';
	print '<td class="right">'.number_format((float) $r->qty, 3).'</td>';
	print '<td class="nowrap">'.dol_escape_htmltag((string) $r->qty_unit).'</td>';
	// Batch note is free text; an empty one means the sheet did not record it.
	print '<td>'.dol_escape_htmltag((string) $r->batch_note).'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();

/**
 * Distinct quantity units seen for a product in this report's scope.
 *
 * Herbal dispensing is weighted in grams while patent medicines count in
 * 盒/支/袋, so a product can mix scales in the same total.
 *
 * @param DoliDB $db      Database handler
 * @param int    $fkProduct Product rowid
 * @return string[]       Unit labels
 */
function pharmacyReportUnits($db, $fkProduct)
{
	global $conf;
	$sql = "SELECT DISTINCT dl.qty_unit FROM ".MAIN_DB_PREFIX."pharmacy_dispense_line AS dl";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."pharmacy_dispense AS d ON d.rowid = dl.fk_dispense";
	$sql .= " WHERE d.entity = ".((int) $conf->entity)." AND dl.fk_product = ".((int) $fkProduct)." AND dl.qty_unit IS NOT NULL AND dl.qty_unit <> ''";
	$sql .= " ORDER BY dl.qty_unit";
	$resql = $db->query($sql);
	if (!$resql) {
		return array();
	}
	$units = array();
	while ($o = $db->fetch_object($resql)) {
		$units[] = $o->qty_unit;
	}
	$db->free($resql);
	return $units;
}
