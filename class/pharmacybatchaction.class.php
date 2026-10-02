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
 * \file    htdocs/custom/pharmacy/class/pharmacybatchaction.class.php
 * \ingroup pharmacy
 * \brief   Expiry disposition actions on batches (V0.1): SCRAP (write the
 *          whole batch off through the same MouvementStock::livraison()
 *          path as dispensing) and BLOCK / UNBLOCK (sales hold, honoured
 *          by Dispense::allocateFefo()). Every action appends one row to
 *          llx_pharmacy_expiry_action; the code offers no UPDATE and no
 *          DELETE path (GSP trace). A batch's effective state is the op of
 *          its highest-rowid record.
 */

/**
 * Class PharmacyBatchAction
 */
class PharmacyBatchAction
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
	 * LEFT JOIN clause exposing the latest action of a batch as <alias>.op
	 * (NULL when the batch was never handled). Keyed on (fk_product, batch)
	 * only: BLOCK/UNBLOCK are warehouse-independent (a hold applies to the
	 * whole batch no matter where it is stored), entity-scoped.
	 *
	 * @param	string	$productExpr	SQL expression for fk_product (e.g. "ps.fk_product")
	 * @param	string	$batchExpr		SQL expression for batch (e.g. "pb.batch")
	 * @param	string	$alias			Alias for the joined action table
	 * @return	string
	 */
	public static function latestOpJoin($productExpr, $batchExpr, $alias = 'ea')
	{
		global $db;

		$table = $db->prefix().'pharmacy_expiry_action';
		$join = " LEFT JOIN ".$table." as ".$alias." ON ".$alias.".fk_product = ".$productExpr." AND ".$alias.".batch = ".$batchExpr;
		$join .= " AND ".$alias.".rowid = (SELECT MAX(e2.rowid) FROM ".$table." as e2";
		$join .= " WHERE e2.fk_product = ".$alias.".fk_product AND e2.batch = ".$alias.".batch AND e2.entity IN (".getEntity('product')."))";
		return $join;
	}

	/**
	 * Append a BLOCK or UNBLOCK record (sales hold). Warehouse-independent.
	 *
	 * @param	User	$user			Acting user (pharmacy dispose permission)
	 * @param	int		$fkProduct		Product id
	 * @param	string	$batch			Batch number
	 * @param	string	$op				'BLOCK' or 'UNBLOCK'
	 * @param	string	$note			Free note (optional)
	 * @return	int						1 ok, -1 error (this->error)
	 */
	public function setBlock(User $user, $fkProduct, $batch, $op, $note = '')
	{
		return $this->appendAction($user, $fkProduct, $batch, $op, 0, 0, $note);
	}

	/**
	 * Write a whole batch off (SCRAP): reverse the batch's remaining stock
	 * through MouvementStock::livraison() — the same path dispensing uses —
	 * then append the trace row. Refuses when the batch no longer holds the
	 * expected quantity (stale screen).
	 *
	 * @param	User	$user			Acting user (pharmacy dispose permission)
	 * @param	int		$fkProduct		Product id
	 * @param	string	$batch			Batch number
	 * @param	int		$warehouseId	Warehouse to scrap from
	 * @param	float	$expectedQty	Quantity shown on screen (whole batch); re-checked under lock
	 * @param	string	$note			Free note (optional)
	 * @return	int						1 ok, -2 refused (stale qty), -1 error
	 */
	public function scrap(User $user, $fkProduct, $batch, $warehouseId, $expectedQty, $note = '')
	{
		$this->error = '';
		$fkProduct = (int) $fkProduct;
		$warehouseId = (int) $warehouseId;
		$batch = trim((string) $batch);

		if ($fkProduct <= 0 || $warehouseId <= 0 || $batch === '') {
			$this->error = 'PharmacyExpiryErrInvalid';
			return -1;
		}

		$this->db->begin();
		try {
			// Lock the batch row and re-check the quantity under lock.
			$sql = "SELECT pb.rowid, pb.qty, pl.sellby, pl.eatby";
			$sql .= " FROM ".$this->db->prefix()."product_batch as pb";
			$sql .= " INNER JOIN ".$this->db->prefix()."product_stock as ps ON ps.rowid = pb.fk_product_stock";
			$sql .= " LEFT JOIN ".$this->db->prefix()."product_lot as pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch AND pl.entity = ".(int) $this->db->sanitize(getEntity('product'));
			$sql .= " WHERE ps.fk_product = ".$fkProduct." AND ps.fk_entrepot = ".$warehouseId." AND pb.batch = '".$this->db->escape($batch)."'";
			$sql .= " FOR UPDATE";
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new RuntimeException($this->db->lasterror());
			}
			$obj = $this->db->fetch_object($resql);
			$this->db->free($resql);
			if (!$obj) {
				$this->db->rollback();
				$this->error = 'PharmacyExpiryErrBatchGone';
				return -2;
			}
			$qty = (float) $obj->qty;
			if ($qty <= 0 || abs($qty - (float) $expectedQty) > 0.0000001) {
				// Screen was stale: someone dispensed or moved stock meanwhile.
				$this->db->rollback();
				$this->error = 'PharmacyExpiryErrQtyChanged';
				return -2;
			}
			if ($obj->sellby || $obj->eatby) {
				$sellby = $obj->sellby ? $this->db->jdate($obj->sellby) : 0;
				$eatby = $obj->eatby ? $this->db->jdate($obj->eatby) : 0;
			} else {
				$sellby = 0;
				$eatby = 0;
			}

			// Stock movement (same path as dispensing): this updates
			// product_batch.qty AND product_stock.reel in one step.
			require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
			$movement = new MouvementStock($this->db);
			$result = $movement->livraison($user, $fkProduct, $warehouseId, $qty, 0, 'Expiry scrap batch '.$batch, dol_now(), $eatby, $sellby, $batch);
			if ($result < 0) {
				throw new RuntimeException('stock movement failed: '.$movement->error);
			}

			$res = $this->appendAction($user, $fkProduct, $batch, 'SCRAP', $warehouseId, $qty, $note, true);
			if ($res < 0) {
				throw new RuntimeException($this->error);
			}

			$this->db->commit();
		} catch (Throwable $e) {
			while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
				$this->db->rollback();
			}
			$this->error = $e->getMessage();
			dol_syslog('PharmacyBatchAction::scrap failed: '.$e->getMessage(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Append one trace row (append-only; called inside or outside a tx).
	 *
	 * @param	User	$user			Acting user
	 * @param	int		$fkProduct		Product id
	 * @param	string	$batch			Batch number
	 * @param	string	$op				SCRAP | BLOCK | UNBLOCK
	 * @param	int		$warehouseId	Warehouse (0 for BLOCK/UNBLOCK)
	 * @param	float	$qty			Scrapped quantity (SCRAP only)
	 * @param	string	$note			Free note
	 * @param	bool	$inTransaction	True when caller manages the transaction
	 * @return	int						1 ok, -1 error
	 */
	private function appendAction(User $user, $fkProduct, $batch, $op, $warehouseId, $qty, $note, $inTransaction = false)
	{
		global $conf;

		$this->error = '';
		if (!in_array($op, array('SCRAP', 'BLOCK', 'UNBLOCK'), true)) {
			$this->error = 'PharmacyExpiryErrInvalid';
			return -1;
		}
		if (!$inTransaction) {
			$this->db->begin();
		}
		$sql = "INSERT INTO ".$this->db->prefix()."pharmacy_expiry_action";
		$sql .= " (entity, fk_product, batch, fk_entrepot, op, qty, note, fk_user_creat, date_creation)";
		$sql .= " VALUES (".((int) $conf->entity).", ".((int) $fkProduct).", '".$this->db->escape(trim((string) $batch))."', ".((int) $warehouseId).", '".$op."', ".price2num($qty, 'MS').", ".($note !== '' ? "'".$this->db->escape(dol_substr($note, 0, 255))."'" : "NULL").", ".((int) $user->id).", '".$this->db->idate(dol_now())."')";
		$resql = $this->db->query($sql);
		if (!$resql) {
			if (!$inTransaction) {
				$this->db->rollback();
			}
			$this->error = $this->db->lasterror();
			return -1;
		}
		if (!$inTransaction) {
			$this->db->commit();
		}
		return 1;
	}

	/**
	 * Latest action op of a batch ('' when never handled). Warehouse
	 * independent, entity scoped.
	 *
	 * @param	int		$fkProduct	Product id
	 * @param	string	$batch		Batch number
	 * @return	string
	 */
	public function lastOp($fkProduct, $batch)
	{
		$sql = "SELECT op FROM ".$this->db->prefix()."pharmacy_expiry_action";
		$sql .= " WHERE fk_product = ".((int) $fkProduct)." AND batch = '".$this->db->escape((string) $batch)."' AND entity IN (".getEntity('product').")";
		$sql .= " ORDER BY rowid DESC LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return $obj ? (string) $obj->op : '';
	}
}
