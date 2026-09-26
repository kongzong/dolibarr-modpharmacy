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
 * \file    htdocs/custom/pharmacy/lib/pharmacy.lib.php
 * \ingroup pharmacy
 * \brief   Shared helpers: dispense status labels, warehouse options,
 *          lists by prescription / patient. Audit goes through modPatient's
 *          patient_audit() with PHARMACY_* actions (spec §5.4).
 */

dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/prescription/lib/prescription.lib.php');

/** Dispense status values (spec §3.4) */
define('PHARMACY_STATUS_PENDING', 0);
define('PHARMACY_STATUS_DISPENSED', 1);
define('PHARMACY_STATUS_RETURNED', 9);

/**
 * @param	int		$status		Status value
 * @return	string				Translated label
 */
function pharmacy_status_label($status)
{
	global $langs;
	$langs->load('pharmacy@pharmacy');
	switch ((int) $status) {
		case PHARMACY_STATUS_DISPENSED:
			return $langs->trans('PharmacyStatusDispensed');
		case PHARMACY_STATUS_RETURNED:
			return $langs->trans('PharmacyStatusReturned');
		default:
			return $langs->trans('PharmacyStatusPending');
	}
}

/**
 * @param	int		$status		Status value
 * @return	string				Badge HTML
 */
function pharmacy_status_badge($status)
{
	$cls = array(PHARMACY_STATUS_PENDING => 'badge-status0', PHARMACY_STATUS_DISPENSED => 'badge-status4',
		PHARMACY_STATUS_RETURNED => 'badge-status9');
	$status = (int) $status;
	return '<span class="badge '.(isset($cls[$status]) ? $cls[$status] : 'badge-status0').'">'.pharmacy_status_label($status).'</span>';
}

/**
 * Warehouses the user can dispense from (open ones of this entity).
 *
 * @param	DoliDB	$db			Database handler
 * @return	array<int,string>	rowid => label, empty when the stock module has none
 */
function pharmacy_warehouse_options($db)
{
	$out = array();
	$sql = "SELECT w.rowid, w.lieu, w.ref FROM ".$db->prefix()."entrepot as w";
	$sql .= " WHERE w.entity IN (".getEntity('stock').") AND w.statut = 1";
	$sql .= $db->order('lieu', 'ASC');
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[(int) $o->rowid] = trim((string) $o->lieu.(empty($o->ref) ? '' : ' - '.$o->ref));
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Non-returned dispense sheets of one prescription (V0.1: at most one can
 * exist; the create() gate refuses a second while this returns a row).
 *
 * @param	DoliDB	$db				Database handler
 * @param	int		$fkPrescription	Prescription rowid
 * @return	array<int,object>			Rows (ref, status, date) newest first
 */
function pharmacy_list_by_prescription($db, $fkPrescription)
{
	$out = array();
	$sql = "SELECT d.rowid, d.ref, d.status, d.date_dispense, d.date_creation, d.fk_warehouse, w.ref as warehouse_label";
	$sql .= " FROM ".$db->prefix()."pharmacy_dispense as d";
	$sql .= " LEFT JOIN ".$db->prefix()."entrepot as w ON w.rowid = d.fk_warehouse";
	$sql .= " WHERE d.fk_prescription = ".((int) $fkPrescription)." AND d.status <> ".PHARMACY_STATUS_RETURNED;
	$sql .= $db->order('d.rowid', 'DESC');
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}

/**
 * Dispense timeline of one patient (patient card tab, hook page).
 *
 * @param	DoliDB	$db			Database handler
 * @param	int		$fkPatient	Patient profile rowid
 * @param	int		$limit		Max rows
 * @return	array<int,object>	Rows (prescription ref join included)
 */
function pharmacy_list_by_patient($db, $fkPatient, $limit = 50)
{
	$out = array();
	$sql = "SELECT d.rowid, d.ref, d.fk_prescription, d.status, d.date_dispense, d.date_creation, p.ref as presc_ref, p.fk_medrecord, m.ref as medrecord_ref";
	$sql .= " FROM ".$db->prefix()."pharmacy_dispense as d";
	$sql .= " INNER JOIN ".$db->prefix()."prescription as p ON p.rowid = d.fk_prescription";
	// Owner visit via the prescription (design §5.1): pharmacy has no direct
	// fk_medrecord; the prescription is the bridge (dispense requires one).
	$sql .= " LEFT JOIN ".$db->prefix()."medrecord as m ON m.rowid = p.fk_medrecord";
	$sql .= " WHERE d.fk_patient = ".((int) $fkPatient);
	$sql .= $db->order('d.rowid', 'DESC');
	$sql .= $db->plimit((int) $limit);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$out[] = $o;
		}
		$db->free($resql);
	}
	return $out;
}
