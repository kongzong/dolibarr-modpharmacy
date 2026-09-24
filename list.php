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
$searchFkPatient = GETPOSTINT('search_fk_patient');
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search = '';
	$status = -1;
	$dateFrom = '';
	$dateTo = '';
	$searchFkPatient = 0;
}

$limit = GETPOSTINT('limit') > 0 ? GETPOSTINT('limit') : $conf->liste_limit;
$page = (int) GETPOST('page', 'int');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;

$dao = new Dispense($db);
$result = $dao->search(array('q' => $search, 'status' => $status, 'from' => $dateFrom, 'to' => $dateTo, 'fk_patient' => $searchFkPatient), $limit, $offset);
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
if ($searchFkPatient > 0) {
	$param .= '&search_fk_patient='.(int) $searchFkPatient;
}

print '<form method="GET" id="searchFormList" action="'.$_SERVER["PHP_SELF"].'">'."\n";
print '<input type="hidden" name="limit" value="'.(int) $limit.'">';
if ($searchFkPatient > 0) {
	print '<input type="hidden" name="search_fk_patient" value="'.(int) $searchFkPatient.'">';
}

if ($searchFkPatient > 0) {
	$ps = patient_get_summary($db, $searchFkPatient);
	$psName = $ps ? $ps['name'] : '';
	print '<div style="margin-bottom:6px;">';
	print '<span class="opacitymedium">'.$langs->trans('PharmacyFilterByPatient').'</span> ';
	print '<a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $searchFkPatient.'">'.dol_escape_htmltag($psName).'</a> ';
	print '<a href="'.$_SERVER["PHP_SELF"].'?search_fk_patient=0" class="butActionDeleteSmall">'.$langs->trans('ClearFilter').'</a>';
	print '</div>';
}

print_barre_liste($langs->trans("PharmacyDispenseList"), $page, $_SERVER["PHP_SELF"], $param, '', '', '', $total, $total, 'fa-pills', 0, '', '', $limit, 0, 0, 1);

$statusOptions = array();
foreach (array(PHARMACY_STATUS_PENDING, PHARMACY_STATUS_DISPENSED, PHARMACY_STATUS_RETURNED) as $st) {
	$statusOptions[(string) $st] = pharmacy_status_label($st);
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">'."\n";
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="4"><input type="text" name="search" class="minwidth200" placeholder="'.dol_escape_htmltag($langs->trans('PharmacyRef').' / '.$langs->trans('PrescriptionRef').' / '.$langs->trans('PatientCardNo')).'" value="'.dol_escape_htmltag($search).'"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre center">'.$form->selectDate($dateFrom, 'search_from', 0, 0, 1, '', 1, 0).' - '.$form->selectDate($dateTo, 'search_to', 0, 0, 1, '', 1, 0).'</td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statusOptions, $status >= 0 ? (string) $status : '', 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100').'</td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td></tr>';

print '<tr class="liste_titre">';
print '<th>'.$langs->trans("PharmacyRef").'</th>';
print '<th>'.$langs->trans("PharmacyPrescription").'</th>';
print '<th>'.$langs->trans("PatientCardNo").'</th>';
print '<th>'.$langs->trans("ThirdPartyName").'</th>';
print '<th>'.$langs->trans("PharmacyWarehouse").'</th>';
print '<th class="center">'.$langs->trans("DateCreation").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th></th>';
print '</tr>'."\n";

if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}

foreach ($rows as $r) {
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.(int) $r->rowid.'">'.img_picto('', 'fa-pills', 'class="pictofixedwidth"').dol_escape_htmltag($r->ref).'</a></td>';
	print '<td><a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.(int) $r->fk_prescription.'">'.dol_escape_htmltag($r->presc_ref).'</a></td>';
	print '<td>'.dol_escape_htmltag($r->card_no).'</td>';
	print '<td><a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $r->fk_patient.'">'.dol_escape_htmltag($r->patient_name).'</a></td>';
	print '<td>'.dol_escape_htmltag($r->warehouse_label !== null ? $r->warehouse_label : '').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($r->date_creation), 'dayhour').'</td>';
	print '<td class="center">'.pharmacy_status_badge($r->status).'</td>';
	print '<td></td>';
	print '</tr>';
}
print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
