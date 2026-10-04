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
 * \file    htdocs/custom/pharmacy/recall.php
 * \ingroup pharmacy
 * \brief   Drug recall: register a batch recall, work through the patient list,
 *          export the notification list, close it.
 *
 * One page with three views (list / create / detail) because the workflow is
 * short: pick a batch, phone the patients, close it.
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
dol_include_once('/pharmacy/class/pharmacyrecall.class.php');

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
$canRecall = $user->hasRight('pharmacy', 'dispose') || $user->hasRight('pharmacy', 'admin');

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$dao = new PharmacyRecall($db);
$form = new Form($db);

$P = $db->prefix();

// Phone of the patient: kept out of the recall table, read live so a corrected
// number shows up on the next export.
function recallPatientPhone($db, $fkPatient)
{
	$P = $db->prefix();
	$sql = "SELECT s.phone FROM ".$P."patient_profile AS pp";
	$sql .= " LEFT JOIN ".$P."societe AS s ON s.rowid = pp.fk_soc WHERE pp.rowid = ".(int) $fkPatient;
	$resql = $db->query($sql);
	$phone = ($resql && ($o = $db->fetch_object($resql))) ? (string) $o->phone : '';
	if ($resql) {
		$db->free($resql);
	}
	return $phone;
}

// ---- Detail: notification list (CSV export) ---------------------------------
if ($id > 0 && $dao->fetch($id) <= 0) {
	setEventMessages($langs->trans("PharmacyRecallErrNotFound"), null, 'errors');
	header('Location: '.dol_buildpath('/pharmacy/recall.php', 1));
	exit;
}

