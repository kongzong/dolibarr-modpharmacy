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
 * \file    htdocs/custom/pharmacy/report_stock.php
 * \ingroup pharmacy
 * \brief   Stock and near-expiry report: one row per stock batch (product /
 *          batch number / batch quantity / stock on hand / eatby and sellby /
 *          days left / risk tier) with a reconciliation summary proving that
 *          the sum of the batch quantities equals the stock on hand.
 *          Filters: warehouse and a days-left horizon. Read only.
 *
 * Risk tiers (effective date = sellby when set, otherwise eatby):
 *   expired    < 0 days   red
 *   30 days      0..=30   amber
 *   180 days       31..180 grey
 *   safe           > 180   green
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
$wid = GETPOSTINT('wid');
if ($wid < 0) {
	$wid = 0;
}
$horizon = getDolGlobalInt('PHARMACY_EXPIRY_DAYS', 90);
if ($horizon <= 0) {
	$horizon = 90;
}
$action = GETPOST('action', 'aZ09');

// ---- Batch rows ----------------------------------------------------------------
// llx_product_batch holds the per-batch quantity, llx_product_lot the dates.
// They are tied together by (fk_product, batch) as requested.
$sql = "SELECT ps.rowid AS stock_rowid, ps.fk_product, ps.fk_entrepot, ps.reel";
$sql .= ", p.ref AS pref, p.label AS plabel";
$sql .= ", b.batch, b.qty AS batch_qty, b.eatby, b.sellby";
$sql .= ", l.eatby AS lot_eatby, l.sellby AS lot_sellby";
$sql .= ", e.ref AS eref, e.lieu AS elieu";
$sql .= " FROM ".MAIN_DB_PREFIX."product_stock AS ps";
$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_batch AS b ON b.fk_product_stock = ps.rowid";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product_lot AS l ON l.fk_product = ps.fk_product AND l.batch = b.batch";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product AS p ON p.rowid = ps.fk_product";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."entrepot AS e ON e.rowid = ps.fk_entrepot";
$sql .= " WHERE b.qty IS NOT NULL AND b.qty <> 0";
if ($wid > 0) {
	$sql .= " AND ps.fk_entrepot = ".((int) $wid);
}
$sql .= " ORDER BY p.ref, b.batch";

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}
$rows = array();
while ($o = $db->fetch_object($resql)) {
	$rows[] = $o;
}
$db->free($resql);

// ---- Days left / risk tier computed in PHP (timezone safe) ---------------------
// llx_product_batch holds DATETIME and llx_product_lot holds DATE, so every
// candidate is converted to a unix timestamp first: subtracting $now from the
// raw string would silently compare a string with an int.
$now = dol_now();
$riskLabel = array(
	'expired' => 'PharmacyReportRiskExpired',
	'critical' => 'PharmacyReportRiskCritical',
	'watch' => 'PharmacyReportRiskWatch',
	'safe' => 'PharmacyReportRiskSafe',
);
foreach ($rows as &$r) {
	$r->sellby_ts = dol_stringtotime($r->sellby);
	$r->eatby_ts = dol_stringtotime($r->eatby);
	$eff = 0;
	foreach (array($r->sellby_ts, $r->eatby_ts, dol_stringtotime($r->lot_sellby), dol_stringtotime($r->lot_eatby)) as $cand) {
		if (!empty($cand)) {
			$eff = $cand;
			break;
		}
	}
	$r->eff_date = $eff;
	$r->days_left = $eff > 0 ? (int) floor(($eff - $now) / 86400) : 999999;
	if ($r->days_left < 0) {
		$r->risk_code = 'expired';
	} elseif ($r->days_left <= 30) {
		$r->risk_code = 'critical';
	} elseif ($r->days_left <= 180) {
		$r->risk_code = 'watch';
	} else {
		$r->risk_code = 'safe';
	}
}
unset($r);

