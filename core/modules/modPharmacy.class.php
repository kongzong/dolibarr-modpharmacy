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
 *  \defgroup   pharmacy     Module Pharmacy
 *  \brief      Dispensing of signed prescriptions with FEFO batch picking and expiry alerts.
 *
 *  \file       htdocs/custom/pharmacy/core/modules/modPharmacy.class.php
 *  \ingroup    pharmacy
 *  \brief      Description and activation file for module Pharmacy
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module Pharmacy
 */
class modPharmacy extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Healthcare module family: 501600 + 10 per module
		$this->numero = 501630;

		$this->rights_class = 'pharmacy';

		$this->family = "crm";
		$this->module_position = '94';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "ModulePharmacyDesc";
		$this->descriptionlong = "ModulePharmacyDescLong";

		$this->editor_name = 'modPharmacy';
		$this->editor_url = 'https://github.com/kongzong/dolibarr-modpharmacy';

		$this->version = '0.1.0';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'fa-pills';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			// Scalar 1 so commonGenerateDocument() scans /pharmacy/ for
			// core/modules/pharmacy/doc/pdf_*.modules.php (chinadoc probe: array form breaks dol_buildpath)
			'models' => 1,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			// Inject the dispense button + dispense list into the prescription card
			'hooks' => array('prescriptioncard'),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array("/pharmacy/temp");

		$this->config_page_url = array("setup.php@pharmacy");

		$this->hidden = getDolGlobalInt('MODULE_PHARMACY_DISABLED');
		$this->depends = array('modPatient', 'modPrescription', 'modProduct', 'modStock', 'modProductBatch');
		$this->requiredby = array('modClinicPay');
		$this->conflictwith = array();

		$this->langfiles = array("pharmacy@pharmacy");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Spec §3.1 constants
		$this->const = array(
			0 => array('PHARMACY_WAREHOUSE_ID', 'chaine', '', 'Default warehouse id for dispensing (empty = pick at dispense time)', 0, 'current', 1),
			1 => array('PHARMACY_EXPIRY_DAYS', 'chaine', '90', 'Expiry alert window in days', 0, 'current', 1),
			2 => array('PHARMACY_EXPIRY_NOTIFY', 'chaine', '0', 'Send the expiry alert digest through modWeCom (0 off by default; enabling is an explicit maintainer decision)', 0, 'current', 1),
			// 采购补货走原生供应商订单：没有编号模块时核心只会给 '(PROVn)' 占位单号
			3 => array('COMMANDE_SUPPLIER_ADDON_NUMBER', 'chaine', 'mod_commande_fournisseur_muguet', 'Numbering module for supplier orders (purchase replenishment)', 0, 'current', 1),
		);

		if (!isModEnabled("pharmacy")) {
			$conf->pharmacy = new stdClass();
			$conf->pharmacy->enabled = 0;
		}

		// Patient card tab: dispensing / return history of this patient
		// (spec §3.6). Reuses /pharmacy/patient_tab.php.
		$this->tabs = array();
		$this->tabs[] = array('data' => 'patient:+dispensing:PharmacyDispenseList:pharmacy@pharmacy:$user->hasRight(\'pharmacy\', \'read\'):/pharmacy/patient_tab.php?id=__ID__');

		$this->boxes = array();

		// Expiry alert cron, disabled by default (spec §3.7; pushing messages
		// to real users is an explicit maintainer decision, red line §5.7)
		$this->cronjobs = array(
			0 => array(
				'label' => 'PharmacyExpiryAlert',
				'jobtype' => 'method',
				'class' => '/pharmacy/class/pharmacyexpiryalert.class.php',
				'objectname' => 'PharmacyExpiryAlert',
				'method' => 'doScheduledJob',
				'parameters' => '',
				'comment' => 'Daily scan of near-expiry and expired batches',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("pharmacy")',
				'priority' => 60,
			),
		);

		// Permissions: one-level form, ids 50163011..81 (spec §9; 61=dispose
		// added 2026-10-02 for the expiry disposition actions; 71/81=purchase
		// and dispatch added 2026-10-03 for the supplier replenishment chain)
		$this->rights = array();
		$r = 0;
		$perms = array(11 => 'read', 21 => 'write', 31 => 'dispense', 41 => 'return', 51 => 'admin', 61 => 'dispose', 71 => 'purchase', 81 => 'dispatch', 91 => 'stock_count');
		foreach ($perms as $suffix => $code) {
			$this->rights[$r][0] = $this->numero . $suffix;
			$this->rights[$r][1] = 'PharmacyPerm'.ucfirst($code);
			$this->rights[$r][4] = $code;
			$r++;
		}

		// Left menu under the shared "Clinic" top menu owned by modPatient
		$this->menu = array();
		$r = 0;

		// Group: pharmacy operations (dispensing / retail / purchase / expiry)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'ClinicMenuPharmacy',
			'mainmenu' => 'clinic',
			'leftmenu' => 'clinic_pharmacy',
			'prefix' => img_picto('', 'fa-pills_fas_#fb8c00', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/list.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyDispenseList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_list',
			'prefix' => img_picto('', 'fa-pills_fas_#fb8c00', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/list.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyExpiry',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_expiry',
			'prefix' => img_picto('', 'fa-hourglass-half_fas_#f9a825', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/expiry.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyDecant',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_decant',
			'prefix' => img_picto('', 'fa-box_fas_#8d6e63', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/decant.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyPurchase',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_purchase',
			'prefix' => img_picto('', 'fa-truck_fas_#009688', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/purchase.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "purchase")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyPurchaseList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_purchase_list',
			'prefix' => img_picto('', 'fa-file-invoice_fas_#546e7a', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/purchase_list.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1308,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "purchase") || $user->hasRight("pharmacy", "dispatch")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyDispatch',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_dispatch',
			'prefix' => img_picto('', 'fa-box-open_fas_#009688', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/dispatch.php?id=__ID__',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "dispatch")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyRetail',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_retail',
			'prefix' => img_picto('', 'fa-cash-register_fas_#00897b', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/retail.php',
			'langs' => 'pharmacy@pharmacy',
			// Fixed instead of 1300 + $r: the automatic counter collided with
			// purchase_list (both landed on 1308, so their order was undefined).
			'position' => 1306,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "write")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyExpiryActionReport',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_expiry_action_report',
			'prefix' => img_picto('', 'fa-clipboard-list_fas_#c62828', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/report_expiry_action.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyTraceBatch',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_trace_batch',
			'prefix' => img_picto('', 'fa-search-plus_fas_#00897b', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/trace_batch.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1300 + $r,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyStockCountList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_stock_count',
			'prefix' => img_picto('', 'fa-clipboard-check_fas_#1e88e5', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/stock_count.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1311,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_pharmacy',
			'type' => 'left',
			'titre' => 'PharmacyRecallList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_recall',
			'prefix' => img_picto('', 'fa-bullhorn_fas_#c62828', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/recall.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1320,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_report',
			'type' => 'left',
			'titre' => 'PharmacyReport',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_report',
			'prefix' => img_picto('', 'fa-chart-line_fas_#00897b', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/report.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1503,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic,fk_leftmenu=clinic_report',
			'type' => 'left',
			'titre' => 'PharmacyReportStock',
			'mainmenu' => 'clinic',
			'leftmenu' => 'pharmacy_report_stock',
			'prefix' => img_picto('', 'fa-boxes_fas_#00897b', 'class="paddingright pictofixedwidth"'),
			'url' => '/pharmacy/report_stock.php',
			'langs' => 'pharmacy@pharmacy',
			'position' => 1504,
			'enabled' => 'isModEnabled("pharmacy")',
			'perms' => '$user->hasRight("pharmacy", "read")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/pharmacy/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Removes constants, permissions, menus, tabs and hooks only.
	 *	Dispense sheets, lines, the sequence table and generated PDFs are
	 *	kept (spec §5.4).
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
