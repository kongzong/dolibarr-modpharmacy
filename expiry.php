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
 * \file    htdocs/custom/pharmacy/expiry.php
 * \ingroup pharmacy
 * \brief   Expiry alert page: near-expiry and expired batches of open
 *          warehouses within PHARMACY_EXPIRY_DAYS (spec §3.5). Read only.
 *          Severity tiers: expired (red) / within 30 days (mid) / rest of
 *          window (grey); stat pills on top, warehouse filter, product and
 *          warehouse links.
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
dol_include_once('/pharmacy/class/pharmacyexpiryalert.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("products", "pharmacy@pharmacy"));

if (!$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$window = getDolGlobalInt('PHARMACY_EXPIRY_DAYS', 90);
if ($window <= 0) {
	$window = 90;
}
$wid = GETPOSTINT('wid');
if ($wid < 0) {
	$wid = 0;
}

llxHeader('', $langs->trans("PharmacyExpiry"));

print load_fiche_titre($langs->trans("PharmacyExpiry").' <span class="opacitymedium">('.(int) $window.' d)</span>', '', 'fa-hourglass-half');

// ---- Warehouse filter (open warehouses only) ----
$warehouses = array();
$resql = $db->query("SELECT rowid, ref, lieu FROM ".$db->prefix()."entrepot WHERE entity IN (".$db->sanitize(getEntity('stock')).") AND statut = 1 ORDER BY lieu");
if ($resql) {
	while ($o = $db->fetch_object($resql)) {
		$warehouses[$o->rowid] = trim($o->lieu.' - '.$o->ref);
	}
	$db->free($resql);
}
if (count($warehouses) > 1) {
	print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formexpiryfilter">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<span class="opacitymedium">'.$langs->trans("PharmacyExpiryWarehouse").':</span> ';
	print '<select name="wid" class="minwidth200" onchange="this.form.submit()">';
	print '<option value="0">'.$langs->trans("PharmacyExpiryAllWarehouses").'</option>';
	foreach ($warehouses as $id => $label) {
		print '<option value="'.(int) $id.'"'.($wid == $id ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
	}
	print '</select></form>';
}

$alert = new PharmacyExpiryAlert($db);
$rows = $alert->collect($window, $wid);

// ---- Stat pills: expired / within 30 d / rest of window / total ----
$now = dol_now();
$nExpired = 0;
$nCritical = 0;
$nWindow = 0;
foreach ($rows as $r) {
	$eff = $r['sellby'] > 0 ? $r['sellby'] : $r['eatby'];
	$days = $eff > 0 ? (int) floor(($eff - $now) / 86400) : 999;
	if ($days < 0) {
		$nExpired++;
	} elseif ($days <= 30) {
		$nCritical++;
	} else {
		$nWindow++;
	}
}
print '<div class="fichecenter marginbottomshort">';
print '<span class="badge badge-status8" style="margin:2px;">'.$langs->trans("PharmacyExpiryStatExpired").' '.$nExpired.'</span> ';
print '<span class="badge badge-status1" style="margin:2px;">'.$langs->trans("PharmacyExpiryStatCritical").' '.$nCritical.'</span> ';
print '<span class="badge badge-status0" style="margin:2px;">'.$langs->trans("PharmacyExpiryStatWindow").' '.$nWindow.'</span> ';
print '<span class="badge badge-status4" style="margin:2px;">'.$langs->trans("PharmacyExpiryStatTotal").' '.count($rows).'</span>';
print '</div>';

// ---- Table ----
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryProduct").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryBatch").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpirySellBy").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryEatBy").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryDaysLeftCol").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyExpiryQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryWarehouse").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyExpiryNone").'</span></td></tr>';
}

foreach ($rows as $r) {
	$eff = $r['sellby'] > 0 ? $r['sellby'] : $r['eatby'];
	$days = $eff > 0 ? (int) floor(($eff - $now) / 86400) : 999;
	if ($days < 0) {
		$daysBadge = '<span class="badge badge-status8">'.$langs->trans("PharmacyExpiryDaysOver", (string) abs($days)).'</span>';
	} elseif ($days <= 30) {
		$daysBadge = '<span class="badge badge-status1">'.$langs->trans("PharmacyExpiryDaysLeft", (string) $days).'</span>';
	} else {
		$daysBadge = '<span class="badge badge-status0">'.$langs->trans("PharmacyExpiryDaysLeft", (string) $days).'</span>';
	}

	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/product/card.php', 1).'?id='.((int) $r['fk_product']).'">'.dol_escape_htmltag($r['product']).'</a></td>';
	print '<td class="nowrap">'.dol_escape_htmltag($r['batch']).'</td>';
	print '<td class="nowrap">'.($r['sellby'] ? dol_print_date($r['sellby'], 'day') : '').'</td>';
	print '<td class="nowrap">'.($r['eatby'] ? dol_print_date($r['eatby'], 'day') : '').'</td>';
	print '<td class="nowrap">'.$daysBadge.'</td>';
	print '<td class="right">'.price2num($r['qty'], 'MS').'</td>';
	print '<td class="nowrap"><a href="'.dol_buildpath('/product/stock/card.php', 1).'?id='.((int) $r['fk_entrepot']).'">'.dol_escape_htmltag($r['warehouse']).'</a></td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