$stats = array('expired' => array(0, 0), 'critical' => array(0, 0), 'watch' => array(0, 0), 'safe' => array(0, 0));
$batchPerRow = array();
$reelPerRow = array();
foreach ($rows as $r) {
	$stats[$r->risk_code][0]++;
	$stats[$r->risk_code][1] += (float) $r->batch_qty;
	$rid = (int) $r->stock_rowid;
	$batchPerRow[$rid] = (float) $batchPerRow[$rid] + (float) $r->batch_qty;
	// A stock row repeats once per batch it holds, so the on-hand is only
	// taken from the first batch of the row - otherwise it would be counted
	// twice and the reconciliation would always look broken.
	if (!isset($reelPerRow[$rid])) {
		$reelPerRow[$rid] = (float) $r->reel;
	}
}
$sumBatch = 0;
foreach ($stats as $tier) {
	$sumBatch += $tier[1];
}
$sumReel = array_sum($reelPerRow);
// A stock row reconciles when its batch quantities add up to its on-hand.
$mismatch = 0;
$deltaPerRow = array();
foreach ($batchPerRow as $rid => $bq) {
	$delta = $bq - (float) $reelPerRow[$rid];
	$deltaPerRow[$rid] = $delta;
	if (abs($delta) > 0.000001) {
		$mismatch++;
	}
}

