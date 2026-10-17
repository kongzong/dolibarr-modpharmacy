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
 * \file    htdocs/custom/pharmacy/purchase_suggest.php
 * \ingroup pharmacy
 * \brief   Purchase suggestions: compare stock / safety threshold / 30-day
 *          consumption / in-transit supplier orders and offer one-click
 *          generation of a native supplier order (same chain as purchase.php).
 *
 * No proprietary table. Formulas:
 *   target  = desiredstock when > 0, else seuil_stock_alerte + out30
 *   suggest = max(0, target - stock - in_transit)
 * In-transit (exact): open supplier orders (statut 1/2/3/4) line qty minus
 * received qty recorded in llx_receptiondet_batch (partial receipts included).
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

$P = $db->prefix();
$entityProduct = getEntity('product');

/** Aggregate stock (all warehouses) keyed by product rowid. */
function pharmacy_suggest_stock($db, $P, $entityProduct)
{
	$rows = array();
	$sql = "SELECT fk_product, SUM(reel) s FROM ".$P."product_stock GROUP BY fk_product";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$rows[(int) $obj->fk_product] = (float) $obj->s;
	}
	return $rows;
}

/** 30-day outbound consumption keyed by product rowid. */
function pharmacy_suggest_out30($db, $P)
{
	$rows = array();
	$sql = "SELECT fk_product, SUM(-value) s FROM ".$P."stock_mouvement";
	$sql .= " WHERE value < 0 AND datem > DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY fk_product";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$rows[(int) $obj->fk_product] = (float) $obj->s;
	}
	return $rows;
}

/**
 * Exact in-transit quantity keyed by product rowid: open supplier orders
 * (statut 1 draft-validated / 2 approved / 3 ordered / 4 partial received)
 * minus what has already been received into llx_receptiondet_batch.
 */
function pharmacy_suggest_intransit($db, $P)
{
	$rows = array();
	$sql = "SELECT cd.fk_product, SUM(cd.qty - COALESCE(rb.received, 0)) s";
	$sql .= " FROM ".$P."commande_fournisseurdet AS cd";
	$sql .= " INNER JOIN ".$P."commande_fournisseur AS cf ON cf.rowid = cd.fk_commande";
	$sql .= " LEFT JOIN (SELECT fk_element, fk_elementdet, SUM(qty) received";
	$sql .= "            FROM ".$P."receptiondet_batch GROUP BY fk_element, fk_elementdet) AS rb";
	$sql .= "   ON rb.fk_element = cf.rowid AND rb.fk_elementdet = cd.rowid";
	$sql .= " WHERE cf.fk_statut IN (1, 2, 3, 4) AND cf.entity IN (".getEntity('commande_fournisseur').")";
	$sql .= " GROUP BY cd.fk_product";
	$resql = $db->query($sql);
	while ($resql && ($obj = $db->fetch_object($resql))) {
		$rows[(int) $obj->fk_product] = max(0.0, (float) $obj->s);
	}
	return $rows;
}

/**
 * Suggestion rows for every stockable product.
 * @return array<int,array{rowid:int,ref:string,label:string,stock:float,seuil:float,desired:float,out30:float,intransit:float,target:float,suggest:float}>
 */
function pharmacy_suggest_rows($db, $P, $entityProduct)
{
	$stocks = pharmacy_suggest_stock($db, $P, $entityProduct);
	$out30 = pharmacy_suggest_out30($db, $P);
	$transit = pharmacy_suggest_intransit($db, $P);

	$sql = "SELECT rowid, ref, label, seuil_stock_alerte AS seuil, desiredstock, tobatch";
	$sql .= " FROM ".$P."product";
	$sql .= " WHERE fk_product_type = 0 AND stockable_product = 1 AND entity IN (".$entityProduct.")";
	$sql .= " ORDER BY ref";
	$resql = $db->query($sql);

	$rows = array();
	while ($resql && ($p = $db->fetch_object($resql))) {
		$pid = (int) $p->rowid;
		$stock = isset($stocks[$pid]) ? $stocks[$pid] : 0.0;
		$seuil = (float) $p->seuil;
		$desired = (float) $p->desiredstock;
		$out = isset($out30[$pid]) ? $out30[$pid] : 0.0;
		$trans = isset($transit[$pid]) ? $transit[$pid] : 0.0;

		if ($desired > 0) {
			$target = $desired;
		} elseif ($seuil > 0) {
			$target = $seuil + $out;
		} else {
			$target = 0.0;
		}
		$suggest = max(0.0, $target - $stock - $trans);

		// Only rows that actually need replenishment are shown.
		if ($suggest <= 0 && !($seuil > 0 && $stock < $seuil)) {
			continue;
		}

		$rows[$pid] = array(
			'rowid' => $pid,
			'ref' => (string) $p->ref,
			'label' => (string) $p->label,
			'stock' => $stock,
			'seuil' => $seuil,
			'desired' => $desired,
			'out30' => $out,
			'intransit' => $trans,
			'target' => $target,
			'suggest' => $suggest,
		);
	}
	return $rows;
}

