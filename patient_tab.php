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
 * \file    htdocs/custom/pharmacy/patient_tab.php
 * \ingroup pharmacy
 * \brief   Patient card tab: dispensing / return history of this patient.
 *          Reuses pharmacy_list_by_patient() (spec §3.6).
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

dol_include_once('/patient/class/patientprofile.class.php');
dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/dispense.class.php');

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "pharmacy@pharmacy", "medrecord@medrecord"));

$id = GETPOSTINT('id');
if ($id <= 0 || !$user->hasRight('patient', 'read') || !$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$patient = new PatientProfile($db);
if ($patient->fetch($id) <= 0) {
	accessforbidden($langs->trans("PatientNotYet"));
}

llxHeader('', $langs->trans("PharmacyDispenseList"));

$head = patient_prepare_head($patient);
print dol_get_fiche_head($head, 'dispensing', $langs->trans("PatientTab"), -1, 'user');

// Patient header in the card/allergies fiche style (no summary banner here;
// the summary mode with quick buttons is for sub-data detail pages, design §5.1)
$linkback = '<a href="'.dol_buildpath('/patient/list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
print '<div class="arearef heightref valignmiddle centpercent">';
print '<div class="inline-block floatleft refid refidpadding">'.img_picto('', 'user', 'class="pictofixedwidth"').'<strong>'.dol_escape_htmltag($patient->card_no).'</strong>';
print ($patient->thirdparty ? ' - '.dol_escape_htmltag($patient->thirdparty->name) : '').'</div>';
print '<div class="inline-block floatright">'.$linkback.'</div>';
print '<div class="clearboth"></div></div>';
print '<div class="underbanner clearboth"></div>';

$rows = pharmacy_list_by_patient($db, $patient->id, 50);
$statusLabels = array(
	PHARMACY_STATUS_PENDING => $langs->trans('PharmacyStatusPending'),
	PHARMACY_STATUS_DISPENSED => $langs->trans('PharmacyStatusDispensed'),
	PHARMACY_STATUS_RETURNED => $langs->trans('PharmacyStatusReturned'),
);

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">'."\n";
print '<tr class="liste_titre">';
print '<th>'.$langs->trans("Ref").'</th>';
print '<th>'.$langs->trans("Prescription").'</th>';
print '<th class="center">'.$langs->trans("Date").'</th>';
print '<th>'.$langs->trans("MedRecordBelonging").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans("NoRecordFound").'</span></td></tr>';
}
foreach ($rows as $r) {
	print '<tr class="oddeven"'.((int) $r->status === PHARMACY_STATUS_RETURNED ? ' style="opacity:.55"' : '').'>';
	print '<td><a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.(int) $r->rowid.'">'.dol_escape_htmltag($r->ref).'</a></td>';
	print '<td>'.($r->fk_prescription ? '<a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.((int) $r->fk_prescription).'">'.dol_escape_htmltag($r->presc_ref).'</a>' : '').'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($r->date_creation), 'dayhour').'</td>';
	$medHtml = '<span class="opacitymedium">—</span>';
	if (!empty($r->fk_medrecord)) {
		$medLabel = ($r->medrecord_ref !== null && $r->medrecord_ref !== '') ? $r->medrecord_ref : '#'.(int) $r->fk_medrecord;
		$medHtml = '<a href="'.dol_buildpath('/medrecord/card.php', 1).'?id='.((int) $r->fk_medrecord).'">'.dol_escape_htmltag($medLabel).'</a>';
	}
	print '<td>'.$medHtml.'</td>';
	print '<td class="center">'.dol_escape_htmltag(isset($statusLabels[(int) $r->status]) ? $statusLabels[(int) $r->status] : $r->status).'</td>';
	print '</tr>';
}
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
