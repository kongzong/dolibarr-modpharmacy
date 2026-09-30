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
 * \file    htdocs/custom/pharmacy/decant.php
 * \ingroup pharmacy
 * \brief   Herbal-drug decanting log (GSP art. 164 (9): clear the bin and
 *          record it before filling a different batch). Append-only list
 *          with an inline create form; no edit, no delete.
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

$langs->loadLangs(array("products", "pharmacy@pharmacy"));

if (!$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$token = GETPOST('token', 'alpha');

// ---- Filters ----
$searchProduct = trim(GETPOST('search_product', 'alpha'));
$searchOp = GETPOST('search_op', 'aZ09');
$searchDateFrom = trim(GETPOST('search_date_from', 'alpha'));
$searchDateTo = trim(GETPOST('search_date_to', 'alpha'));
if ($searchDateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDateFrom)) {
	$searchDateFrom = '';
}
if ($searchDateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDateTo)) {
	$searchDateTo = '';
}

// ------------------------------------------------- create submission (append-only)
$createErrors = array();
if ($action === 'create' && $token !== '') {
	if (!$user->hasRight('pharmacy', 'write')) {
		accessforbidden();
	}

	$fkProduct = GETPOSTINT('fk_product');
	$batch = trim(GETPOST('batch', 'alpha'));
	$binloc = trim(GETPOST('binloc', 'alpha'));
	$op = GETPOST('op', 'aZ09');
	$dateOp = trim(GETPOST('date_op', 'alpha'));
	$checker = trim(GETPOST('checker', 'alpha'));
	$note = trim(GETPOST('note', 'restricthtml'));

	if ($fkProduct <= 0) {
		$createErrors[] = 'PharmacyDecantErrProduct';
	}
	if ($batch === '') {
		$createErrors[] = 'PharmacyDecantErrBatch';
	}
	if ($binloc === '') {
		$createErrors[] = 'PharmacyDecantErrBin';
	}
	if (!in_array($op, array('CLEAR', 'FILL'), true)) {
		$createErrors[] = 'PharmacyDecantErrOp';
	}
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOp)) {
		$createErrors[] = 'PharmacyDecantErrDate';
	}
	if ($checker === '') {
		$createErrors[] = 'PharmacyDecantErrChecker';
	}

	if (empty($createErrors)) {
		// Product must exist and stay batch-enabled (the point of the log)
		$resql = $db->query("SELECT rowid FROM ".$db->prefix()."product WHERE rowid = ".((int) $fkProduct)." AND tobatch = 1");
		if (!$resql || $db->num_rows($resql) == 0) {
			$createErrors[] = 'PharmacyDecantErrProduct';
		}
		if ($resql) {
			$db->free($resql);
		}
	}

	if (empty($createErrors)) {
		$sql = "INSERT INTO ".$db->prefix()."pharmacy_decant";
		$sql .= " (entity, fk_product, batch, binloc, op, date_op, checker, note, fk_user_creat, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkProduct).", '".$db->escape($batch)."', '".$db->escape($binloc)."'";
		$sql .= ", '".$db->escape($op)."', '".$db->escape($dateOp)."', '".$db->escape($checker)."'";
		$sql .= ", ".($note !== '' ? "'".$db->escape($note)."'" : 'NULL').", ".((int) $user->id).", '".$db->idate(dol_now())."')";
		if ($db->query($sql)) {
			setEventMessages($langs->trans("PharmacyDecantSaved"), null, 'mesgs');
			header('Location: '.$_SERVER["PHP_SELF"].'?token='.newToken());
			exit;
		}
		setEventMessages($db->lasterror(), null, 'errors');
	} else {
		$msgs = array();
		foreach ($createErrors as $k) {
			$msgs[] = $langs->trans($k);
		}
		setEventMessages(implode('<br>', $msgs), null, 'errors');
	}
}

llxHeader('', $langs->trans("PharmacyDecant"));

print load_fiche_titre($langs->trans("PharmacyDecant"), '<span class="opacitymedium small">'.$langs->trans("PharmacyDecantAppendOnly").'</span>', 'fa-box');

print '<div class="opacitymedium small marginbottomshort">'.$langs->trans("PharmacyDecantHelp").'</div>';

