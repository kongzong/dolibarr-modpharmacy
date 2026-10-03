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
 * \file    htdocs/custom/pharmacy/purchase.php
 * \ingroup pharmacy
 * \brief   Supplier purchase requisition: pick a supplier, add medicine lines
 *          into a temporary cart, then create + validate + approve a native
 *          Dolibarr supplier order (llx_commande_fournisseur) ready to be
 *          dispatched on pharmacy/dispatch.php.
 *
 * No proprietary table: the order itself lives in the core supplier order
 * module. Only the cart is kept in the session, like pharmacy/retail.php.
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/pharmacy/lib/pharmacy.lib.php');

$langs->loadLangs(array('pharmacy@pharmacy', 'products', 'companies', 'supplier'));

$action = GETPOST('action', 'aZ09');
$token = GETPOST('token', 'alpha');

if (!$user->hasRight('pharmacy', 'purchase')) {
	accessforbidden();
}

$cartKey = 'pharmacy_purchase_cart';
if (!isset($_SESSION[$cartKey]) || !is_array($_SESSION[$cartKey])) {
	$_SESSION[$cartKey] = array();
}
$socKey = 'pharmacy_purchase_soc';

$supplierId = GETPOSTINT('fk_soc');
$fkProduct = GETPOSTINT('fk_product');
$qty = (float) price2num(GETPOST('qty', 'alpha'));
$price = (float) price2num(GETPOST('price', 'alpha'));