// ---------------------------------------------------------------- actions
if ($action === 'generate' && $token !== '') {
	$supplierId = GETPOSTINT('fk_soc');
	$sug = GETPOST('sug', 'array');
	$lines = array();

	// Keep only positive quantities on valid stockable products.
	$valid = array();
	foreach ($sug as $pid => $qty) {
		$pid = (int) $pid;
		$qty = (float) price2num((string) $qty);
		if ($pid > 0 && $qty > 0) {
			$valid[$pid] = $qty;
		}
	}

	if ($supplierId <= 0) {
		setEventMessages($langs->trans('PharmacySuggestNoSupplier'), null, 'errors');
	} elseif (empty($valid)) {
		setEventMessages($langs->trans('PharmacySuggestNoRow'), null, 'errors');
	} else {
		$sql = "SELECT rowid, ref, label, price, tobatch, fk_product_type";
		$sql .= " FROM ".$P."product WHERE rowid IN (".implode(',', array_keys($valid)).")";
		$resql = $db->query($sql);
		while ($resql && ($p = $db->fetch_object($resql))) {
			$pid = (int) $p->rowid;
			if ((int) $p->fk_product_type !== 0 || empty($p->tobatch)) {
				continue; // services / non-batched products are never ordered here
			}
			$lines[] = array(
				'fk_product' => $pid,
				'ref' => (string) $p->ref,
				'label' => (string) $p->label,
				'price' => (float) $p->price,
				'qty' => $valid[$pid],
			);
		}

		if (empty($lines)) {
			setEventMessages($langs->trans('PharmacySuggestNoRow'), null, 'errors');
		} else {
			$order = new CommandeFournisseur($db);
			$order->socid = $supplierId;
			$order->date = dol_now();
			$res = $order->create($user);
			if ($res <= 0) {
				setEventMessages($order->error, $order->errors, 'errors');
			} else {
				foreach ($lines as $line) {
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
				// Same roll-up as purchase.php: VALIDATED -> ORDERSENT so that
				// dispatchProduct() accepts the order on the receive screen.
				if (empty($order->lines)) {
					setEventMessages($langs->trans('PharmacyPurchaseErrEmpty'), null, 'errors');
				} elseif ($order->valid($user) <= 0) {
					setEventMessages($order->error, $order->errors, 'errors');
				} elseif ($order->approve($user) <= 0) {
					setEventMessages($order->error, $order->errors, 'errors');
				} elseif ($order->status != CommandeFournisseur::STATUS_ORDERSENT && $order->commande($user, dol_now(), 0, '') <= 0) {
					setEventMessages($order->error, $order->errors, 'errors');
				} else {
					setEventMessages($langs->trans('PharmacySuggestGenerated', $order->ref), null, 'mesgs');
					header('Location: '.DOL_URL_ROOT.'/custom/pharmacy/dispatch.php?id='.((int) $order->id).'&token='.newToken());
					exit;
				}
			}
		}
	}
}

/*
 * View
 */

llxHeader('', $langs->trans('PharmacySuggest'), 'PharmacySuggest');

$rows = pharmacy_suggest_rows($db, $P, $entityProduct);

print load_fiche_titre($langs->trans('PharmacySuggest'), null, 'stock');
print '<div class="opacitymedium paddingbottom">'.$langs->trans('PharmacySuggestHint').'</div>';

if (empty($rows)) {
	print '<div class="opacitymedium">'.$langs->trans('PharmacySuggestNone').'</div>';
	llxFooter();
	exit;
}

// Supplier picker + generate form wraps the suggestion table.
$sql = "SELECT s.rowid, s.nom FROM ".$P."societe s WHERE s.fournisseur = 1";
$sql .= " AND s.entity IN (".getEntity('societe').") ORDER BY s.nom";
$resql = $db->query($sql);
$suppliers = array();
while ($resql && ($obj = $db->fetch_object($resql))) {
	$suppliers[$obj->rowid] = $obj->nom;
}

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="generate">';

print '<table class="noborder centpercent">';
print '<tr><td class="fieldrequired" style="width:120px">'.$langs->trans('Supplier').'</td><td>';
print $suppliers
	? $form->selectarray('fk_soc', $suppliers, GETPOSTINT('fk_soc') > 0 ? GETPOSTINT('fk_soc') : 0, 1)
	: '<span class="opacitymedium">'.$langs->trans('PharmacyPurchaseNoSupplier').'</span>';
print '</td></tr></table>';

print '<div class="div-table-responsive-no-min">';
print '<table class="liste centpercent">';
print '<tr class="liste_titre">';
print '<th class="center" style="width:36px"></th>';
print '<th>'.$langs->trans('Product').'</th>';
print '<th class="right">'.$langs->trans('PharmacySuggestStock').'</th>';
print '<th class="right">'.$langs->trans('PharmacySuggestSeuil').'</th>';
print '<th class="right">'.$langs->trans('PharmacySuggestOut30').'</th>';
print '<th class="right">'.$langs->trans('PharmacySuggestInTransit').'</th>';
print '<th class="right">'.$langs->trans('PharmacySuggestTarget').'</th>';
print '<th class="right">'.$langs->trans('PharmacySuggestQty').'</th>';
print '</tr>';

$i = 0;
foreach ($rows as $row) {
	$low = $row['seuil'] > 0 && $row['stock'] < $row['seuil'];
	print '<tr class="'.($i % 2 ? 'impair' : 'pair').'">';
	print '<td class="center">';
	if ($row['suggest'] > 0) {
		print '<input type="checkbox" class="sugcheck" data-pid="'.$row['rowid'].'" checked>';
	}
	print '</td>';
	print '<td>'.dol_escape_htmltag($row['ref'].' '.$row['label']).'</td>';
	print '<td class="right'.($low ? ' error' : '').'">'.price2num($row['stock'], 'MS').'</td>';
	print '<td class="right">'.($row['seuil'] > 0 ? price2num($row['seuil'], 'MS') : '<span class="opacitymedium">-</span>').'</td>';
	print '<td class="right">'.price2num($row['out30'], 'MS').'</td>';
	print '<td class="right">'.($row['intransit'] > 0 ? price2num($row['intransit'], 'MS') : '0').'</td>';
	print '<td class="right">'.($row['target'] > 0 ? price2num($row['target'], 'MS') : '<span class="opacitymedium">-</span>').'</td>';
	print '<td class="right">';
	if ($row['suggest'] > 0) {
		print '<input type="text" class="flat right width50" name="sug['.$row['rowid'].']" value="'.price2num(ceil($row['suggest']), 'MS').'">';
	} else {
		print '<span class="opacitymedium">-</span>';
	}
	print '</td>';
	print '</tr>';
	$i++;
}
print '</table>';
print '</div>';

print '<div class="tabsAction">';
print '<button type="submit" class="butAction"'.($suppliers ? '' : ' disabled').'>'.dol_escape_htmltag($langs->trans('PharmacySuggestGenerate')).'</button>';
print '</div>';
print '</form>';

// Checkbox toggles: unchecking a row blanks its qty so nothing is ordered.
print '<script type="text/javascript">';
print 'jQuery(document).ready(function(){';
print 'jQuery(".sugcheck").on("change",function(){';
print 'var pid=jQuery(this).data("pid");';
print 'var input=jQuery(\'input[name="sug[\'+pid+\']"]\');';
print 'if(input.length){input.prop("disabled",!jQuery(this).is(":checked"));}';
print '});});';
print '</script>';

llxFooter();