// ------------------------------------------------- create form
if ($user->hasRight('pharmacy', 'write')) {
	$keep = !empty($createErrors);

	// Batch-enabled sellable products for the dropdown
	$products = array();
	$resql = $db->query("SELECT rowid, ref, label FROM ".$db->prefix()."product WHERE tosell = 1 AND tobatch = 1 AND entity IN (".$db->sanitize(getEntity('product')).") ORDER BY ref");
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$products[$o->rowid] = $o->ref.' - '.$o->label;
		}
		$db->free($resql);
	}

	// Known batches for the datalist (convenience only, free text allowed)
	$batchOptions = array();
	$resql = $db->query("SELECT DISTINCT l.batch FROM ".$db->prefix()."product_lot AS l WHERE l.batch <> '' ORDER BY l.batch LIMIT 500");
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$batchOptions[] = $o->batch;
		}
		$db->free($resql);
	}

	$fkProductSel = $keep ? GETPOSTINT('fk_product') : 0;
	$batchSel = $keep ? GETPOST('batch', 'alpha') : '';
	$binSel = $keep ? GETPOST('binloc', 'alpha') : '';
	$opSel = $keep ? GETPOST('op', 'aZ09') : 'FILL';
	$dateSel = $keep && GETPOST('date_op', 'alpha') !== '' ? GETPOST('date_op', 'alpha') : date('Y-m-d');
	$checkerSel = $keep ? GETPOST('checker', 'alpha') : '';
	$noteSel = $keep ? GETPOST('note', 'restricthtml') : '';

	print '<div class="fichecenter marginbottomshort">';
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formdecantcreate">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="create">';
	print '<table class="noborder centpercent">';
	print '<tr><td colspan="4" class="liste_titre">'.$langs->trans("PharmacyDecantNew").'</td></tr>';
	print '<tr>';
	print '<td>'.$langs->trans("PharmacyDecantOp").'</td><td>';
	print '<select name="op" class="minwidth100">';
	foreach (array('FILL' => 'PharmacyDecantOpFill', 'CLEAR' => 'PharmacyDecantOpClear') as $ov => $lk) {
		print '<option value="'.$ov.'"'.($opSel === $ov ? ' selected' : '').'>'.$langs->trans($lk).'</option>';
	}
	print '</select></td>';
	print '<td>'.$langs->trans("PharmacyDecantDate").'</td><td><input type="date" name="date_op" value="'.dol_escape_htmltag($dateSel).'"></td>';
	print '</tr><tr>';
	print '<td>'.$langs->trans("Product").'</td><td>';
	print '<select name="fk_product" class="minwidth300">';
	print '<option value="0">&nbsp;</option>';
	foreach ($products as $pid => $plabel) {
		print '<option value="'.(int) $pid.'"'.($fkProductSel == $pid ? ' selected' : '').'>'.dol_escape_htmltag($plabel).'</option>';
	}
	print '</select></td>';
	print '<td>'.$langs->trans("PharmacyDecantBatch").'</td><td>';
	print '<input name="batch" list="decantbatches" class="minwidth150" value="'.dol_escape_htmltag($batchSel).'">';
	print '<datalist id="decantbatches">';
	foreach ($batchOptions as $b) {
		print '<option value="'.dol_escape_htmltag($b).'">';
	}
	print '</datalist></td>';
	print '</tr><tr>';
	print '<td>'.$langs->trans("PharmacyDecantBin").'</td><td><input name="binloc" class="minwidth100" value="'.dol_escape_htmltag($binSel).'"></td>';
	print '<td>'.$langs->trans("PharmacyDecantChecker").'</td><td><input name="checker" class="minwidth100" value="'.dol_escape_htmltag($checkerSel).'"></td>';
	print '</tr><tr>';
	print '<td>'.$langs->trans("PharmacyDecantNote").'</td><td colspan="3"><input name="note" class="minwidth500 widthcentpercent" value="'.dol_escape_htmltag($noteSel).'"></td>';
	print '</tr><tr>';
	print '<td colspan="4" class="center"><button type="submit" class="button" name="save" value="1">'.$langs->trans("Save").'</button></td>';
	print '</tr>';
	print '</table>';
	print '</form>';
	print '</div>';
}


// ------------------------------------------------- list (append-only, latest first)
$sql = "SELECT d.rowid, d.date_op, d.op, d.batch, d.binloc, d.checker, d.note, d.fk_product, d.fk_user_creat, d.date_creation";
$sql .= ", p.ref AS pref, p.label AS plabel, u.lastname AS ulast, u.firstname AS ufirst";
$sql .= " FROM ".$db->prefix()."pharmacy_decant AS d";
$sql .= " LEFT JOIN ".$db->prefix()."product AS p ON p.rowid = d.fk_product";
$sql .= " LEFT JOIN ".$db->prefix()."user AS u ON u.rowid = d.fk_user_creat";
$sql .= " WHERE d.entity = ".((int) $conf->entity);
if ($searchProduct !== '') {
	$like = "'%".$db->escape($searchProduct)."%'";
	$sql .= " AND (p.ref LIKE ".$like." OR p.label LIKE ".$like.")";
}
if (in_array($searchOp, array('CLEAR', 'FILL'), true)) {
	$sql .= " AND d.op = '".$db->escape($searchOp)."'";
}
if ($searchDateFrom !== '') {
	$sql .= " AND d.date_op >= '".$db->escape($searchDateFrom)."'";
}
if ($searchDateTo !== '') {
	$sql .= " AND d.date_op <= '".$db->escape($searchDateTo)."'";
}
$sql .= " ORDER BY d.date_op DESC, d.rowid DESC LIMIT 200";

