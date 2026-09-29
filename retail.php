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
 * \file    htdocs/custom/pharmacy/retail.php
 * \ingroup pharmacy
 * \brief   OTC retail counter: pick products into a cart, then one click
 *          builds the whole chain for the shared walk-in patient —
 *          internal OTC prescription -> dispense (stock, FEFO) -> paid bill.
 *          Reuses every module's own classes, so stock, batch/expiry and
 *          audit logic is never duplicated here.
 */

require '../../main.inc.php';
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/dispense.class.php');
dol_include_once('/prescription/class/prescriptionsheet.class.php');
dol_include_once('/clinicpay/class/paybill.class.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/patient/lib/patient.lib.php');

$langs->loadLangs(array('pharmacy@pharmacy', 'prescription@prescription', 'clinicpay@clinicpay', 'patient@patient', 'stocks', 'companies'));

$action = GETPOST('action', 'aZ09');
$token = GETPOST('token', 'alpha');
$warehouseId = GETPOSTINT('fk_warehouse');
$channel = GETPOST('channel', 'alpha') ? (GETPOST('channel', 'alpha') === 'SCAN' ? 'SCAN' : 'CASH') : 'CASH';
$channelRef = GETPOST('channel_ref', 'alphanohtml');
$search = GETPOST('q', 'restricthtml');

// Counter rights: retail hits four modules, name the missing one instead of a
// blank 403 so the operator knows who to ask for the permission.
$required = array(
	array('pharmacy', 'write', 'PharmacyRetailErrRightPharmacyWrite'),
	array('pharmacy', 'dispense', 'PharmacyRetailErrRightPharmacyDispense'),
	array('prescription', 'write', 'PharmacyRetailErrRightPrescriptionWrite'),
	array('prescription', 'issue', 'PharmacyRetailErrRightPrescriptionIssue'),
	array('clinicpay', 'write', 'PharmacyRetailErrRightClinicPayWrite'),
	array('clinicpay', 'pay', 'PharmacyRetailErrRightClinicPayPay'),
);
foreach ($required as $r) {
	if (!$user->hasRight($r[0], $r[1])) {
		accessforbidden($langs->trans($r[2]));
	}
}

$cartKey = 'pharmacy_retail_cart';
if (!isset($_SESSION[$cartKey]) || !is_array($_SESSION[$cartKey])) {
	$_SESSION[$cartKey] = array();
}

$checkoutInfo = array();
$checkoutResult = 0;

