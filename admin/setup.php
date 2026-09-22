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
 * \file    htdocs/custom/pharmacy/admin/setup.php
 * \ingroup pharmacy
 * \brief   Pharmacy module setup: warehouse default, expiry window,
 *          WeCom push switch (off by default, red line §5.7).
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/pharmacy/lib/pharmacy.lib.php');

// clang-format off
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
// clang-format on

$langs->loadLangs(array("admin", "pharmacy@pharmacy"));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

$constants = array('PHARMACY_WAREHOUSE_ID', 'PHARMACY_EXPIRY_DAYS', 'PHARMACY_EXPIRY_NOTIFY', 'PHARMACY_EXPIRY_NOTIFY_USER');

if ($action == 'save' && $user->admin) {
	foreach ($constants as $k) {
		$value = GETPOST($k, 'alphanohtml');
		if ($k === 'PHARMACY_EXPIRY_NOTIFY' && $value !== '1' && $value !== '0') {
			$value = '0';
		}
		dolibarr_set_const($db, $k, $value, 'chaine', 0, '', $conf->entity);
	}
	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	header('Location: '.dol_buildpath('/pharmacy/admin/setup.php', 1));
	exit;
}

llxHeader('', $langs->trans("PharmacySetup"));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans("PharmacySetup"), $linkback, 'fa-pills');

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefield">'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

// Default warehouse (empty = pick at dispense time, spec §8.1)
$warehouses = pharmacy_warehouse_options($db);
print '<tr class="oddeven"><td>'.$langs->trans("PharmacyWarehouse").'</td><td>';
print '<select class="flat" name="PHARMACY_WAREHOUSE_ID"><option value=""'.(getDolGlobalString('PHARMACY_WAREHOUSE_ID') === '' ? ' selected' : '').'>'.$langs->trans("None").'</option>';
foreach ($warehouses as $wid => $wlabel) {
	print '<option value="'.$wid.'"'.(getDolGlobalString('PHARMACY_WAREHOUSE_ID') == $wid ? ' selected' : '').'>'.dol_escape_htmltag($wlabel).'</option>';
}
print '</select></td></tr>';

// Expiry window
print '<tr class="oddeven"><td>'.$langs->trans("PharmacyExpiryWindow").'</td><td>';
print '<input class="flat width75" type="number" min="1" name="PHARMACY_EXPIRY_DAYS" value="'.dol_escape_htmltag(getDolGlobalString('PHARMACY_EXPIRY_DAYS', '90')).'"></td></tr>';

// WeCom push (explicit maintainer decision, red line §5.7)
print '<tr class="oddeven"><td>'.$langs->trans("PharmacyExpiryNotify");
print '<br><span class="opacitymedium small">'.dol_escape_htmltag($langs->trans("PharmacyExpiryNotifyHelp")).'</span></td><td>';
print '<input type="checkbox" name="PHARMACY_EXPIRY_NOTIFY" value="1"'.(getDolGlobalString('PHARMACY_EXPIRY_NOTIFY') === '1' ? ' checked' : '').'>';
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("PharmacyExpiryNotifyUser").'</td><td>';
print '<input class="flat width200" type="text" name="PHARMACY_EXPIRY_NOTIFY_USER" value="'.dol_escape_htmltag(getDolGlobalString('PHARMACY_EXPIRY_NOTIFY_USER', '')).'">';
print '</td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

llxFooter();
$db->close();