// ---------------------------------------------------------------- actions
if ($action === 'addline' && $token !== '') {
	if ($supplierId > 0) {
		// 供应商随清单一起记住：加行后页面会重定向，GET 参数会丢
		$_SESSION[$socKey] = $supplierId;
	} elseif (!empty($_SESSION[$socKey])) {
		$supplierId = (int) $_SESSION[$socKey];
	}
	if ($fkProduct <= 0 || $qty <= 0) {
		setEventMessages($langs->trans('PharmacyPurchaseErrQty'), null, 'errors');
	} else {
		$sql = "SELECT rowid, ref, label, price, tobatch, fk_product_type";
		$sql .= " FROM ".$db->prefix()."product WHERE rowid = ".$fkProduct;
		$resql = $db->query($sql);
		$prod = $resql ? $db->fetch_object($resql) : null;
		if (!$prod) {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		} elseif ((int) $prod->fk_product_type !== 0) {
			// Services are never ordered through this screen
			setEventMessages($langs->trans('PharmacyPurchaseErrService', $prod->ref), null, 'errors');
		} elseif (empty($prod->tobatch)) {
			setEventMessages($langs->trans('PharmacyRetailNoBatch', $prod->ref), null, 'errors');
		} else {
			$linePrice = $price > 0 ? $price : (float) $prod->price;
			$found = false;
			foreach ($_SESSION[$cartKey] as $i => $line) {
				if ((int) $line['fk_product'] === (int) $prod->rowid) {
					$_SESSION[$cartKey][$i]['qty'] = (float) $line['qty'] + $qty;
					$_SESSION[$cartKey][$i]['price'] = $linePrice;
					$found = true;
					break;
				}
			}
			if (!$found) {
				$_SESSION[$cartKey][] = array(
					'fk_product' => (int) $prod->rowid,
					'ref' => (string) $prod->ref,
					'label' => (string) $prod->label,
					'price' => $linePrice,
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

// Create the native supplier order out of the cart.
$orderInfo = array();
if ($action === 'createorder' && $token !== '' && !empty($_SESSION[$cartKey])) {
	if ($supplierId <= 0 && !empty($_SESSION[$socKey])) {
		$supplierId = (int) $_SESSION[$socKey];
	}
	if ($supplierId <= 0) {
		setEventMessages($langs->trans('PharmacyPurchaseErrSupplier'), null, 'errors');
	} else {
		$order = new CommandeFournisseur($db);
		$order->socid = $supplierId;
		$order->date = dol_now();
		$res = $order->create($user);
		if ($res <= 0) {
			setEventMessages($order->error, $order->errors, 'errors');
		} else {
			foreach ($_SESSION[$cartKey] as $line) {
				$lineRes = $order->addline(
					(string) $line['label'],
					(float) $line['price'],
					(float) $line['qty'],
					0, // txtva: small-scale clinic purchases are booked untaxed
					0,
					0,
					(int) $line['fk_product'],
					0,
					'',
					0,
					'HT'
				);
				if ($lineRes <= 0) {
					setEventMessages($langs->trans('PharmacyPurchaseErrLine'), $order->errors, 'errors');
				}
			}
			// VALIDATED then ORDERSENT: dispatchProduct() only accepts orders
			// that have left the supplier (STATUS_ORDERSENT = 3).
			if (empty($order->lines)) {
				setEventMessages($langs->trans('PharmacyPurchaseErrEmpty'), null, 'errors');
			} elseif ($order->valid($user) <= 0) {
				setEventMessages($order->error, $order->errors, 'errors');
			} elseif ($order->approve($user) <= 0) {
				setEventMessages($order->error, $order->errors, 'errors');
			} elseif ($order->status != CommandeFournisseur::STATUS_ORDERSENT && $order->commande($user, dol_now(), 0, '') <= 0) {
				setEventMessages($order->error, $order->errors, 'errors');
			} else {
				$orderInfo = array('id' => $order->id, 'ref' => $order->ref);
				$_SESSION[$cartKey] = array();
			}
		}
	}
	if ($orderInfo) {
		// 建单成功直接进入收货页（批号/效期在那里录）
		header('Location: '.DOL_URL_ROOT.'/custom/pharmacy/dispatch.php?id='.((int) $orderInfo['id']).'&token='.newToken());
	} else {
		header('Location: '.$_SERVER['PHP_SELF'].'?token='.newToken());
	}
	exit;
}

/*
 * Views
 */

// 重定向后回显上次选中的供应商
if ($supplierId <= 0 && !empty($_SESSION[$socKey])) {
	$supplierId = (int) $_SESSION[$socKey];
}

llxHeader('', $langs->trans('PharmacyPurchase'), 'PharmacyPurchase');

print '<div class="fiche">';
print '<div class="ficheleft">';
print '<h3 class="titlebefore">'.$langs->trans('PharmacyPurchaseNew').'</h3>';

// -- supplier picker
$sql = "SELECT s.rowid, s.nom";
$sql .= " FROM ".$db->prefix()."societe s WHERE s.fournisseur = 1";
$sql .= " AND s.entity IN (".getEntity('societe').")";
$sql .= " ORDER BY s.nom";
$resql = $db->query($sql);
$suppliers = array();
while ($obj = $db->fetch_object($resql)) {
	$suppliers[$obj->rowid] = $obj->nom;
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="addline">';
print '<table class="noborder width100">';
print '<tr><td class="fieldrequired">'.$langs->trans('Supplier').'</td><td>';
print $suppliers
	? $form->selectarray('fk_soc', $suppliers, $supplierId)
	: '<span class="opacitymedium">'.$langs->trans('PharmacyPurchaseNoSupplier').'</span>';
print '</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('PharmacyPurchaseProduct').'</td><td>';
$sqlp = "SELECT rowid, ref, label FROM ".$db->prefix()."product";
$sqlp .= " WHERE fk_product_type = 0 AND tobatch = 1 AND entity IN (".getEntity('product').")";
$sqlp .= " ORDER BY ref";
$resqlp = $db->query($sqlp);
$productOptions = '';
while ($objp = $db->fetch_object($resqlp)) {
	$productOptions .= '<option value="'.((int) $objp->rowid).'">'.dol_escape_htmltag($objp->ref.' '.$objp->label).'</option>';
}
print '<select name="fk_product" class="flat minwidth300">'.$productOptions.'</select>';
print '</td></tr>';
print '<tr><td class="fieldrequired">'.$langs->trans('Qty').'</td><td><input class="flat minwidth100" type="text" name="qty" value="1"></td></tr>';
print '<tr><td>'.$langs->trans('PharmacyPurchasePriceUnit').'</td><td><input class="flat minwidth100" type="text" name="price" value="0"></td></tr>';
print '<tr><td></td><td><input type="submit" class="butAction" value="'.$langs->trans('PharmacyPurchaseAddLine').'"></td></tr>';
print '</table>';
print '</form>';

print '</div>'; // ficheleft

print '<div class="fichedright">';
print '<h3 class="titlebefore">'.$langs->trans('PharmacyPurchaseCart').'</h3>';
if (empty($_SESSION[$cartKey])) {
	print '<div class="opacitymiddle">'.$langs->trans('PharmacyPurchaseCartEmpty').'</div>';
} else {
	print '<table class="liste noborder">';
	print '<tr class="liste_titre">';
	print '<th>'.$langs->trans('Product').'</th>';
	print '<th class="right">'.$langs->trans('Qty').'</th>';
	print '<th class="right">'.$langs->trans('UnitPriceHT').'</th>';
	print '<th class="right">'.$langs->trans('TotalHT').'</th>';
	print '<th class="center">'.$langs->trans('Action').'</th>';
	print '</tr>';
	$total = 0;
	foreach ($_SESSION[$cartKey] as $idx => $line) {
		$lineTotal = (float) $line['qty'] * (float) $line['price'];
		$total += $lineTotal;
		print '<tr class="'.($idx % 2 ? 'impair' : 'pair').'">';
		print '<td>'.$line['ref'].' '.$line['label'].'</td>';
		print '<td class="right">'.((float) $line['qty']).'</td>';
		print '<td class="right">'.price($line['price']).'</td>';
		print '<td class="right">'.price($lineTotal).'</td>';
		print '<td class="center"><a href="'.$_SERVER['PHP_SELF'].'?action=remove&idx='.$idx.'&token='.newToken().'">'.img_delete().'</a></td>';
		print '</tr>';
	}
	print '<tr class="liste_titre"><td colspan="3" class="right">'.$langs->trans('TotalHT').'</td><td class="right">'.price($total).'</td><td></td></tr>';
	print '</table>';
	print '<div class="but">';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="clear">';
	print '<button type="submit" class="butActionDelete">'.$langs->trans('PharmacyRetailClear').'</button>';
	print '</form>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline;">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="createorder">';
	print '<input type="hidden" name="fk_soc" value="'.((int) $supplierId).'">';
	print '<button type="submit" class="butAction">'.$langs->trans('PharmacyPurchaseCreate').'</button>';
	print '</form>';
	print '</div>';
}
print '</div>'; // fichedright
print '</div>'; // fiche

llxFooter();
