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
 * \file    htdocs/custom/pharmacy/dispatch.php
 * \ingroup pharmacy
 * \brief   Receive a supplier order into the clinic warehouse. Each line takes
 *          an actual quantity plus the GSP mandatory batch data (batch number,
 *          sell-by and use-by dates) and is dispatched through the native
 *          CommandeFournisseur::dispatchProduct(), which writes llx_reception,
 *          increases llx_product_stock.reel and creates the lot/batch rows.
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/pharmacy/lib/pharmacy.lib.php');

$langs->loadLangs(array('pharmacy@pharmacy', 'products', 'companies', 'stocks', 'supplier'));

$action = GETPOST('action', 'aZ09');
$token = GETPOST('token', 'alpha');
$orderId = GETPOSTINT('id');

if (!$user->hasRight('pharmacy', 'dispatch')) {
	accessforbidden();
}

/**
 * Stock is only increased on dispatch when this core option is on. Turn it on
 * once (it is a global setup flag stored in llx_const) so a reception never
 * silently records a batch without touching the stock.
 */
if (!getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER')) {
	dol_const('STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER', '1');
	$conf->global->STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER = 1;
}

$order = new CommandeFournisseur($db);
if ($orderId > 0) {
	$order->fetch($orderId);
}
$order->fetch_lines();

$warehouseId = (int) getDolGlobalString('PHARMACY_WAREHOUSE_ID');
if ($warehouseId <= 0) {
	$sql = "SELECT rowid FROM ".$db->prefix()."entrepot LIMIT 1";
	$resql = $db->query($sql);
	$warehouseId = $resql ? (int) $db->fetch_object($resql)->rowid : 0;
}

// ---------------------------------------------------------------- actions
if ($action === 'dispatch' && $token !== '') {
	if ($warehouseId <= 0) {
		setEventMessages($langs->trans('PharmacyErrWarehouseRequired'), null, 'errors');
	} else {
		foreach ($_POST as $key => $val) {
			if (preg_match('/^qty_(\d+)$/', $key, $reg)) {
				$lineId = (int) $reg[1];
				$qty = (float) price2num((string) $val);
				if ($qty <= 0) {
					continue;
				}
				$batch = trim((string) GETPOST('batch_'.$lineId, 'alphanohtml'));
				$sellby = trim((string) GETPOST('sellby_'.$lineId, 'alpha'));
				$eatby = trim((string) GETPOST('eatby_'.$lineId, 'alpha'));
				if ($eatby === '') {
					$eatby = date('Y-m-d', strtotime('+2 year'));
				}
				if ($sellby === '') {
					$sellby = $eatby;
				}

				$sql = "SELECT rowid, fk_product, ref, label, subprice AS price";
				$sql .= " FROM ".$db->prefix()."commande_fournisseurdet";
				$sql .= " WHERE rowid = ".$lineId." AND fk_commande = ".$order->id;
				$resql = $db->query($sql);
				$line = $resql ? $db->fetch_object($resql) : null;
				if (!$line) {
					continue;
				}
				if ($batch === '') {
					// Suggest the house convention: <product ref>-B<YYYYMMDD of expiry>
					$batch = $line->ref.'-B'.str_replace('-', '', $eatby);
				}
				$res = $order->dispatchProduct(
					$user,
					(int) $line->fk_product,
					$qty,
					$warehouseId,
					(float) $line->price,
					'',
					(string) $eatby,
					(string) $sellby,
					$batch,
					$lineId
				);
				if ($res > 0) {
					setEventMessages($langs->trans('PharmacyDispatchOk', $line->ref, $qty, $batch), null, 'mesgs');
				} else {
					setEventMessages($langs->trans('PharmacyDispatchErr', $line->ref, $order->error), $order->errors, 'errors');
				}
			}
		}
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.$order->id.'&token='.newToken());
	exit;
}

/*
 * Views
 */

llxHeader('', $langs->trans('PharmacyDispatch'), 'PharmacyDispatch');

print '<h3 class="titlebefore">'.$langs->trans('PharmacyDispatchOrder').' '.$order->getNomUrl().'</h3>';
print $order->getLibStatut();

// Already received quantity per order line, from the core reception batches.
$sql = "SELECT fk_elementdet, SUM(qty) q FROM ".$db->prefix()."receptiondet_batch";
$sql .= " WHERE fk_element = ".((int) $order->id);
$sql .= " GROUP BY fk_elementdet";
$resql = $db->query($sql);
$received = array();
while ($obj = $db->fetch_object($resql)) {
	$received[(int) $obj->fk_elementdet] = (float) $obj->q;
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="dispatch">';
print '<input type="hidden" name="id" value="'.((int) $order->id).'">';

print '<table class="liste noborder">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Product').'</th>';
print '<th class="center">'.$langs->trans('PharmacyDispatchOrdered').'</th>';
print '<th class="center">'.$langs->trans('PharmacyDispatchReceived').'</th>';
print '<th class="center">'.$langs->trans('Qty').'</th>';
print '<th>'.$langs->trans('PharmacyDispatchBatch').'</th>';
	print '<th class="center">'.$langs->trans('PharmacyDispatchSellBy').'</th>';
	print '<th class="center">'.$langs->trans('PharmacyDispatchEatBy').'</th>';
print '</tr>';

$lines = $order->lines;
foreach ($lines as $line) {
	$ordered = (float) $line->qty;
	$got = isset($received[(int) $line->id]) ? $received[(int) $line->id] : 0;
	$rest = $ordered - $got;
	print '<tr class="'.($line->id % 2 ? 'impair' : 'pair').'">';
	print '<td>'.$line->ref.' '.$line->label.'</td>';
	print '<td class="center">'.price($ordered).'</td>';
	print '<td class="center">'.price($got).'</td>';
	print '<td class="center">';
	print '<input class="flat minwidth60" type="text" name="qty_'.((int) $line->id).'" value="'.($rest > 0 ? $rest : 0).'">';
	print '</td>';
	print '<td>';
	$suggest = $line->ref.'-B'.date('Ymd');
	print '<input class="flat" type="text" name="batch_'.((int) $line->id).'" size="24" value="'.dol_escape_htmltag($suggest).'">';
	print '</td>';
	print '<td class="center"><input class="flat" type="date" name="sellby_'.((int) $line->id).'"></td>';
	print '<td class="center"><input class="flat" type="date" name="eatby_'.((int) $line->id).'"></td>';
	print '</tr>';
}
print '</table>';
print '<div class="but"><button type="submit" class="butAction">'.$langs->trans('PharmacyDispatchReceive').'</button></div>';
print '</form>';

llxFooter();
