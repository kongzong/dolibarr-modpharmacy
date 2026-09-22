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
 * \file    htdocs/custom/pharmacy/list.php
 * \ingroup pharmacy
 * \brief   Dispense sheet list: ref / prescription ref / card no / name,
 *          warehouse, status, date range. Returned sheets hidden unless
 *          filtered.
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
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/dispense.class.php');

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

$form = new Form($db);

$search = trim(GETPOST('search', 'alphanohtml'));
$searchStatus = GETPOST('search_status', 'alpha');
$status = ($searchStatus !== '' && is_numeric($searchStatus)) ? (int) $searchStatus : -1;
$dateFrom = dol_mktime(0, 0, 0, GETPOSTINT('search_frommonth'), GETPOSTINT('search_fromday'), GETPOSTINT('search_fromyear'));
$dateTo = dol_mktime(23, 59, 59, GETPOSTINT('search_tomonth'), GETPOSTINT('search_today'), GETPOSTINT('search_toyear'));
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search = '';
	$status = -1;
	$dateFrom = '';
	$dateTo = '';
}

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$dao = new Dispense($db);
$result = $dao->search(array('q' => $search, 'status' => $status, 'from' => $dateFrom, 'to' => $dateTo), $limit, $offset);
if ($result === null) {
	dol_print_error($db, $dao->error);
	exit;
}
$total = $result['total'];
$rows = $result['rows'];

llxHeader('', $langs->trans("PharmacyDispenseList"));

$param = '&limit='.(int) $limit;
if ($search !== '') {
	$param .= '&search='.urlencode($search);
}
if ($searchStatus !== '') {
	$param .= '&search_status='.urlencode($searchStatus);
}
if ($dateFrom) {
	$param .= '&search_frommonth='.GETPOSTINT('search_frommonth').'&search_fromday='.GETPOSTINT('search_fromday').'&search_fromyear='.GETPOSTINT('search_fromyear');
}
if ($dateTo) {
	$param .= '&search_tomonth='.GETPOSTINT('search_tomonth').'&search_today='.GETPOSTINT('search_today').'&search_toyear='.GETPOSTINT('search_toyear');
}

print '<form method="GET" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="limit" value="'.(int) $limit.'">';

print_barre_liste($langs->trans("PharmacyDispenseList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'fa-pills', 0, '', '', $limit, 0, 0, 1);

$filters = '<div class="liste_titre_filter">';
$filters .= '<div class="liste_titre_left">';
$filters .= '<div class="marginrightonly"><input class="flat inputsearch" type="text" name="search" value="'.dol_escape_htmltag($search).'" placeholder="'.$langs->trans('PharmacyRef').' / '.$langs->trans('PrescriptionRef').' / '.$langs->trans('PatientCardNo').'"></div>';
$filters .= '<div class="marginrightonly"><select class="flat" name="search_status"><option value="-1">&nbsp;</option>';
foreach (array(PHARMACY_STATUS_PENDING, PHARMACY_STATUS_DISPENSED, PHARMACY_STATUS_RETURNED) as $st) {
	$filters .= '<option value="'.$st.'"'.($status === $st ? ' selected' : '').'>'.pharmacy_status_label($st).'</option>';
}
$filters .= '</select></div>';
$filters .= '<div class="marginrightonly">'.$form->selectDate($dateFrom, 'search_from_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans("From")).'</div>';
$filters .= '<div class="marginrightonly">'.$form->selectDate($dateTo, 'search_to_', 0, 0, 1, '', 1, 0, 0, '', '', '', '', 1, '', $langs->trans("To")).'</div>';
$filters .= '<button type="submit" class="liste_titre_search" name="button_search" value="1">'.$langs->trans("Search").'</button>';
$filters .= '<button type="submit" class="liste_titre_search" name="button_removefilter" value="1">'.$langs->trans("RemoveFilter").'</button>';
$filters .= '</div></div>';
print $filters;

print '<table class="tagtable liste">'."\n";
print '<tr class="liste_titre">';
print_liste_field_titre("PharmacyRef", $_SERVER["PHP_SELF"], "d.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("PharmacyPrescription", $_SERVER["PHP_SELF"], "p.ref", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("PatientCardNo", $_SERVER["PHP_SELF"], "pp.card_no", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("ThirdPartyName", $_SERVER["PHP_SELF"], "s.nom", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("PharmacyWarehouse", $_SERVER["PHP_SELF"], "d.fk_warehouse", "", $param, '', $sortfield, $sortorder);
print_liste_field_titre("DateCreation", $_SERVER["PHP_SELF"], "d.date_creation", "", $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre("Status", $_SERVER["PHP_SELF"], "d.status", "", $param, '', $sortfield, $sortorder, 'center ');
print '</tr>'."\n";

if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

foreach ($rows as $r) {
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.(int) $r->rowid.'">'.dol_escape_htmltag($r->ref).'</a></td>';
	print '<td><a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.(int) $r->fk_prescription.'">'.dol_escape_htmltag($r->presc_ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($r->card_no).'</td>';
	print '<td>'.dol_escape_htmltag($r->patient_name).'</td>';
	print '<td>'.dol_escape_htmltag($r->warehouse_label !== null ? $r->warehouse_label : '').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($r->date_creation), 'dayhour').'</td>';
	print '<td class="center">'.pharmacy_status_badge($r->status).'</td>';
	print '</tr>';
}
print '</table>';
print '</form>';

llxFooter();
$db->close();
