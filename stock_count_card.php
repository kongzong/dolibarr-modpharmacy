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
 * \file    htdocs/custom/pharmacy/stock_count_card.php
 * \ingroup pharmacy
 * \brief   Stock count sheet: create it, type the counted quantities, post it.
 *
 * Posting is deliberately a separate, explicit step from saving the counts:
 * saving only records what was counted, posting is what moves the stock. The
 * poster must not be the counter unless the sheet says otherwise (GSP), and a
 * sheet with uncounted lines is refused so a half-count never reaches the
 * stock ledger.
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
if (!$user->hasRight('pharmacy', 'stock_count')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$id = GETPOSTINT('id');
$form = new Form($db);
$warehouses = pharmacy_warehouse_options($db);

$dao = new PharmacyStockCount($db);

// ---- Create -------------------------------------------------------------------
if ($action === 'create' && $id <= 0) {
	$wh = GETPOSTINT('warehouse');
	$countDate = substr(GETPOST('date_count', 'alpha'), 0, 10);
	if (empty($wh)) {
		$wh = (int) getDolGlobalInt('PHARMACY_WAREHOUSE_ID');
	}
	if (empty($wh)) {
		// Single-warehouse installations can skip the picker.
		$keys = array_keys($warehouses);
		$wh = (int) (empty($keys) ? 0 : $keys[0]);
	}

	if ($action === 'create' && GETPOSTISSET('submitcreate')) {
		$newId = $dao->createFromWarehouse($user, (int) $wh, $countDate, GETPOST('note', 'restricthtml'));
		if ($newId > 0) {
			header('Location: '.dol_buildpath('/pharmacy/stock_count_card.php', 1).'?id='.$newId);
			exit;
		}
		setEventMessages($langs->trans($dao->error), null, 'errors');
	}

	llxHeader('', $langs->trans("PharmacyStockCountCreate"));
	print load_fiche_titre($langs->trans("PharmacyStockCountCreate"), '', 'fa-clipboard-check');
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formstockcountcreate">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="create">';
	print '<table class="noborder centpercent">';
	print '<tr><td class="nowrap">'.$langs->trans("PharmacyWarehouse").'</td><td class="nowrap">';
	print $form->selectarray('warehouse', $warehouses, (string) $wh, -1, 0, 0, '', 0, 0, 0, '', 'maxwidth250');
	print '</td></tr>';
	print '<tr><td class="nowrap">'.$langs->trans("PharmacyLedgerDate").'</td><td class="nowrap">';
	print '<input name="date_count" class="minwidth150" value="'.dol_escape_htmltag($countDate ? $countDate : date('Y-m-d')).'">';
	print '</td></tr>';
	print '<tr><td class="nowrap">'.dol_escape_htmltag($langs->trans("Note")).'</td><td class="nowrap">';
	print '<input name="note" class="minwidth400" value="'.dol_escape_htmltag(GETPOST('note', 'restricthtml')).'">';
	print '</td></tr>';
	print '<tr><td colspan="2" class="center">';
	print '<button type="submit" class="button" name="submitcreate" value="1">'.$langs->trans("PharmacyStockCountGenerate").'</button>';
	print ' <a class="butAction" href="'.dol_buildpath('/pharmacy/stock_count.php', 1).'">'.$langs->trans("Cancel").'</a>';
	print '</td></tr>';
	print '</table>';
	print '</form>';
	print '<div class="opacitymedium clearboth">'.$langs->trans("PharmacyStockCountGenerateHint").'</div>';
	llxFooter();
	$db->close();
	exit;
}

// ---- Load ---------------------------------------------------------------------
if ($id > 0) {
	$rc = $dao->fetch($id);
	if ($rc <= 0) {
		setEventMessages($dao->error ? $langs->trans($dao->error) : $langs->trans("PharmacyStockCountErrNotFound"), null, 'errors');
		header('Location: '.dol_buildpath('/pharmacy/stock_count.php', 1));
		exit;
	}
} else {
	setEventMessages($langs->trans("PharmacyStockCountNoId"), null, 'errors');
	header('Location: '.dol_buildpath('/pharmacy/stock_count.php', 1));
	exit;
}

$posted = ($dao->status === PharmacyStockCount::STATUS_POSTED);

// ---- Actions ------------------------------------------------------------------
if ($action === 'save' && !$posted) {
	if (!GETPOST('token')) {
		setEventMessages($langs->trans("PharmacyStockCountErrToken"), null, 'errors');
	} else {
		$counted = GETPOST('counted', 'array');
		$errors = 0;
		if (is_array($counted)) {
			foreach ($dao->lines as $l) {
				$lineId = (int) $l->rowid;
				if (!array_key_exists($lineId, $counted)) {
					continue;
				}
				$raw = trim((string) $counted[$lineId]);
				$value = ($raw === '') ? null : (float) $raw;
				$rcSave = $dao->setCounted($lineId, $value);
				if ($rcSave < 0) {
					$errors++;
				}
			}
			$dao->fetchLines();
			$dao->refreshTotals();
		}
		if ($errors > 0) {
			setEventMessages($langs->trans("PharmacyStockCountSaveFailed", $errors), null, 'errors');
		} else {
			setEventMessages($langs->trans("PharmacyStockCountSaved"), null, 'mesgs');
		}
		header('Location: '.dol_buildpath('/pharmacy/stock_count_card.php', 1).'?id='.$dao->id);
		exit;
	}
}

if ($action === 'post' && !$posted) {
	// Counted values posted with the form must be stored first, otherwise the
	// operator would have to save, go back, and post.
	$counted = GETPOST('counted', 'array');
	if (is_array($counted)) {
		foreach ($dao->lines as $l) {
			$lineId = (int) $l->rowid;
			if (!array_key_exists($lineId, $counted)) {
				continue;
			}
			$raw = trim((string) $counted[$lineId]);
			$dao->setCounted($lineId, ($raw === '') ? null : (float) $raw);
		}
		$dao->fetchLines();
		$dao->refreshTotals();
	}
	$forceSame = GETPOST('force_same_user', 'alpha') ? true : false;
	$rcPost = $dao->post($user, $forceSame);
	if ($rcPost > 0) {
		setEventMessages($langs->trans("PharmacyStockCountPosted", $dao->qty_diff), null, 'mesgs');
		header('Location: '.dol_buildpath('/pharmacy/stock_count_card.php', 1).'?id='.$dao->id);
		exit;
	}
	if ($rcPost === -3) {
		setEventMessages($langs->trans("PharmacyStockCountErrNotAllCounted"), null, 'errors');
	} elseif ($rcPost === -4) {
		setEventMessages($langs->trans("PharmacyStockCountErrSameUser"), null, 'errors');
	} else {
		setEventMessages($dao->error ? $langs->trans($dao->error) : $langs->trans("PharmacyStockCountPostFailed"), null, 'errors');
	}
	$dao->fetch($dao->id);
	$posted = false;
}

llxHeader('', $langs->trans("PharmacyStockCountCard"));

print load_fiche_titre(
	$langs->trans("PharmacyStockCountCard").' '.$dao->ref,
	'<a class="butAction" href="'.dol_buildpath('/pharmacy/stock_count.php', 1).'"><span class="fa fa-list fa-fw valignmiddle"></span>'.$langs->trans("PharmacyStockCountList").'</a>',
	'fa-clipboard-check'
);

print '<div class="fichecenter marginbottomonly">';
print '<span class="badge badge-status2">'.$langs->trans("PharmacyLedgerDate").' '.dol_escape_htmltag($dao->date_count).'</span> ';
print '<span class="badge badge-status1">'.$langs->trans("PharmacyStockCountBook").' '.number_format($dao->qty_book, 3, '.', '').'</span> ';
print '<span class="badge badge-status4">'.$langs->trans("PharmacyStockCountCounted").' '.number_format($dao->qty_counted, 3, '.', '').'</span> ';
print '<span class="badge badge-status'.(abs($dao->qty_diff) > 0.0005 ? '9' : '8').'">';
print $langs->trans("PharmacyStockCountDiff").' '.number_format($dao->qty_diff, 3, '.', '').'</span> ';
if ($posted) {
	print '<span class="badge badge-status4">'.$langs->trans("PharmacyStockCountStatusPosted").'</span>';
} else {
	print '<span class="badge badge-status2">'.$langs->trans("PharmacyStockCountStatusDraft").'</span>';
}
print '</div>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formstockcountcard">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="id" value="'.$dao->id.'">';
// Default action for the sheet form. The save/post buttons still override it
// through their own name/value, but a submit carrying no submitter (JS
// form.submit(), Enter in some browsers) would otherwise reach no branch at
// all and silently re-render the sheet with the input lost.
print '<input type="hidden" name="action" value="save">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryProduct").'</th>';
print '<th class="liste_titre">'.$langs->trans("PharmacyExpiryBatch").'</th>';
print '<th class="liste_titre center nowrap">'.$langs->trans("PharmacyReportStockColEatBy").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountBook").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountCounted").'</th>';
print '<th class="liste_titre right">'.$langs->trans("PharmacyStockCountDiff").'</th>';
print '</tr>';

