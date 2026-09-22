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
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fkPrescriptionParam = GETPOSTINT('fk_prescription');
$warehouseParam = GETPOSTINT('fk_warehouse');

$form = new Form($db);
$dao = new Dispense($db);

// ------------------------------------------------------------ create (from a signed prescription)
if ($action == 'create') {
	if (!$user->hasRight('pharmacy', 'write')) {
		accessforbidden();
	}
	if ($fkPrescriptionParam <= 0) {
		accessforbidden($langs->trans("PharmacyErrNotIssued"));
	}
	$presc = new PrescriptionSheet($db);
	if ($presc->fetch($fkPrescriptionParam) <= 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
	$warehouses = pharmacy_warehouse_options($db);
	$defaultWarehouse = (int) getDolGlobalString('PHARMACY_WAREHOUSE_ID');

	llxHeader('', $langs->trans("PharmacyDispenseList"));
	$trail = array();
	if ($presc->fk_medrecord) {
		$trail[] = array('label' => $langs->trans('MedRecordTab'), 'url' => dol_buildpath('/medrecord/patient_tab.php', 1).'?id='.((int) $presc->fk_patient));
	}
	$trail[] = array('label' => $presc->ref, 'url' => dol_buildpath('/prescription/card.php', 1).'?id='.((int) $presc->id));
	$trail[] = array('label' => $langs->trans('PharmacyCreateFromPrescription'));
	print patient_summary_banner(patient_get_summary($db, $presc->fk_patient), $trail, 'pharmacy');

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print '<input type="hidden" name="fk_prescription" value="'.((int) $presc->id).'">';
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate">'.$langs->trans("PharmacyPrescription").'</td><td>'.dol_escape_htmltag($presc->ref).' '.prescription_status_badge($presc->status).'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans("PharmacyWarehouse").'</td><td>';
	print '<select class="flat" name="fk_warehouse">';
	foreach ($warehouses as $wid => $wlabel) {
		$sel = ($warehouseParam == $wid) || (!$warehouseParam && $defaultWarehouse == $wid) ? ' selected' : '';
		print '<option value="'.$wid.'"'.$sel.'>'.dol_escape_htmltag($wlabel).'</option>';
	}
	print '</select></td></tr>';
	print '<tr><td>'.$langs->trans("PharmacyNote").'</td><td><textarea class="flat" name="note" rows="2" cols="60"></textarea></td></tr>';
	print '</table>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>#</th><th>'.$langs->trans("PharmacyLineDrug").'</th><th class="right">'.$langs->trans("PharmacyLineQty").'</th><th>'.$langs->trans("PharmacyLineUnit").'</th></tr>';
	foreach ($presc->lines as $i => $l) {
		print '<tr class="oddeven">';
		print '<td>'.($i + 1).'</td>';
		print '<td>'.dol_escape_htmltag($l['label']).'</td>';
		print '<td class="right">'.($l['qty'] !== null ? price2num($l['qty'], 'MS') : '').'</td>';
		print '<td>'.dol_escape_htmltag((string) $l['qty_unit']).'</td>';
		print '</tr>';
	}
	print '</table></div>';
	print $form->buttonsSaveCancel("PharmacyCreateFromPrescription", "Cancel");
	print '</form>';
	llxFooter();
	$db->close();
	exit;
}

if ($action == 'add' && $confirm != 'yes') {
	// formconfirm wrapper for create
	if (!$user->hasRight('pharmacy', 'write')) {
		accessforbidden();
	}
}

// ------------------------------------------------------------ add (create submission)
if ($action == 'add' && GETPOST('token', 'alpha') != '' && GETPOSTINT('fk_prescription') > 0 && GETPOST('save', 'alpha') !== '' || ($action == 'add' && $confirm == 'yes')) {
	if (!$user->hasRight('pharmacy', 'write')) {
		accessforbidden();
	}
	if (GETPOSTINT('fk_warehouse') <= 0) {
		setEventMessages($langs->trans("PharmacyErrWarehouseRequired"), null, 'errors');
		header('Location: '.$_SERVER["PHP_SELF"].'?action=create&fk_prescription='.((int) GETPOSTINT('fk_prescription')).'&token='.newToken());
		exit;
	}
	$presc = new PrescriptionSheet($db);
	if ($presc->fetch(GETPOSTINT('fk_prescription')) <= 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
	$result = $dao->createFromPrescription($user, $presc, GETPOSTINT('fk_warehouse'), (string) GETPOST('note', 'restricthtml'));
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
		header('Location: '.$_SERVER["PHP_SELF"].'?id='.$dao->id);
		exit;
	}
	setEventMessages($langs->trans($dao->error) !== $dao->error ? $langs->trans($dao->error) : $dao->error, null, 'errors');
	header('Location: '.$_SERVER["PHP_SELF"].'?action=create&fk_prescription='.((int) $presc->id).'&token='.newToken());
	exit;
}

// ------------------------------------------------------------ confirm dispense
if ($action == 'confirm_dispense' && $id > 0) {
	if (!$user->hasRight('pharmacy', 'dispense')) {
		accessforbidden();
	}
	if ($dao->fetch($id) <= 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
	$result = $dao->confirm($user);
	if ($result > 0) {
		setEventMessages($langs->trans("PharmacyAuditDispense").' '.$dao->ref, null, 'mesgs');
	} else {
		$msg = $dao->error;
		$translated = $langs->trans($msg);
		setEventMessages($translated !== $msg ? $translated : $msg, null, 'errors');
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
	exit;
}

// ------------------------------------------------------------ return
if ($action == 'confirm_return' && $id > 0 && $confirm == 'yes') {
	if (!$user->hasRight('pharmacy', 'return')) {
		accessforbidden();
	}
	$reason = (string) GETPOST('reason', 'restricthtml');
	if ($dao->fetch($id) <= 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
	$result = $dao->returnSheet($user, $reason);
	if ($result > 0) {
		setEventMessages($langs->trans("PharmacyAuditReturn").' '.$dao->ref, null, 'mesgs');
	} else {
		$msg = $dao->error;
		$translated = $langs->trans($msg);
		setEventMessages($translated !== $msg ? $translated : $msg, null, 'errors');
	}
	header('Location: '.$_SERVER["PHP_SELF"].'?id='.$id);
	exit;
}

if ($id <= 0) {
	print '<div class="error">'.$langs->trans("NoRecordFound").'</div>';
	llxFooter();
	$db->close();
	exit;
}

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

// Actions (spec §3.4): confirm = dispense permission, return = return permission
print '<div class="tabsAction">';
if ((int) $dao->status === PHARMACY_STATUS_PENDING && $user->hasRight('pharmacy', 'dispense')) {
	print dolGetButtonAction($langs->trans("PharmacyConfirm"), '', 'default', $_SERVER["PHP_SELF"].'?id='.$dao->id.'&action=confirm_dispense&token='.newToken(), '', 1);
}
if ((int) $dao->status === PHARMACY_STATUS_DISPENSED && $user->hasRight('pharmacy', 'return')) {
	print dolGetButtonAction($langs->trans("PharmacyReturn"), '', 'delete', $_SERVER["PHP_SELF"].'?id='.$dao->id.'&action=return&token='.newToken(), '', 1);
}
print '</div>';

if ($action == 'return' && (int) $dao->status === PHARMACY_STATUS_DISPENSED && $user->hasRight('pharmacy', 'return')) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$dao->id, $langs->trans("PharmacyReturn"), $langs->trans("PharmacyConfirmReturn"), 'confirm_return', array(array('type' => 'text', 'name' => 'reason', 'label' => $langs->trans("PharmacyReturnReason"), 'value' => '', 'size' => 60)), 0, 1);
}

llxFooter();
$db->close();
