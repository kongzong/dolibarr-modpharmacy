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
 * \file    htdocs/custom/pharmacy/purchase_list.php
 * \ingroup pharmacy
 * \brief   Supplier order list (native llx_commande_fournisseur records created
 *          by the purchase replenishment flow).
 *
 * The purchase cart builds native supplier orders, so this page is the only UI
 * place that answers "which orders exist, what has been received, what is
 * left". Fully received orders stay listed (never deleted) for the audit trail.
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

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("pharmacy@pharmacy", "orders@commande"));

if (!$user->hasRight('pharmacy', 'purchase') && !$user->hasRight('pharmacy', 'dispatch')) {
	accessforbidden();
}

$form = new Form($db);

// Filters
$socid = GETPOSTINT('fk_soc');
$statusRaw = (string) GETPOST('search_status', 'alpha');
$status = ($statusRaw === '' ? '-1' : (int) $statusRaw);
$dateFrom = GETPOST('date_from', 'alpha');
$dateTo = GETPOST('date_to', 'alpha');
$token = GETPOST('token', 'alpha');

// Status filter: -1 = all (own labels: the core order lang has no such keys)
$statusOptions = array(
	'-1' => $langs->trans('All'),
	CommandeFournisseur::STATUS_DRAFT => $langs->trans('PharmacyPoStatusDraft'),
	CommandeFournisseur::STATUS_VALIDATED => $langs->trans('PharmacyPoStatusValidated'),
	CommandeFournisseur::STATUS_ACCEPTED => $langs->trans('PharmacyPoStatusAccepted'),
	CommandeFournisseur::STATUS_ORDERSENT => $langs->trans('PharmacyPoStatusOrdered'),
	CommandeFournisseur::STATUS_RECEIVED_PARTIALLY => $langs->trans('PharmacyPoStatusPartially'),
	CommandeFournisseur::STATUS_RECEIVED_COMPLETELY => $langs->trans('PharmacyPoStatusComplete'),
);

llxHeader('', $langs->trans('PharmacyPurchaseList'), 'PharmacyPurchaseList');

print load_fiche_titre($langs->trans('PharmacyPurchaseList'), '', 'fa-truck');

// ---------------------------------------------------------------- filters
print '<div class="div-table-responsive-no-min">';
print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
print '<table class="noborder centpercent">';
print '<tr>';
$supplierOptions = array(0 => $langs->trans('All'));
$sqlSup = "SELECT s.rowid, s.nom FROM ".$db->prefix()."societe as s";
$sqlSup .= " WHERE s.fournisseur = 1 AND s.entity IN (".getEntity('societe').")";
$sqlSup .= $db->order('s.nom');
$resqlSup = $db->query($sqlSup);
if ($resqlSup) {
	while ($objSup = $db->fetch_object($resqlSup)) {
		$supplierOptions[(int) $objSup->rowid] = $objSup->nom;
	}
	$db->free($resqlSup);
}
print '<td class="liste_titre">'.$form->selectarray('fk_soc', $supplierOptions, (string) $socid, 1, 0, 0, 'maxwidth200').'</td>';
print '<td class="liste_titre center nowrap">'.$form->selectDate($dateFrom, 'date_from', 0, 0, 1, '', 1, 0).' - '.$form->selectDate($dateTo, 'date_to', 0, 0, 1, '', 1, 0).'</td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', $statusOptions, (string) $status, 1, 0, 0, 'maxwidth150', 0, '', 0, 0, '', 0, 0).'</td>';
print '<td class="liste_titre center maxwidthsearch">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search" value="x"><span class="fa fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter" value="x"><span class="fa fa-remove"></span></button>';
print '</td>';
print '</tr>';
print '</table>';
print '</form>';
print '</div>';