$todo = 0;
foreach ($dao->lines as $l) {
	$isTodo = ($l->qty_counted === null);
	if ($isTodo) {
		$todo++;
	}
	$label = trim((string) $l->product_label.' ['.$l->product_ref.']');
	$expired = (!empty($l->sellby) && $l->sellby < date('Y-m-d'));
	print '<tr class="oddeven'.($expired ? ' error' : '').'">';
	print '<td>'.dol_escape_htmltag($label).'</td>';
	print '<td>'.dol_escape_htmltag((string) $l->batch).'</td>';
	print '<td class="center nowrap">'.(!empty($l->sellby) ? dol_print_date($db->jdate($l->sellby), 'day') : '').'</td>';
	print '<td class="right">'.number_format((float) $l->qty_book, 3, '.', '').'</td>';
	print '<td class="right">'.($posted
		? '<span'.($isTodo ? ' class="error"' : '').'>'.($isTodo ? '-' : number_format((float) $l->qty_counted, 3, '.', '')).'</span>'
		: '<input type="text" class="right minwidth80" name="counted['.(int) $l->rowid.']" value="'.($isTodo ? '' : dol_escape_htmltag((string) $l->qty_counted)).'">').'</td>';
	print '<td class="right">'.($isTodo ? '' : number_format((float) $l->qty_diff, 3, '.', '')).'</td>';
	print '</tr>';
}
print '</table></div>';

if (!$posted) {
	print '<div class="center margintop"><button type="submit" class="button" name="action" value="save">'.$langs->trans("PharmacyStockCountSave").'</button> ';
	if ($todo == 0) {
		print '<button type="submit" class="button button-save" name="action" value="post">'.$langs->trans("PharmacyStockCountPost").'</button> ';
		if ((int) $dao->fk_user_creat === (int) $user->id) {
			// GSP wants a second person to post; offer the override explicitly.
			print '<label class="left"><input type="checkbox" name="force_same_user" value="1"> '.$langs->trans("PharmacyStockCountForceSameUser").'</label> ';
		}
	}
	print '</div>';
	if ($todo > 0) {
		print '<div class="opacitymedium center">'.$langs->trans("PharmacyStockCountTodo", $todo).'</div>';
	}
}
print '</form>';

if (!$posted && $todo == 0) {
	print '<div class="warning margintop">'.$langs->trans("PharmacyStockCountPostWarning").'</div>';
}

llxFooter();
$db->close();