// ---- CSV (must run before any output) ------------------------------------------
if ($action === 'export') {
	$csvName = 'pharmacy_report_stock'.dol_now('%Y%m%d%H%M%S');
	// Module temp dir: always inside the open_basedir whitelist (d:/dolibarr/...).
	$csvDir = empty($conf->pharmacy->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->pharmacy->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		// UTF-8 BOM so Excel reads the Chinese header/values correctly.
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans('Product'),
			$langs->trans('PharmacyReportStockColBatch'),
			$langs->trans('PharmacyReportStockColBatchQty'),
			$langs->trans('PharmacyReportStockColStock'),
			$langs->trans('PharmacyReportStockColDelta'),
			$langs->trans('PharmacyReportStockColEatBy'),
			$langs->trans('PharmacyReportStockColSellBy'),
			$langs->trans('PharmacyReportStockColDaysLeft'),
			$langs->trans('PharmacyReportStockColRisk'),
		));
		foreach ($rows as $r) {
			fputcsv($fh, array(
				(string) $r->plabel,
				(string) $r->batch,
				number_format((float) $r->batch_qty, 3, '.', ''),
				number_format((float) $r->reel, 3, '.', ''),
				number_format($deltaPerRow[(int) $r->stock_rowid], 3, '.', ''),
				dol_print_date($r->eff_date, 'day'),
				(string) $r->days_left,
				$langs->trans($riskLabel[$r->risk_code]),
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
	setEventMessages($langs->trans('PharmacyReportExportFailed'), null, 'errors');
}

llxHeader('', $langs->trans("PharmacyReportStock"));

print load_fiche_titre(
	$langs->trans("PharmacyReportStock"),
	'<a class="butAction" href="'.dol_buildpath('/pharmacy/report.php', 1).'"><span class="fa fa-chart-line fa-fw valignmiddle"></span>'.$langs->trans("PharmacyReportBackToOutflow").'</a>',
	'fa-boxes'
);

// ---- Warehouse filter ----------------------------------------------------------
$warehouses = array();
$resql = $db->query("SELECT rowid, ref, lieu FROM ".MAIN_DB_PREFIX."entrepot"
	." WHERE entity IN (".$db->sanitize(getEntity('stock')).") AND statut = 1 ORDER BY lieu");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$warehouses[$o->rowid] = trim($o->lieu.' - '.$o->ref);
	}
	$db->free($resql);
}
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formstockfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
if (count($warehouses) > 1) {
	print '<table class="noborder centpercent"><tr>';
	print '<td class="opacitymedium nowrap">'.$langs->trans("PharmacyReportStockWarehouse").':</td><td>';
	print '<select name="wid" class="minwidth200" onchange="this.form.submit()">';
	print '<option value="0">'.$langs->trans("PharmacyReportStockAllWarehouses").'</option>';
	foreach ($warehouses as $id => $label) {
		print '<option value="'.(int) $id.'"'.($wid == $id ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
	}
	print '</select>';
	print '</td></tr></table>';
} else {
	print '<input type="hidden" name="wid" value="0">';
}

// ---- Risk summary --------------------------------------------------------------
print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status8">'.$langs->trans("PharmacyReportRiskExpired").' '.$stats['expired'][0].'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("PharmacyReportRiskCritical").' '.$stats['critical'][0].'</span> ';
print '<span class="badge badge-status0">'.$langs->trans("PharmacyReportRiskWatch").' '.$stats['watch'][0].'</span> ';
print '<span class="badge badge-status4">'.$langs->trans("PharmacyReportRiskSafe").' '.$stats['safe'][0].'</span> ';
print '<span class="badge badge-status6">'.$langs->trans("PharmacyReportStockTotal").' '.count($rows).'</span>';
print '</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("PharmacyReportStockRecon").'</td></tr>';
print '<tr class="oddeven"><td class="nowrap">'.$langs->trans("PharmacyReportStockColBatchQtySum").'</td><td class="right">'.number_format($sumBatch, 3).'</td></tr>';
print '<tr class="oddeven"><td class="nowrap">'.$langs->trans("PharmacyReportStockColStockSum").'</td><td class="right">'.number_format($sumReel, 3).'</td></tr>';
print '<tr class="oddeven"><td class="nowrap">'.$langs->trans("PharmacyReportStockColDeltaSum").'</td><td class="right">'.number_format($sumBatch - $sumReel, 3).'</td></tr>';
print '<tr class="oddeven"><td class="nowrap">'.$langs->trans("PharmacyReportStockColMismatch").'</td>';
print '<td class="right'.($mismatch > 0 ? ' error' : '').'">'.$mismatch.'</td></tr>';
print '<tr class="oddeven"><td colspan="2" class="'.($mismatch > 0 ? 'error' : 'opacitymedium').'">';
print $langs->trans("PharmacyReportStockReconHelp");
print '</td></tr>';
print '</table>';
print '</form>';

// ---- Batch table ---------------------------------------------------------------
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("Product").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyReportStockColBatch").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportStockColBatchQty").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportStockColStock").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyReportStockColDelta").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyReportStockColSellBy").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyReportStockColEatBy").'</th>';
print '<th class="liste_titre nowrap">'.$langs->trans("PharmacyReportStockColDaysLeft").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyReportStockColRisk").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans("PharmacyReportNoData").'</span></td></tr>';
}
foreach ($rows as $r) {
	// The delta is a property of the stock row (all its batches together), and
	// is repeated on every batch line of that row so the red highlight sits on
	// the rows that carry an unbalanced stock row.
	$delta = $deltaPerRow[(int) $r->stock_rowid];
	$recon = abs($delta) > 0.000001;
	$daysBadge = '<span class="badge badge-status'.($r->risk_code === 'expired' ? '8' : ($r->risk_code === 'critical' ? '1' : ($r->risk_code === 'watch' ? '0' : '4'))).'">';
	$daysBadge .= $r->days_left >= 999999 ? '—' : $langs->trans('PharmacyReportStockDaysLeft', (string) $r->days_left);
	$daysBadge .= '</span>';

	// Rows whose batch total does not match the on-hand quantity get the red
	// delta cell below; the row itself stays a plain oddeven.
	print '<tr class="oddeven">';
	print '<td class="nowrap"><a href="'.dol_buildpath('/product/card.php', 1).'?id='.((int) $r->fk_product).'">'.dol_escape_htmltag((string) $r->plabel).'</a></td>';
	print '<td class="nowrap">'.dol_escape_htmltag((string) $r->batch).'</td>';
	print '<td class="right">'.number_format((float) $r->batch_qty, 3).'</td>';
	print '<td class="right">'.number_format((float) $r->reel, 3).'</td>';
	// A stock row whose batches do not add up to the on-hand quantity is flagged red.
	print '<td class="right'.($recon ? ' error' : '').'">'.number_format($delta, 3).'</td>';
	print '<td class="center nowrap">'.($r->sellby_ts ? dol_print_date($r->sellby_ts, 'day') : (dol_stringtotime($r->lot_sellby) ? dol_print_date(dol_stringtotime($r->lot_sellby), 'day') : '')).'</td>';
	print '<td class="center nowrap">'.($r->eatby_ts ? dol_print_date($r->eatby_ts, 'day') : (dol_stringtotime($r->lot_eatby) ? dol_print_date(dol_stringtotime($r->lot_eatby), 'day') : '')).'</td>';
	print '<td class="nowrap">'.$daysBadge.'</td>';
	print '<td><span class="badge badge-status'.($r->risk_code === 'expired' ? '8' : ($r->risk_code === 'critical' ? '1' : ($r->risk_code === 'watch' ? '0' : '4'))).'">'.$langs->trans($riskLabel[$r->risk_code]).'</span></td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