// ---------------------------------------------------------------- query
$sql = "SELECT o.rowid, o.ref, o.date_creation, o.total_ht, o.total_ttc, o.fk_statut, o.fk_soc";
$sql .= ", s.nom as supplier";
$sql .= ", (SELECT COUNT(*) FROM ".$db->prefix()."commande_fournisseurdet d WHERE d.fk_commande = o.rowid) nb_lines";
$sql .= ", (SELECT COALESCE(SUM(d.qty),0) FROM ".$db->prefix()."commande_fournisseurdet d WHERE d.fk_commande = o.rowid) ordered_qty";
$sql .= ", (SELECT COALESCE(SUM(rb.qty),0) FROM ".$db->prefix()."receptiondet_batch rb WHERE rb.fk_element = o.rowid) received_qty";
$sql .= " FROM ".$db->prefix()."commande_fournisseur as o";
$sql .= " INNER JOIN ".$db->prefix()."societe as s ON s.rowid = o.fk_soc";
$sql .= " WHERE o.entity IN (".getEntity('societe').")";
if ($socid > 0) {
	$sql .= " AND o.fk_soc = ".((int) $socid);
}
if ($status >= 0 && $status !== -1) {
	$sql .= " AND o.fk_statut = ".((int) $status);
}
if ($dateFrom !== '') {
	$sql .= " AND o.date_creation >= '".$db->idate($db->jdate($dateFrom))." 00:00:00'";
}
if ($dateTo !== '') {
	$sql .= " AND o.date_creation <= '".$db->idate($db->jdate($dateTo))." 23:59:59'";
}
$sql .= $db->order('o.rowid', 'DESC');
$sql .= $db->plimit(100);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	exit;
}

$rows = array();
while ($o = $db->fetch_object($resql)) {
	$rows[] = $o;
}
$db->free($resql);

print '<div class="div-table-responsive-no-min">';
print '<table class="liste">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('PharmacyOrderRef').'</th>';
print '<th>'.$langs->trans('Supplier').'</th>';
print '<th class="center">'.$langs->trans('Date').'</th>';
print '<th class="right">'.$langs->trans('AmountHT').'</th>';
print '<th class="center">'.$langs->trans('PharmacyDispatchLines').'</th>';
print '<th class="center">'.$langs->trans('PharmacyPurchaseProgress').'</th>';
print '<th class="center">'.$langs->trans('Status').'</th>';
print '<th class="center">&nbsp;</th>';
print '</tr>';

if (!$rows) {
	print '<tr><td colspan="8"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}

foreach ($rows as $row) {
	$ordered = (float) $row->ordered_qty;
	$received = (float) $row->received_qty;
	$percent = $ordered > 0 ? min(100, round($received / $ordered * 100)) : 0;
	$canReceive = in_array((int) $row->fk_statut, array(3, 4), true) && $user->hasRight('pharmacy', 'dispatch');
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag((string) $row->ref).'</td>';
	print '<td>'.dol_escape_htmltag((string) $row->supplier).'</td>';
	print '<td class="center">'.dol_print_date($db->jdate($row->date_creation), 'day').'</td>';
	print '<td class="right">'.price((float) $row->total_ht).'</td>';
	print '<td class="center">'.(int) $row->nb_lines.'</td>';
	print '<td class="center">';
	if ($percent > 0) {
		print '<span class="'.($percent >= 100 ? 'ok' : 'opacitymedium').'">'.(int) $percent.'%</span>';
	} else {
		print '<span class="opacitymedium">0%</span>';
	}
	print '</td>';
	$statusKey = isset($statusOptions[(int) $row->fk_statut]) ? (int) $row->fk_statut : -1;
	print '<td class="center">'.dol_escape_htmltag((string) $statusOptions[$statusKey]).'</td>';
	print '<td class="center">';
	if ($canReceive) {
		print '<a href="'.dol_buildpath('/pharmacy/dispatch.php', 1).'?id='.((int) $row->rowid).'">'.$langs->trans('PharmacyDispatchReceive').'</a>';
	}
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

llxFooter();
$db->close();
