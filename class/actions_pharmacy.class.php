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
 * \file    htdocs/custom/pharmacy/class/actions_pharmacy.class.php
 * \ingroup pharmacy
 * \brief   Hook injection into the prescription card: "Dispense" button
 *          (issued prescriptions, pharmacy write) + the dispense sheets of
 *          this prescription with their status. Naming rule
 *          actions_<module>.class.php -> ActionsPharmacy (DEV.md §二.11).
 */

dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/dispense.class.php');

/**
 * Class ActionsPharmacy
 */
class ActionsPharmacy
{
	/** @var DoliDB */
	public $db;

	/** @var string Last error */
	public $error = '';

	/** @var array<string,string> */
	public $errors = array();

	/** @var string Hook output printed by the caller */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Hook: prescriptionCard (modPrescription card.php, after tabsAction).
	 *
	 * @param	array<mixed>	$parameters	Hook parameters (object = prescription)
	 * @param	object			$object		PrescriptionSheet
	 * @param	string			$action		Current action
	 * @return	int							0 = print via resPrint, 1 = replaced
	 */
	public function prescriptionCard($parameters, $object, $action)
	{
		global $langs, $user;

		if (!isModEnabled('pharmacy')) {
			return 0;
		}
		if (!($object instanceof PrescriptionSheet) || $object->id <= 0) {
			return 0;
		}

		$out = '';
		$canWrite = $user->hasRight('pharmacy', 'write');

		// Dispense button on a signed prescription without a live sheet
		$sheets = pharmacy_list_by_prescription($this->db, $object->id);
		if ((int) $object->status === PRESCRIPTION_STATUS_ISSUED && $canWrite && empty($sheets)) {
			$out .= '<div class="tabsAction">';
			$out .= dolGetButtonAction($langs->trans("PharmacyCreateFromPrescription"), '', 'default', dol_buildpath('/pharmacy/card.php', 1).'?action=create&fk_prescription='.$object->id.'&token='.newToken(), '', 1);
			$out .= '</div>';
		}

		if (!empty($sheets)) {
			$out .= '<div class="div-table-responsive-no-min" style="margin-top:8px;"><table class="noborder centpercent">';
			$out .= '<tr class="liste_titre"><th>'.$langs->trans("PharmacyRef").'</th><th>'.$langs->trans("PharmacyWarehouse").'</th><th>'.$langs->trans("DateCreation").'</th><th class="center">'.$langs->trans("Status").'</th></tr>';
			foreach ($sheets as $s) {
				$out .= '<tr class="oddeven">';
				$out .= '<td><a href="'.dol_buildpath('/pharmacy/card.php', 1).'?id='.((int) $s->rowid).'">'.dol_escape_htmltag($s->ref).'</a></td>';
				$out .= '<td>'.dol_escape_htmltag((string) $s->warehouse_label).'</td>';
				$out .= '<td>'.dol_print_date($this->db->jdate($s->date_creation), 'dayhour').'</td>';
				$out .= '<td class="center">'.pharmacy_status_badge($s->status).'</td>';
				$out .= '</tr>';
			}
			$out .= '</table></div>';
		}

		$this->resprints = $out;
		return 0;
	}
}