$resql = $db->query($sql);
$nbrow = $resql ? $db->num_rows($resql) : 0;

print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'" name="formdecantfilter">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print_liste_field_titre("PharmacyDecantDate", $_SERVER["PHP_SELF"], "d.date_op", "", "", '', '', '', 'nowrap');
print_liste_field_titre("PharmacyDecantOp", $_SERVER["PHP_SELF"], "d.op", "", "", '', '', '', 'center ');
print_liste_field_titre("Product", $_SERVER["PHP_SELF"], "p.ref", "", "", '', '', '', '');
print_liste_field_titre("PharmacyDecantBatch", $_SERVER["PHP_SELF"], "d.batch", "", "", '', '', '', '');
print_liste_field_titre("PharmacyDecantBin", $_SERVER["PHP_SELF"], "d.binloc", "", "", '', '', '', '');
print_liste_field_titre("PharmacyDecantChecker", $_SERVER["PHP_SELF"], "d.checker", "", "", '', '', '', '');
print_liste_field_titre("PharmacyDecantOperator", $_SERVER["PHP_SELF"], "", "", "", '', '', '', '');
print_liste_field_titre("PharmacyDecantNote", $_SERVER["PHP_SELF"], "", "", "", '', '', '', '');
print '</tr>';

// filter row
print '<tr class="liste_titre">';
print '<td class="liste_titre center nowrap">';
print '<input type="date" name="search_date_from" value="'.dol_escape_htmltag($searchDateFrom).'"> - ';
print '<input type="date" name="search_date_to" value="'.dol_escape_htmltag($searchDateTo).'">';
print '</td>';
print '<td class="liste_titre center">';
print '<select name="search_op" class="minwidth100"><option value="">'.$langs->trans("PharmacyDecantFilterAll").'</option>';
print '<option value="FILL"'.($searchOp === 'FILL' ? ' selected' : '').'>'.$langs->trans("PharmacyDecantOpFill").'</option>';
print '<option value="CLEAR"'.($searchOp === 'CLEAR' ? ' selected' : '').'>'.$langs->trans("PharmacyDecantOpClear").'</option>';
print '</select>';
print '</td>';
print '<td class="liste_titre"><input name="search_product" class="minwidth100" value="'.dol_escape_htmltag($searchProduct).'"></td>';
print '<td class="liste_titre"></td><td class="liste_titre"></td><td class="liste_titre"></td><td class="liste_titre"></td>';
print '<td class="liste_titre right"><button type="submit" class="button" name="submitfilter" value="1">'.$langs->trans("Refresh").'</button></td>';
print '</tr>';
print '</form>';

if ($resql) {
	$i = 0;
	while ($o = $db->fetch_object($resql)) {
		print '<tr class="oddeven">';
		print '<td class="center nowrap">'.dol_print_date($db->jdate($o->date_op), 'day').'</td>';
		print '<td class="center">';
		if ($o->op === 'CLEAR') {
			print '<span class="badge badge-status1">'.$langs->trans("PharmacyDecantOpClear").'</span>';
		} else {
			print '<span class="badge badge-status4">'.$langs->trans("PharmacyDecantOpFill").'</span>';
		}
		print '</td>';
		print '<td>';
		if ($o->pref !== null) {
			$product = new Product($db);
			if ($product->fetch((int) $o->fk_product) > 0) {
				print $product->getNomUrl(1);
			} else {
				print dol_escape_htmltag((string) $o->pref);
			}
		} else {
			print '<span class="opacitymedium">#'.(int) $o->fk_product.'</span>';
		}
		print '</td>';
		print '<td>'.dol_escape_htmltag((string) $o->batch).'</td>';
		print '<td>'.dol_escape_htmltag((string) $o->binloc).'</td>';
		print '<td>'.dol_escape_htmltag((string) $o->checker).'</td>';
		print '<td>'.dol_escape_htmltag(trim($o->ulast.' '.$o->ufirst)).'</td>';
		print '<td>'.dol_escape_htmltag((string) $o->note).'</td>';
		print '</tr>';
		$i++;
	}
	$db->free($resql);
}
if ($nbrow == 0) {
	print '<tr class="oddeven"><td colspan="8" class="opacitymedium center">'.$langs->trans("PharmacyDecantListEmpty").'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
