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
 * \file    htdocs/custom/pharmacy/stock_count.php
 * \ingroup pharmacy
 * \brief   Stock count sheets: list, and the entry point to create one.
 *
 * A sheet snapshots the book quantity of every batch in a warehouse, then the
 * counter types what is physically on the shelf. Posting the sheet writes the
 * difference to llx_product_batch / llx_product_stock plus a stock movement, so
 * the batch rule (sum of batch quantities == reel) keeps holding.
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/pharmacystockcount.class.php');

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
$canCount = $user->hasRight('pharmacy', 'stock_count');

// ---- Filters ------------------------------------------------------------------
$wh = GETPOSTINT('search_wh');
$status = GETPOST('search_status', 'aZ09');
$dateFrom = substr(GETPOST('search_from', 'alpha'), 0, 10);
$dateTo = substr(GETPOST('search_to', 'alpha'), 0, 10);
$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = $conf->liste_limit;
}
$page = GETPOSTINT('page');
if ($page < 1) {
	$page = 1;
}
$sortorder = GETPOST('sortorder', 'aZ09');
$sortorder = ($sortorder === 'ASC' ? 'ASC' : 'DESC');

$form = new Form($db);
$warehouses = pharmacy_warehouse_options($db);

$dao = new PharmacyStockCount($db);
$result = $dao->search(array(
	'wh' => $wh,
	'status' => $status,
	'from' => $dateFrom,
	'to' => $dateTo,
	'limit' => $limit,
	'offset' => ($page - 1) * $limit,
));
if (!is_array($result)) {
	dol_print_error($db);
	exit;
}
$rows = $result['rows'];
$total = $result['total'];

llxHeader('', $langs->trans("PharmacyStockCountList"));

print load_fiche_titre(
	$langs->trans("PharmacyStockCountList"),
	($canCount ? '<a class="butAction" href="'.dol_buildpath('/pharmacy/stock_count_card.php', 1).'?action=create&wh='.($wh > 0 ? $wh : 0).'"><span class="fa fa-plus fa-fw valignmiddle"></span>'.$langs->trans("PharmacyStockCountCreate").'</a>' : ''),
	'fa-clipboard-check'
);

// ---- Filter -------------------------------------------------------------------
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formstockcount">';
print '<table class="noborder centpercent">';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("PharmacyWarehouse").'</td><td class="nowrap">';
print $form->selectarray('search_wh', $warehouses, $wh > 0 ? (string) $wh : '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth200');
print '</td>';
print '<td class="nowrap">'.$langs->trans("Status").'</td><td class="nowrap">';
$statusOptions = array(
	'' => $langs->trans("PharmacyReportFilterAll"),
	(string) PharmacyStockCount::STATUS_DRAFT => $langs->trans("PharmacyStockCountStatusDraft"),
	(string) PharmacyStockCount::STATUS_POSTED => $langs->trans("PharmacyStockCountStatusPosted"),
);
print $form->selectarray('search_status', $statusOptions, $status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td></tr>';
print '<tr>';
print '<td class="nowrap">'.$langs->trans("PharmacyLedgerDate").'</td><td class="nowrap">';
print '<input name="search_from" class="minwidth120" value="'.dol_escape_htmltag($dateFrom).'"> - ';
print '<input name="search_to" class="minwidth120" value="'.dol_escape_htmltag($dateTo).'">';
print '</td>';
print '<td colspan="2" class="center">';
print '<button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button>';
print ' <button type="submit" class="button" name="button_removefilter" value="1">'.$langs->trans("ClearFilter").'</button>';
print '</td></tr>';
print '</table>';
print '</form>';

// ---- List ---------------------------------------------------------------------
$param = ($wh > 0 ? '&search_wh='.$wh : '').($status !== '' ? '&search_status='.$status : '');
$param .= ($dateFrom ? '&search_from='.urlencode($dateFrom) : '').($dateTo ? '&search_to='.urlencode($dateTo) : '');
$param .= '&limit='.$limit.'&sortorder='.$sortorder;

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyStockCountRef").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyLedgerDate").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyWarehouse").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountLines").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountBook").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountCounted").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountDiff").'</th>';
print '<th class="liste_titre center">'.$langs->trans("Status").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("PharmacyStockCountNone").'</span></td></tr>';
}
foreach ($rows as $r) {
	$diff = (float) $r->qty_diff;
	$diffColor = (abs($diff) > 0.0005) ? 'error' : 'opacitymedium';
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/pharmacy/stock_count_card.php', 1).'?id='.(int) $r->rowid.'">'.img_picto('', 'fa-clipboard-check', 'class="pictofixedwidth"').dol_escape_htmltag((string) $r->ref).'</a></td>';
	print '<td class="center nowrap">'.dol_escape_htmltag((string) $r->date_count).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->warehouse).'</td>';
	print '<td class="right">'.(int) $r->nb_line.((int) $r->nb_todo > 0 ? ' <span class="opacitymedium">('.$langs->trans("PharmacyStockCountTodo").' '.(int) $r->nb_todo.')</span>' : '').'</td>';
	print '<td class="right">'.number_format((float) $r->qty_book, 3, '.', '').'</td>';
	print '<td class="right">'.number_format((float) $r->qty_counted, 3, '.', '').'</td>';
	print '<td class="right '.$diffColor.'">'.number_format($diff, 3, '.', '').'</td>';
	if ((int) $r->status === PharmacyStockCount::STATUS_POSTED) {
		print '<td class="center"><span class="badge badge-status4">'.$langs->trans("PharmacyStockCountStatusPosted").'</span></td>';
	} else {
		print '<td class="center"><span class="badge badge-status2">'.$langs->trans("PharmacyStockCountStatusDraft").'</span></td>';
	}
	print '</tr>';
}
print '</table></div>';

print_barre_liste($langs->trans("PharmacyStockCountList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'fa-clipboard-check', 0, '', '', $limit, 0, 0, 1);

llxFooter();
$db->close();