if ($id > 0 && $action === 'export') {
	$csvName = 'pharmacy_recall_'.$dao->ref;
	$csvDir = empty($conf->pharmacy->dir_temp) ? DOL_DOCUMENT_ROOT.'/dolibarr_documents/temp/' : $conf->pharmacy->dir_temp;
	$csvFile = $csvDir.'/'.$csvName.'.csv';
	$fh = fopen($csvFile, 'w');
	if ($fh) {
		fwrite($fh, "\xEF\xBB\xBF");
		fputcsv($fh, array(
			$langs->trans("PharmacyRecallRef"),
			$langs->trans("PatientCardNo"),
			$langs->trans("ThirdPartyName"),
			$langs->trans("Phone"),
			$langs->trans("PharmacyRecallQty"),
			$langs->trans("PharmacyDateDispense"),
			$langs->trans("PharmacyRecallDispenseSheet"),
			$langs->trans("PharmacyRecallNotified"),
		));
		foreach ($dao->lines as $l) {
			fputcsv($fh, array(
				$dao->ref,
				(string) $l->card_no,
				(string) $l->patient_name,
				recallPatientPhone($db, (int) $l->fk_patient),
				number_format((float) $l->qty, 3, '.', ''),
				$l->date_dispense ? dol_print_date($db->jdate($l->date_dispense), 'day') : '',
				(string) $l->dispense_ref,
				$l->notified ? $langs->trans("Yes") : $langs->trans("No"),
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
	setEventMessages($langs->trans("PharmacyReportExportFailed"), null, 'errors');
}

if ($id > 0 && $action === 'notify' && $canRecall) {
	$checked = GETPOST('notified', 'array');
	$checked = is_array($checked) ? array_map('intval', $checked) : array();
	foreach ($dao->lines as $l) {
		$lineId = (int) $l->rowid;
		$flag = in_array($lineId, $checked, true);
		if ($flag !== (bool) $l->notified) {
			$dao->markNotified($lineId, $flag);
		}
	}
	$dao->fetchLines();
	$dao->refreshStatus();
	header('Location: '.dol_buildpath('/pharmacy/recall.php', 1).'?id='.$dao->id);
	exit;
}

if ($id > 0 && $action === 'close' && $canRecall) {
	$rc = $dao->close($user);
	if ($rc === -3) {
		setEventMessages($langs->trans("PharmacyRecallErrNotNotified"), null, 'errors');
	} elseif ($rc > 0) {
		setEventMessages($langs->trans("PharmacyRecallClosed"), null, 'mesgs');
		header('Location: '.dol_buildpath('/pharmacy/recall.php', 1).'?id='.$dao->id);
		exit;
	} else {
		setEventMessages($dao->error ? $langs->trans($dao->error) : $langs->trans("PharmacyRecallCloseFailed"), null, 'errors');
	}
	$dao->fetch($dao->id);
}

// ---- Create ------------------------------------------------------------------
if ($action === 'create' && $canRecall) {
	if (GETPOSTISSET('submitcreate')) {
		$newId = $dao->createFromBatch(
			$user,
			GETPOSTINT('product'),
			GETPOST('batch', 'alpha'),
			GETPOSTINT('level'),
			GETPOST('reason', 'restricthtml'),
			GETPOSTINT('wh')
		);
		if ($newId > 0) {
			setEventMessages($langs->trans("PharmacyRecallCreated", $dao->ref), null, 'mesgs');
			header('Location: '.dol_buildpath('/pharmacy/recall.php', 1).'?id='.$newId);
			exit;
		}
		setEventMessages($dao->error ? $langs->trans($dao->error) : $langs->trans("PharmacyRecallCreateFailed"), null, 'errors');
	}

	// Only batch-managed products can be recalled.
	$productOptions = array();
	$sql = "SELECT p.rowid, p.ref, p.label FROM ".$P."product AS p";
	$sql .= " WHERE p.entity IN (".getEntity('product').") AND p.tobatch = 1 ORDER BY p.ref";
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$productOptions[(string) $o->rowid] = trim($o->label.' ['.$o->ref.']');
		}
		$db->free($resql);
	}

	llxHeader('', $langs->trans("PharmacyRecallCreate"));
	print load_fiche_titre(
		$langs->trans("PharmacyRecallCreate"),
		'<a class="butAction" href="'.dol_buildpath('/pharmacy/recall.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("PharmacyRecallList").'</a>',
		'fa-bullhorn'
	);
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formrecallcreate">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="create">';
	print '<table class="noborder centpercent">';
	print '<tr><td class="nowrap">'.$langs->trans("PharmacyExpiryProduct").'</td><td class="nowrap">';
	print $form->selectarray('product', $productOptions, (string) GETPOSTINT('product'), 1, 0, 0, '', 0, 0, 0, '', 'maxwidth300');
	print '</td></tr>';
	print '<tr><td class="nowrap">'.$langs->trans("PharmacyExpiryBatch").'</td><td class="nowrap">';
	print '<input name="batch" class="minwidth200" value="'.dol_escape_htmltag(GETPOST('batch', 'alpha')).'">';
	print '</td></tr>';
	print '<tr><td class="nowrap">'.$langs->trans("PharmacyRecallLevel").'</td><td class="nowrap">';
	$levelOptions = array();
	foreach (array(1, 2, 3) as $lv) {
		$levelOptions[(string) $lv] = $langs->trans('PharmacyRecallLevel'.$lv);
	}
	print $form->selectarray('level', $levelOptions, (string) (GETPOSTINT('level') ? GETPOSTINT('level') : 1), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth250');
	print '</td></tr>';
	print '<tr><td class="nowrap">'.$langs->trans("PharmacyWarehouse").'</td><td class="nowrap">';
	print $form->selectarray('wh', pharmacy_warehouse_options($db), (string) GETPOSTINT('wh'), 1, 0, 0, '', 0, 0, 0, '', 'maxwidth250');
	print '</td></tr>';
	print '<tr><td class="nowrap">'.dol_escape_htmltag($langs->trans("Note")).'</td><td class="nowrap">';
	print '<input name="reason" class="minwidth400" value="'.dol_escape_htmltag(GETPOST('reason', 'restricthtml')).'">';
	print '</td></tr>';
	print '<tr><td colspan="2" class="center">';
	print '<button type="submit" class="button" name="submitcreate" value="1">'.$langs->trans("PharmacyRecallRegister").'</button>';
	print ' <a class="butAction" href="'.dol_buildpath('/pharmacy/recall.php', 1).'">'.$langs->trans("Cancel").'</a>';
	print '</td></tr>';
	print '</table>';
	print '</form>';
	print '<div class="opacitymedium clearboth">'.$langs->trans("PharmacyRecallCreateHint").'</div>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Detail ------------------------------------------------------------------
if ($id > 0) {
	llxHeader('', $langs->trans("PharmacyRecallCard"));
	print load_fiche_titre(
		$langs->trans("PharmacyRecallCard").' '.$dao->ref,
		'<a class="butAction" href="'.dol_buildpath('/pharmacy/recall.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("PharmacyRecallList").'</a>',
		'fa-bullhorn'
	);

	$todo = 0;
	foreach ($dao->lines as $l) {
		if (empty($l->notified)) {
			$todo++;
		}
	}
	print '<div class="fichecenter marginbottomonly">';
	print '<span class="badge badge-status4">'.trim($dao->product_label.' ['.$dao->product_ref.']').'</span> ';
	print '<span class="badge badge-status1">'.$langs->trans("PharmacyExpiryBatch").' '.dol_escape_htmltag($dao->batch).'</span> ';
	print '<span class="badge badge-status2">'.$langs->trans("PharmacyRecallLevel").' '.$langs->trans('PharmacyRecallLevel'.$dao->level).'</span> ';
	print '<span class="badge badge-status3">'.count($dao->lines).' '.$langs->trans("PharmacyRecallPatients").'</span> ';
	print '<span class="badge badge-status'.($todo > 0 ? '9' : '4').'">'.$langs->trans("PharmacyRecallTodo").' '.$todo.'</span> ';
	if ($dao->status === PharmacyRecall::STATUS_CLOSED) {
		print '<span class="badge badge-status8">'.$langs->trans("PharmacyRecallStatusClosed").'</span>';
	} elseif ($dao->status === PharmacyRecall::STATUS_NOTIFIED) {
		print '<span class="badge badge-status4">'.$langs->trans("PharmacyRecallStatusNotified").'</span>';
	} else {
		print '<span class="badge badge-status2">'.$langs->trans("PharmacyRecallStatusRegistered").'</span>';
	}
	print '</div>';

	if ($dao->reason !== '') {
		print '<div class="opacitymedium margintop">'.$langs->trans("PharmacyRecallReason").': '.dol_escape_htmltag($dao->reason).'</div>';
	}

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formrecallnotify">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="id" value="'.$dao->id.'">';
	print '<div class="div-table-responsive-no-min margintop"><table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th style="width:28px;">&nbsp;</th>';
	print '<th class="liste_titre">'.$langs->trans("PatientCardNo").'</th>';
	print '<th class="liste_titre">'.$langs->trans("ThirdPartyName").'</th>';
	print '<th class="liste_titre">'.$langs->trans("Phone").'</th>';
	print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyDateDispense").'</th>';
	print '<th class="liste_titre">'.$langs->trans("PharmacyRecallDispenseSheet").'</th>';
	print '<th class="liste_titre right">'.$langs->trans("PharmacyRecallQty").'</th>';
	print '</tr>';

	if (empty($dao->lines)) {
		print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyRecallNoPatient").'</span></td></tr>';
	}
	$editable = ($canRecall && $dao->status !== PharmacyRecall::STATUS_CLOSED);
	foreach ($dao->lines as $l) {
		print '<tr class="oddeven">';
		if ($editable) {
			print '<td class="center"><input type="checkbox" name="notified[]" value="'.(int) $l->rowid.'"'.(!empty($l->notified) ? ' checked' : '').'></td>';
		} else {
			print '<td class="center">'.(!empty($l->notified) ? '✓' : '').'</td>';
		}
		print '<td>'.((int) $l->fk_patient > 0
			? '<a href="'.dol_buildpath('/patient/card.php', 1).'?id='.(int) $l->fk_patient.'">'.dol_escape_htmltag((string) $l->card_no).'</a>'
			: dol_escape_htmltag((string) $l->card_no)).'</td>';
		print '<td>'.dol_escape_htmltag((string) $l->patient_name).'</td>';
		print '<td>'.dol_escape_htmltag(recallPatientPhone($db, (int) $l->fk_patient)).'</td>';
		print '<td class="center nowrap">'.($l->date_dispense ? dol_print_date($db->jdate($l->date_dispense), 'day') : '').'</td>';
		print '<td>'.((int) $l->fk_dispense > 0
			? '<a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.(int) $l->fk_dispense.'">'.dol_escape_htmltag((string) $l->dispense_ref).'</a>'
			: dol_escape_htmltag((string) $l->dispense_ref)).'</td>';
		print '<td class="right">'.number_format((float) $l->qty, 3, '.', '').'</td>';
		print '</tr>';
	}
	print '</table></div>';

	print '<div class="center margintop">';
	print '<a class="butAction" href="'.dol_buildpath('/pharmacy/recall.php', 1).'?id='.$dao->id.'&action=export">';
	print '<span class="fa fa-file-excel-o fa-fw valignmiddle"></span>'.$langs->trans("PharmacyRecallExportList").'</a> ';
	if ($editable) {
		print '<button type="submit" class="button" name="action" value="notify">'.$langs->trans("PharmacyRecallSaveNotify").'</button> ';
	}
	if ($canRecall && $dao->status === PharmacyRecall::STATUS_NOTIFIED) {
		print '<a class="butAction" href="'.dol_buildpath('/pharmacy/recall.php', 1).'?id='.$dao->id.'&action=close"><span class="fa fa-check fa-fw valignmiddle"></span>'.$langs->trans("PharmacyRecallClose").'</a>';
	}
	print '</div>';
	print '</form>';

	llxFooter();
	$db->close();
	exit;
}

// ---- List --------------------------------------------------------------------
$status = GETPOST('search_status', 'aZ09');
$level = GETPOSTINT('search_level');
$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = $conf->liste_limit;
}
$page = GETPOSTINT('page');
if ($page < 1) {
	$page = 1;
}

$result = $dao->search(array('status' => $status, 'level' => $level, 'limit' => $limit, 'offset' => ($page - 1) * $limit));
if (!is_array($result)) {
	dol_print_error($db);
	exit;
}
$rows = $result['rows'];
$total = $result['total'];

llxHeader('', $langs->trans("PharmacyRecallList"));

print load_fiche_titre(
	$langs->trans("PharmacyRecallList"),
	($canRecall ? '<a class="butAction" href="'.dol_buildpath('/pharmacy/recall.php', 1).'?action=create"><span class="fa fa-plus fa-fw valignmiddle"></span>'.$langs->trans("PharmacyRecallCreate").'</a>' : ''),
	'fa-bullhorn'
);

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formrecallfilter">';
print '<table class="noborder centpercent"><tr>';
print '<td class="nowrap">'.$langs->trans("Status").'</td><td class="nowrap">';
$statusOptions = array(
	'' => $langs->trans("PharmacyReportFilterAll"),
	(string) PharmacyRecall::STATUS_REGISTERED => $langs->trans("PharmacyRecallStatusRegistered"),
	(string) PharmacyRecall::STATUS_NOTIFIED => $langs->trans("PharmacyRecallStatusNotified"),
	(string) PharmacyRecall::STATUS_CLOSED => $langs->trans("PharmacyRecallStatusClosed"),
);
print $form->selectarray('search_status', $statusOptions, $status, 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td>';
print '<td class="nowrap">'.$langs->trans("PharmacyRecallLevel").'</td><td class="nowrap">';
$levelOptions = array('' => $langs->trans("PharmacyReportFilterAll"));
foreach (array(1, 2, 3) as $lv) {
	$levelOptions[(string) $lv] = $langs->trans('PharmacyRecallLevel'.$lv);
}
print $form->selectarray('search_level', $levelOptions, (string) ($level > 0 ? $level : ''), 0, 0, 0, '', 0, 0, 0, '', 'maxwidth150');
print '</td>';
print '<td class="center"><button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button></td>';
print '</tr></table></form>';

$param = ($status !== '' ? '&search_status='.$status : '').($level > 0 ? '&search_level='.$level : '').'&limit='.$limit;

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyRecallRef").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryProduct").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryBatch").'</th>';
print '<th class="liste_titre center">'.$langs->trans("PharmacyRecallLevel").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyRecallPatients").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyRecallTodo").'</th>';
print '<th class="liste_titre center">'.$langs->trans("Status").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="7"><span class="opacitymedium">'.$langs->trans("PharmacyRecallNone").'</span></td></tr>';
}
foreach ($rows as $r) {
	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/pharmacy/recall.php', 1).'?id='.(int) $r->rowid.'">'.img_picto('', 'fa-bullhorn', 'class="pictofixedwidth"').dol_escape_htmltag((string) $r->ref).'</a></td>';
	print '<td>'.dol_escape_htmltag(trim((string) $r->product_label.' ['.$r->product_ref.']')).'</td>';
	print '<td>'.dol_escape_htmltag((string) $r->batch).'</td>';
	print '<td class="center">'.(int) $r->level.'</td>';
	print '<td class="right">'.(int) $r->nb_patient.'</td>';
	print '<td class="right">'.((int) $r->nb_todo > 0 ? '<span class="error">'.(int) $r->nb_todo.'</span>' : '0').'</td>';
	if ((int) $r->status === PharmacyRecall::STATUS_CLOSED) {
		print '<td class="center"><span class="badge badge-status8">'.$langs->trans("PharmacyRecallStatusClosed").'</span></td>';
	} elseif ((int) $r->status === PharmacyRecall::STATUS_NOTIFIED) {
		print '<td class="center"><span class="badge badge-status4">'.$langs->trans("PharmacyRecallStatusNotified").'</span></td>';
	} else {
		print '<td class="center"><span class="badge badge-status2">'.$langs->trans("PharmacyRecallStatusRegistered").'</span></td>';
	}
	print '</tr>';
}
print '</table></div>';

print load_barre_liste($total, $page, $limit, $total, $param, '', '', '', '');

llxFooter();
$db->close();