// ---------------------------------------------------------------- actions
if ($action === 'add' && $token !== '') {
	$fkProduct = GETPOSTINT('fk_product');
	$qty = (float) price2num(GETPOST('qty', 'alpha'));
	if ($fkProduct > 0 && $qty > 0) {
		// Medicines must be batch-managed to leave the warehouse (design
		// consensus 2026-09-24): refuse anything else before it reaches the cart.
		$sql = "SELECT rowid, ref, label, price, tva_tx, tobatch FROM ".$db->prefix()."product WHERE rowid = ".$fkProduct;
		$resql = $db->query($sql);
		$prod = $resql ? $db->fetch_object($resql) : null;
		if (!$prod) {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		} elseif (empty($prod->tobatch)) {
			setEventMessages($langs->trans('PharmacyRetailNoBatch', $prod->ref), null, 'errors');
		} else {
			$found = false;
			foreach ($_SESSION[$cartKey] as $i => $line) {
				if ((int) $line['fk_product'] === $fkProduct) {
					$_SESSION[$cartKey][$i]['qty'] = (float) $line['qty'] + $qty;
					$found = true;
					break;
				}
			}
			if (!$found) {
				$_SESSION[$cartKey][] = array(
					'fk_product' => (int) $prod->rowid,
					'ref' => (string) $prod->ref,
					'label' => (string) $prod->label,
					'price' => (float) $prod->price,
					'qty' => $qty,
				);
			}
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?token='.newToken());
	exit;
}

if ($action === 'remove' && $token !== '') {
	$idx = GETPOSTINT('idx');
	if (isset($_SESSION[$cartKey][$idx])) {
		unset($_SESSION[$cartKey][$idx]);
		$_SESSION[$cartKey] = array_values($_SESSION[$cartKey]);
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?token='.newToken());
	exit;
}

if ($action === 'clear' && $token !== '') {
	$_SESSION[$cartKey] = array();
	header('Location: '.$_SERVER['PHP_SELF'].'?token='.newToken());
	exit;
}

if ($action === 'checkout' && $token !== '' && !empty($_SESSION[$cartKey])) {
	if ($warehouseId <= 0) {
		setEventMessages($langs->trans('PharmacyErrWarehouseRequired'), null, 'errors');
	} elseif ($channel === 'SCAN' && $channelRef === '') {
		setEventMessages($langs->trans('PharmacyRetailErrScanRef'), null, 'errors');
	} else {
		$checkoutResult = pharmacy_retail_checkout($db, $user, $_SESSION[$cartKey], $warehouseId, $channel, $channelRef, $checkoutInfo);
		if ($checkoutResult === 1) {
			setEventMessages($langs->trans('PharmacyRetailDone', $checkoutInfo['bill_ref'], $checkoutInfo['prescription_ref'], $checkoutInfo['dispense_ref']), null, 'mesgs');
			$_SESSION[$cartKey] = array();
		} else {
			setEventMessages($langs->trans($checkoutInfo['error']), null, 'errors');
		}
	}
}

// ---------------------------------------------------------------- search
$products = array();
if ($search !== '') {
	$sql = "SELECT p.rowid, p.ref, p.label, p.price, p.tva_tx, p.fk_product_type, p.tobatch,";
	$sql .= " COALESCE(SUM(ps.reel), 0) as stock";
	$sql .= " FROM ".$db->prefix()."product as p";
	$sql .= " LEFT JOIN ".$db->prefix()."product_stock as ps ON ps.fk_product = p.rowid";
	$sql .= " WHERE p.tosell = 1 AND p.entity IN (".getEntity('product').")";
	$sql .= " AND (p.ref LIKE '%".$db->escape($search)."%' OR p.label LIKE '%".$db->escape($search)."%')";
	$sql .= " GROUP BY p.rowid, p.ref, p.label, p.price, p.tva_tx, p.fk_product_type, p.tobatch";
	$sql .= " ORDER BY p.ref ASC";
	$sql .= $db->plimit(30);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$products[] = $o;
		}
		$db->free($resql);
	}
}

$warehouses = pharmacy_warehouse_options($db);
$cartTotal = 0.0;
foreach ($_SESSION[$cartKey] as $line) {
	$cartTotal += (float) $line['price'] * (float) $line['qty'];
}

// ---------------------------------------------------------------- render
llxHeader('', $langs->trans('PharmacyRetail'));

print load_fiche_titre($langs->trans('PharmacyRetail'), '', 'fa-cash-register');

if ($checkoutResult === 1) {
	print '<div class="warning">';
	print $langs->trans('PharmacyRetailReceipt', $checkoutInfo['prescription_ref'], $checkoutInfo['dispense_ref'], $checkoutInfo['bill_ref']);
	print ' — <a href="'.dol_buildpath('/clinicpay/bill.php', 1).'?id='.((int) $checkoutInfo['bill']).'">'.$langs->trans('ClinicPayBill').'</a>';
	print '</div>';
} elseif ($checkoutResult < 0 && !empty($checkoutInfo['partial'])) {
	print '<div class="error">';
	print $langs->trans('PharmacyRetailPartial', $checkoutInfo['prescription_ref'], $checkoutInfo['dispense_ref']);
	print '</div>';
}

// --- product search
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="search">';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('PharmacyRetailSearch').'</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('Product').'</td>';
print '<td><input type="text" name="q" class="minwidth300" value="'.dol_escape_htmltag($search).'"> <span class="opacitymedium">'.$langs->trans('PharmacyRetailBatchHint').'</span></td>';
print '<td colspan="2"><input type="submit" class="button" value="'.$langs->trans('Search').'"></td></tr>';
print '</table></div></form>';

if ($search !== '') {
	print '<div class="div-table-responsive">';
	print '<table class="tagtable liste centpercent">';
	print '<tr class="liste_titre"><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th class="right">'.$langs->trans('Price').'</th><th class="right">'.$langs->trans('Stock').'</th><th class="center">'.$langs->trans('Qty').'</th><th></th></tr>';
	if (empty($products)) {
		print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	foreach ($products as $p) {
		$sellable = !empty($p->tobatch);
		print '<tr class="oddeven'.($sellable ? '' : ' opacitymedium').'">';
		print '<td>'.$p->ref.'</td>';
		print '<td>'.dol_escape_htmltag($p->label);
		if (!$sellable) {
			print ' <span class="badge badge-status1">'.$langs->trans('PharmacyRetailNoBatchShort').'</span>';
		}
		print '</td>';
		print '<td class="right">'.price($p->price).'</td>';
		print '<td class="right">'.price2num($p->stock, 'MS').'</td>';
		if ($sellable) {
			print '<td class="center"><form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="add">';
			print '<input type="hidden" name="fk_product" value="'.((int) $p->rowid).'">';
			print '<input type="text" name="qty" value="1" class="width50 right"> ';
			print '<input type="submit" class="button smallpaddingimp" value="'.$langs->trans('PharmacyRetailAdd').'">';
			print '</form></td>';
		} else {
			// No batch management -> the dispense step would refuse it anyway
			print '<td class="center"><span class="opacitymedium">'.$langs->trans('PharmacyRetailNoBatchHint').'</span></td>';
		}
		print '<td></td>';
		print '</tr>';
	}
	print '</table></div>';
}

// --- cart
print '<br>';
print load_fiche_titre($langs->trans('PharmacyRetailCart'), '', 'fa-shopping-cart');

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre"><th>'.$langs->trans('Ref').'</th><th>'.$langs->trans('Label').'</th><th class="right">'.$langs->trans('Price').'</th><th class="right">'.$langs->trans('Qty').'</th><th class="right">'.$langs->trans('TotalHT').'</th><th class="center">'.$langs->trans('Delete').'</th></tr>';
if (empty($_SESSION[$cartKey])) {
	print '<tr><td colspan="6"><span class="opacitymedium">'.$langs->trans('PharmacyRetailEmptyCart').'</span></td></tr>';
}
foreach ($_SESSION[$cartKey] as $i => $line) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($line['ref']).'</td>';
	print '<td>'.dol_escape_htmltag($line['label']).'</td>';
	print '<td class="right">'.price($line['price']).'</td>';
	print '<td class="right">'.price2num($line['qty'], 'MS').'</td>';
	print '<td class="right">'.price(price2num((float) $line['price'] * (float) $line['qty'], 'MT')).'</td>';
	print '<td class="center"><a href="'.$_SERVER['PHP_SELF'].'?action=remove&idx='.((int) $i).'&token='.newToken().'">'.img_delete().'</a></td>';
	print '</tr>';
}
if (!empty($_SESSION[$cartKey])) {
	print '<tr class="liste_total"><td colspan="4" class="right">'.$langs->trans('TotalHT').'</td><td class="right">'.price($cartTotal).'</td><td></td></tr>';
}
print '</table></div>';

// --- checkout
if (!empty($_SESSION[$cartKey])) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="checkout">';
	print '<div class="div-table-responsive">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('PharmacyRetailCheckout').'</td></tr>';
	print '<tr><td class="fieldrequired">'.$langs->trans('Warehouse').'</td><td>'.$form->selectarray('fk_warehouse', $warehouses, $warehouseId, 1).'</td></tr>';
	$channels = array(CLINICPAY_CHANNEL_CASH => clinicpay_channel_label(CLINICPAY_CHANNEL_CASH), CLINICPAY_CHANNEL_SCAN => clinicpay_channel_label(CLINICPAY_CHANNEL_SCAN));
	print '<tr><td class="fieldrequired">'.$langs->trans('ClinicPayBillChannel').'</td><td>'.$form->selectarray('channel', $channels, $channel, 0).'</td></tr>';
	print '<tr><td>'.$langs->trans('ClinicPayBillChannelRef').'</td><td><input type="text" name="channel_ref" value="'.dol_escape_htmltag($channelRef).'"> <span class="opacitymedium">'.$langs->trans('ClinicPayChannelRefHint').'</span></td></tr>';
	print '</table></div>';
	print '<div class="center">';
	print '<a class="butActionDelete" href="'.$_SERVER['PHP_SELF'].'?action=clear&token='.newToken().'">'.$langs->trans('PharmacyRetailClear').'</a> ';
	print '<input type="submit" class="butAction" value="'.$langs->trans('PharmacyRetailSubmit').'">';
	print '</div>';
	print '</form>';
}

llxFooter();
$db->close();
