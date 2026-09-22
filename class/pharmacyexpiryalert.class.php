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
 * \file    htdocs/custom/pharmacy/class/pharmacyexpiryalert.class.php
 * \ingroup pharmacy
 * \brief   Near-expiry / expired batch scan (spec §3.5 / §3.7). The same
 *          collect() feeds the expiry page and the daily cron; the cron
 *          only pushes a digest through modWeCom when
 *          PHARMACY_EXPIRY_NOTIFY=1 (off by default, outbound red line
 *          §5.7). Stock data is read natively: product_lot + product_batch.
 */

dol_include_once('/pharmacy/lib/pharmacy.lib.php');

/**
 * Class PharmacyExpiryAlert
 */
class PharmacyExpiryAlert
{
	/** @var string */
	public $error = '';

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Batches with qty > 0 whose sell-by (preferred) or eat-by date falls
	 * into the window, oldest first. Expired batches included.
	 *
	 * @param	int		$windowDays		Alert window in days
	 * @param	int		$warehouseId	0 = all open warehouses
	 * @return	array<int,array{product:string,batch:string,sellby:int,eatby:int,qty:float,warehouse:string}>|null
	 */
	public function collect($windowDays, $warehouseId = 0)
	{
		$windowDays = max(0, (int) $windowDays);
		$limitTs = dol_time_plus_duree(dol_now(), $windowDays, 'd');

		$sql = "SELECT prod.label as product_label, prod.ref as product_ref, pb.batch, pl.sellby, pl.eatby, pb.qty,";
		$sql .= " w.lieu as warehouse_lieu, w.label as warehouse_label";
		$sql .= " FROM ".$this->db->prefix()."product_lot as pl";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_batch as pb ON pb.batch = pl.batch";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_stock as ps ON ps.rowid = pb.fk_product_stock";
		$sql .= " INNER JOIN ".$this->db->prefix()."product as prod ON prod.rowid = ps.fk_product";
		$sql .= " INNER JOIN ".$this->db->prefix()."entrepot as w ON w.rowid = ps.fk_entrepot AND w.statut = 1";
		$sql .= " WHERE ps.fk_product = pl.fk_product AND pb.qty > 0";
		$sql .= " AND (pl.sellby > 0 AND pl.sellby <= ".$limitTs." OR pl.eatby > 0 AND pl.eatby <= ".$limitTs.")";
		if ($warehouseId > 0) {
			$sql .= " AND ps.fk_entrepot = ".((int) $warehouseId);
		}
		$sql .= $this->db->order('pl.sellby', 'ASC');

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($o = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'product' => trim($o->product_label.' ['.$o->product_ref.']'),
				'batch' => $o->batch,
				'sellby' => $o->sellby ? (int) $o->sellby : 0,
				'eatby' => $o->eatby ? (int) $o->eatby : 0,
				'qty' => (float) $o->qty,
				'warehouse' => trim((string) $o->warehouse_lieu.(empty($o->warehouse_label) ? '' : ' - '.$o->warehouse_label)),
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * Cron entry (descriptor cronjobs, disabled by default). Scans the
	 * window and pushes a product/batch digest (no patient data, §7.G)
	 * through modWeCom only when PHARMACY_EXPIRY_NOTIFY=1.
	 *
	 * @return	int		1 ok, <=0 error
	 */
	public function doScheduledJob()
	{
		global $conf, $user;

		if (getDolGlobalString('PHARMACY_EXPIRY_NOTIFY') !== '1') {
			return 1;
		}
		if (!isModEnabled('wecom')) {
			dol_syslog('PharmacyExpiryAlert: PHARMACY_EXPIRY_NOTIFY is on but modWeCom is not enabled', LOG_WARNING);
			return 1;
		}

		$rows = $this->collect(getDolGlobalInt('PHARMACY_EXPIRY_DAYS', 90), 0);
		if ($rows === null) {
			return -1;
		}
		if (empty($rows)) {
			return 1;
		}

		$recipients = preg_split('/[\s,;]+/', (string) getDolGlobalString('PHARMACY_EXPIRY_NOTIFY_USER'));
		$recipients = array_filter(array_map('trim', $recipients));
		if (empty($recipients)) {
			dol_syslog('PharmacyExpiryAlert: no recipient configured', LOG_WARNING);
			return 1;
		}

		$expired = 0;
		$lines = array();
		foreach (array_slice($rows, 0, 20) as $r) {
			$isExpired = ($r['sellby'] > 0 && $r['sellby'] < dol_now());
			$expired += $isExpired ? 1 : 0;
			$lines[] = $r['product'].' '.$r['batch'].' '.dol_print_date($r['sellby'] ?: $r['eatby'], 'dayformatter').' '.price2num($r['qty'], 'MS').($isExpired ? ' ['.$this->db->escape('expired').']' : '');
		}
		$text = "药品效期预警: 共 ".count($rows)." 条（过期 ".$expired."），前 20 条:\n".implode("\n", $lines);

		dol_include_once('/wecom/lib/wecom.lib.php');
		if (function_exists('wecom_send_user_text')) {
			foreach ($recipients as $login) {
				// Internal alert to configured staff; content carries no patient data
				wecom_send_user_text($this->db, $login, $text);
			}
		}
		return 1;
	}
}
