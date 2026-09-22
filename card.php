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
 * \file    htdocs/custom/pharmacy/card.php
 * \ingroup pharmacy
 * \brief   Dispense sheet card. Phase 1 skeleton: view of a pending /
 *          dispensed / returned sheet with its lines; the confirm / return
 *          actions and the FEFO picker land in phase 2 (spec §6).
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
dol_include_once('/prescription/class/prescriptionsheet.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "pharmacy@pharmacy", "prescription@prescription"));

if (!$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$id = GETPOSTINT('id');

llxHeader('', $langs->trans("PharmacyDispenseList"));

if ($id <= 0) {
	print '<div class="error">'.$langs->trans("NoRecordFound").'</div>';
	llxFooter();
	$db->close();
	exit;
}

$dao = new Dispense($db);
if ($dao->fetch($id) <= 0) {
	print '<div class="error">'.$langs->trans("NoRecordFound").'</div>';
	llxFooter();
	$db->close();
	exit;
}
patient_audit($db, $dao->fk_patient, 'PHARMACY_READ', $user, array('ref' => $dao->ref, 'dispense' => $dao->id, 'via' => 'ui'));

$summary = patient_get_summary($db, $dao->fk_patient);
$presc = new PrescriptionSheet($db);
$hasPresc = $presc->fetch($dao->fk_prescription) > 0;

// Patient context bar (navigation convention 2026-09-21: never invent a back
// button; highlight the tab we are on)
$trail = array();
if ($hasPresc && $presc->fk_medrecord) {
	$trail[] = array('label' => $langs->trans('MedRecordTab'), 'url' => dol_buildpath('/medrecord/card.php', 1).'?id='.(int) $presc->fk_medrecord);
}
$trail[] = array('label' => $langs->trans('PrescriptionTab'), 'url' => dol_buildpath('/prescription/card.php', 1).'?id='.(int) $dao->fk_prescription);
print patient_summary_banner($summary, $trail, 'pharmacy');

print load_fiche_titre($langs->trans("PharmacyRef").' '.dol_escape_htmltag($dao->ref).' '.pharmacy_status_badge($dao->status), '', 'fa-pills');

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">'.$langs->trans("PharmacyPrescription").'</td><td>';
if ($hasPresc) {
	print '<a href="'.dol_buildpath('/prescription/card.php', 1).'?id='.(int) $presc->id.'">'.dol_escape_htmltag($presc->ref).'</a> ';
	print prescription_status_badge($presc->status).' '.($presc->presc_type === PRESCRIPTION_TYPE_TCM ? $langs->trans('PrescriptionTypeTCM') : $langs->trans('PrescriptionTypeWM'));
}
print '</td></tr>';
print '<tr><td>'.$langs->trans("PharmacyWarehouse").'</td><td>'.dol_escape_htmltag((string) $dao->warehouse_label).'</td></tr>';
print '<tr><td>'.$langs->trans("PharmacyDateDispense").'</td><td>'.$dao->date_dispense ? dol_print_date($dao->date_dispense, 'dayhour') : ''.'</td></tr>';
if ((int) $dao->status === PHARMACY_STATUS_RETURNED) {
	print '<tr><td>'.$langs->trans("PharmacyReturnReason").'</td><td>'.dol_escape_htmltag((string) $dao->return_reason).'</td></tr>';
}
print '<tr><td>'.$langs->trans("PharmacyNote").'</td><td>'.dol_escape_htmltag((string) $dao->note).'</td></tr>';
print '<tr><td>'.$langs->trans("DateCreation").'</td><td>'.dol_print_date($dao->date_creation, 'dayhour').'</td></tr>';
print '</table>';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">#</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyLineDrug").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyLineQty").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyLineUnit").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyLineBatch").'</th>';
print '</tr>';
foreach ($dao->lines as $i => $l) {
	print '<tr class="oddeven">';
	print '<td>'.($i + 1).'</td>';
	print '<td>'.dol_escape_htmltag($l['label']).'</td>';
	print '<td class="right">'.price2num($l['qty'], 'MS').'</td>';
	print '<td>'.dol_escape_htmltag((string) $l['qty_unit']).'</td>';
	print '<td>'.($l['is_stock'] ? dol_escape_htmltag((string) $l['batch_note']) : '<span class="opacitymedium">'.$langs->trans("PharmacyLineNonStock").'</span>').'</td>';
	print '</tr>';
}
print '</table></div>';

// Phase 2: confirm (dispense permission), return (return permission), PDF
llxFooter();
$db->close();
