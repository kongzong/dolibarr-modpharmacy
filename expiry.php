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

llxHeader('', $langs->trans("PharmacyExpiry"));

print load_fiche_titre($langs->trans("PharmacyExpiry").' <span class="opacitymedium">('.(int) $window.' d)</span>', '', 'fa-hourglass-half');

$alert = new PharmacyExpiryAlert($db);
$rows = $alert->collect($window, 0);

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryProduct").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryBatch").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpirySellBy").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryEatBy").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyExpiryQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryWarehouse").'</th>';
print '<th class="liste_titre">'.$langs->trans("Status").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyExpiryNone").'</span></td></tr>';
}

foreach ($rows as $r) {
	$expired = ($r['sellby'] > 0 && $r['sellby'] < dol_now());
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($r['product']).'</td>';
	print '<td>'.dol_escape_htmltag($r['batch']).'</td>';
	print '<td>'.($r['sellby'] ? dol_print_date($r['sellby'], 'day') : '').'</td>';
	print '<td>'.($r['eatby'] ? dol_print_date($r['eatby'], 'day') : '').'</td>';
	print '<td class="right">'.price2num($r['qty'], 'MS').'</td>';
	print '<td>'.dol_escape_htmltag($r['warehouse']).'</td>';
	print '<td>'.($expired ? '<span class="badge badge-status8">'.$langs->trans("PharmacyExpiryExpired").'</span>' : '').'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
