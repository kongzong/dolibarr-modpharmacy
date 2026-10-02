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
dol_include_once('/pharmacy/class/pharmacybatchaction.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

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
$action = GETPOST('action', 'aZ09');
$canDispose = $user->hasRight('pharmacy', 'dispose');

// ---- Disposition confirm (POSTed by formconfirm): SCRAP / BLOCK / UNBLOCK.
// Database writes happen here, before llxHeader(); the formconfirm itself is
// printed after llxHeader() (it needs the header already sent).
$disposeError = '';
if ($action === 'confirm_dispose' && GETPOST('confirm', 'alpha') === 'yes') {
	if (!$canDispose) {
		accessforbidden();
	}
	$op = GETPOST('dispose_op', 'aZ09');
	$fkProduct = GETPOSTINT('dispose_product');
	$batch = trim(GETPOST('dispose_batch', 'alpha'));
	$expectedQty = (float) GETPOST('dispose_qty', 'alpha');
	$note = trim(GETPOST('dispose_note', 'restricthtml'));
	// SCRAP needs the concrete warehouse of the row (not the wid filter,
	// which is 0 = all warehouses); BLOCK/UNBLOCK ignore it.
	$dwid = GETPOSTINT('dispose_wid');
	if ($dwid <= 0) {
		$dwid = $wid;
	}
	$batchAction = new PharmacyBatchAction($db);
	if ($op === 'SCRAP') {
		$res = $batchAction->scrap($user, $fkProduct, $batch, $dwid, $expectedQty, $note);
	} elseif ($op === 'BLOCK' || $op === 'UNBLOCK') {
		$res = $batchAction->setBlock($user, $fkProduct, $batch, $op, $note);
	} else {
		$res = -1;
		$batchAction->error = 'PharmacyExpiryErrInvalid';
	}
	if ($res > 0) {
		setEventMessages($langs->trans("PharmacyExpiryDone"), null);
		header('Location: '.$_SERVER["PHP_SELF"].'?wid='.((int) $wid).'&token='.newToken());
		exit;
	}
	$disposeError = $batchAction->error;
	setEventMessages($langs->trans($disposeError !== '' ? $disposeError : 'PharmacyExpiryErrInvalid'), null, 'errors');
	$action = '';
}

llxHeader('', $langs->trans("PharmacyExpiry"));

print load_fiche_titre($langs->trans("PharmacyExpiry").' <span class="opacitymedium">('.(int) $window.' d)</span>', '<a class="butActionNew" href="'.dol_buildpath('/pharmacy/decant.php', 1).'"><span class="fa fa-box fa-fw valignmiddle"></span>'.$langs->trans("PharmacyDecant").'</a>', 'fa-hourglass-half');

// ---- formconfirm for the row actions (needs headers already sent) ----
$askOp = '';
if ($canDispose && ($action === 'ask_scrap' || $action === 'ask_block' || $action === 'ask_unblock')) {
	$askOp = $action === 'ask_scrap' ? 'SCRAP' : ($action === 'ask_block' ? 'BLOCK' : 'UNBLOCK');
	$askProduct = GETPOSTINT('product');
	$askBatch = trim(GETPOST('batch', 'alpha'));
	$askQty = (float) GETPOST('qty', 'alpha');
	$askWid = GETPOSTINT('dispose_wid');
	$backUrl = $_SERVER["PHP_SELF"].'?wid='.((int) $wid).'&token='.newToken()
		.'&dispose_op='.$askOp.'&dispose_product='.((int) $askProduct)
		.'&dispose_batch='.urlencode($askBatch).'&dispose_qty='.price2num($askQty, 'MS')
		.'&dispose_wid='.((int) $askWid);
	$form = new Form($db);
	if ($askOp === 'SCRAP') {
		$formquestion = array(
			0 => array('type' => 'text', 'name' => 'dispose_note', 'label' => $langs->trans("PharmacyExpiryScrapNote"), 'value' => '', 'morecss' => 'minwidth300'),
		);
		print $form->formconfirm($backUrl, $langs->trans("PharmacyExpiryScrap"), $langs->trans("PharmacyExpiryScrapAsk", $askBatch, price2num($askQty, 'MS')), 'confirm_dispose', $formquestion, 'yes');
	} elseif ($askOp === 'BLOCK') {
		print $form->formconfirm($backUrl, $langs->trans("PharmacyExpiryBlock"), $langs->trans("PharmacyExpiryBlockAsk", $askBatch), 'confirm_dispose', array(), 'yes');
	} else {
		print $form->formconfirm($backUrl, $langs->trans("PharmacyExpiryUnblock"), $langs->trans("PharmacyExpiryUnblockAsk", $askBatch), 'confirm_dispose', array(), 'yes');
	}
}

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
print '<th class="liste_titre center">'.$langs->trans("PharmacyExpiryActionCol").'</th>';
print '</tr>';

if (empty($rows)) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans("PharmacyExpiryNone").'</span></td></tr>';
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

	// Disposition links (dispose permission): scrap the whole batch, or
	// hold / release it (hold = excluded from FEFO dispensing).
	$rowQty = price2num($r['qty'], 'MS');
	$actionUrl = $_SERVER["PHP_SELF"].'?wid='.((int) $wid).'&token='.newToken().'&product='.((int) $r['fk_product']).'&batch='.urlencode($r['batch']).'&qty='.$rowQty.'&dispose_wid='.((int) $r['fk_entrepot']);
	$dispCell = '';
	if ($canDispose) {
		$links = array();
		if ($r['blocked']) {
			$links[] = '<a class="reposition" href="'.$actionUrl.'&action=ask_unblock"><span class="fa fa-play fa-fw valignmiddle"></span>'.$langs->trans("PharmacyExpiryUnblock").'</a>';
		} else {
			$links[] = '<a class="reposition" href="'.$actionUrl.'&action=ask_block"><span class="fa fa-pause fa-fw valignmiddle"></span>'.$langs->trans("PharmacyExpiryBlock").'</a>';
		}
		$links[] = '<a class="reposition" href="'.$actionUrl.'&action=ask_scrap"><span class="fa fa-trash fa-fw valignmiddle"></span>'.$langs->trans("PharmacyExpiryScrap").'</a>';
		$dispCell = implode(' &nbsp; ', $links);
	}

	print '<tr class="oddeven">';
	print '<td><a href="'.dol_buildpath('/product/card.php', 1).'?id='.((int) $r['fk_product']).'">'.dol_escape_htmltag($r['product']).'</a></td>';
	print '<td class="nowrap">'.dol_escape_htmltag($r['batch']).'</td>';
	print '<td class="nowrap">'.($r['sellby'] ? dol_print_date($r['sellby'], 'day') : '').'</td>';
	print '<td class="nowrap">'.($r['eatby'] ? dol_print_date($r['eatby'], 'day') : '').'</td>';
	print '<td class="nowrap">'.$daysBadge.'</td>';
	print '<td class="right">'.$rowQty.'</td>';
	print '<td class="nowrap"><a href="'.dol_buildpath('/product/stock/card.php', 1).'?id='.((int) $r['fk_entrepot']).'">'.dol_escape_htmltag($r['warehouse']).'</a></td>';
	print '<td class="center nowrap">'.($r['blocked'] ? '<span class="badge badge-status8" title="'.$langs->trans("PharmacyExpiryBlockedHelp").'">'.$langs->trans("PharmacyExpiryBlocked").'</span> ' : '').$dispCell.'</td>';
	print '</tr>';
}
print '</table></div>';

llxFooter();
$db->close();
