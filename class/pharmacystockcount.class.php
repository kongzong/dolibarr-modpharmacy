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
 * \file    htdocs/custom/pharmacy/class/pharmacystockcount.class.php
 * \ingroup pharmacy
 * \brief   Stock count sheets: snapshot the book quantity per batch, record
 *          what was physically counted, post the difference.
 *
 * Rules that shaped this class:
 * - The book quantity is a SNAPSHOT taken when the sheet is generated. Stock may
 *   move afterwards; the difference printed on the sheet stays meaningful.
 * - A counted quantity may be zero, but NULL means "not counted yet" and
 *   posting refuses the sheet while any line is NULL (a GSP audit must not
 *   accept a half-counted sheet).
 * - Posting adjusts BOTH llx_product_batch.qty and llx_product_stock.reel and
 *   writes one llx_stock_mouvement row per adjusted line, because the batch
 *   rule (sum of batch quantities == reel) is what the dispenser relies on to
 *   tell "not enough stock" from "batch rows missing".
 * - Posting is refused when the batches of a touched warehouse do not add up to
 *   the stock rows, i.e. when the data was already inconsistent.
 * - The poster should not be the counter (GSP): post() refuses it unless the
 *   caller passes $forceSameUser.
 *
 * SQL is assembled with sprintf() on purpose: the concatenation style used
 * elsewhere in this module hides a missing parenthesis around a cast, which
 * silently turns an integer into part of a string.
 */
class PharmacyStockCount
{
	/** Sheet is still being counted. */
	const STATUS_DRAFT = 0;
	/** Sheet is posted: adjustments written, sheet read-only. */
	const STATUS_POSTED = 1;

	/** @var DoliDB */
	private $db;
	/** @var string */
	public $error = '';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $ref = '';
	/** @var int */
	public $fk_entrepot = 0;
	/** @var string */
	public $date_count = '';
	/** @var int */
	public $status = self::STATUS_DRAFT;
	/** @var string */
	public $note = '';
	/** @var float */
	public $qty_book = 0;
	/** @var float */
	public $qty_counted = 0;
	/** @var float */
	public $qty_diff = 0;
	/** @var int */
	public $fk_user_creat = 0;
	/** @var int */
	public $fk_user_valid = 0;
	/** @var array<int,object> */
	public $lines = array();

	/**
	 * @param	DoliDB	$db		Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/* ------------------------------------------------------------------ create */

	/**
	 * Generate a sheet for one warehouse: one line per batch that currently has
	 * a quantity. Expired lots are included on purpose - they are physically
	 * still on the shelf and must be counted (and then disposed of).
	 *
	 * @param	User		$user			Author
	 * @param	int			$warehouseId	Warehouse
	 * @param	string		$countDate		Business day, Y-m-d
	 * @param	string		$note			Free note
	 * @return	int							>0 sheet id, <=0 error
	 */
	public function createFromWarehouse(User $user, $warehouseId, $countDate, $note = '')
	{
		$this->error = '';
		$warehouseId = (int) $warehouseId;
		if ($warehouseId <= 0) {
			$this->error = 'PharmacyStockCountErrWarehouse';
			return -1;
		}
		$countDate = substr((string) $countDate, 0, 10);
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $countDate)) {
			$countDate = date('Y-m-d');
		}

		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT ps.fk_product, p.ref AS product_ref, p.label AS product_label, pb.batch, pb.qty AS qty_book, pl.eatby, pl.sellby"
			." FROM %sproduct_batch AS pb"
			." INNER JOIN %sproduct_stock AS ps ON ps.rowid = pb.fk_product_stock"
			." LEFT JOIN %sproduct AS p ON p.rowid = ps.fk_product"
			." LEFT JOIN %sproduct_lot AS pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch"
			." WHERE ps.fk_entrepot = %d AND pb.qty <> 0"
			." ORDER BY p.ref, pb.batch",
			$P,
			$P,
			$P,
			$P,
			$warehouseId
		);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$batches = array();
		while ($o = $this->db->fetch_object($resql)) {
			$batches[] = $o;
		}
		$this->db->free($resql);

		if (empty($batches)) {
			$this->error = 'PharmacyStockCountErrNoStock';
			return -2;
		}

		$this->db->begin();
		try {
			$ref = $this->nextRef($countDate);
			$sql = sprintf(
				"INSERT INTO %spharmacy_stock_count (entity, ref, fk_entrepot, date_count, status, note, fk_user_creat, date_creation)"
				." VALUES (%d, '%s', %d, '%s', %d, '%s', %d, '%s')",
				$P,
				(int) $this->getEntity(),
				$this->db->escape($ref),
				$warehouseId,
				$this->db->escape($countDate),
				self::STATUS_DRAFT,
				$this->db->escape($note),
				(int) $user->id,
				$this->db->idate(dol_now())
			);
			if (!$this->db->query($sql)) {
				throw new RuntimeException($this->db->lasterror());
			}
			$countId = (int) $this->db->last_insert_id($P.'pharmacy_stock_count');

			foreach ($batches as $b) {
				$sql = sprintf(
					"INSERT INTO %spharmacy_stock_count_line (fk_count, fk_product, product_ref, product_label, batch, eatby, sellby, qty_book)"
					." VALUES (%d, %d, '%s', '%s', '%s', %s, %s, %s)",
					$P,
					$countId,
					(int) $b->fk_product,
					$this->db->escape((string) $b->product_ref),
					$this->db->escape((string) $b->product_label),
					$this->db->escape((string) $b->batch),
					empty($b->eatby) ? 'NULL' : "'".$this->db->escape($b->eatby)."'",
					empty($b->sellby) ? 'NULL' : "'".$this->db->escape($b->sellby)."'",
					$this->num((float) $b->qty_book)
				);
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}
			}
			$this->db->commit();

			$this->id = $countId;
			$this->ref = $ref;
			$this->fk_entrepot = $warehouseId;
			$this->date_count = $countDate;
			$this->status = self::STATUS_DRAFT;
			$this->note = $note;
			$this->fk_user_creat = (int) $user->id;
			// Fill the header totals right away, otherwise a freshly created
			// sheet shows 0.000 / 0.000 / 0.000 until the first save.
			$this->refreshTotals();
			$this->fetchLines();
			return $countId;
		} catch (Exception $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			return -1;
		}
	}

	/**
	 * Next sheet reference: PD-{Ymd}-{NNN}, NNN counting the sheets of that day.
	 *
	 * @param	string	$countDate	Business day, Y-m-d
	 * @return	string
	 */
	private function nextRef($countDate)
	{
		$P = $this->db->prefix();
		$day = str_replace('-', '', $countDate);
		for ($try = 0; $try < 20; $try++) {
			$sql = sprintf("SELECT COUNT(*) AS n FROM %spharmacy_stock_count WHERE ref LIKE 'PD-%s-%%'", $P, $day);
			$resql = $this->db->query($sql);
			$n = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
			if ($resql) {
				$this->db->free($resql);
			}
			$ref = 'PD-'.$day.'-'.sprintf('%03d', $n + 1 + $try);
			$sql = sprintf("SELECT COUNT(*) AS n FROM %spharmacy_stock_count WHERE ref = '%s'", $P, $this->db->escape($ref));
			$resql = $this->db->query($sql);
			$exists = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
			if ($resql) {
				$this->db->free($resql);
			}
			if ($exists === 0) {
				return $ref;
			}
		}
		return 'PD-'.$day.'-'.dol_print_date(dol_now(), 'dayhour');
	}

	/* -------------------------------------------------------------------- read */

	/**
	 * @param	int	$id		Sheet id
	 * @return	int				1 ok, 0 not found, -1 error
	 */
	public function fetch($id)
	{
		$this->lines = array();
		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT rowid, ref, fk_entrepot, date_count, status, note, qty_book, qty_counted, qty_diff, fk_user_creat, fk_user_valid"
			." FROM %spharmacy_stock_count WHERE rowid = %d",
			$P,
			(int) $id
		);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return 0;
		}
		$this->id = (int) $obj->rowid;
		$this->ref = (string) $obj->ref;
		$this->fk_entrepot = (int) $obj->fk_entrepot;
		$this->date_count = (string) $obj->date_count;
		$this->status = (int) $obj->status;
		$this->note = (string) $obj->note;
		$this->qty_book = (float) $obj->qty_book;
		$this->qty_counted = (float) $obj->qty_counted;
		$this->qty_diff = (float) $obj->qty_diff;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		$this->fk_user_valid = (int) $obj->fk_user_valid;
		return $this->fetchLines();
	}

	/**
	 * @return	int	1 ok, -1 error
	 */
	private function fetchLines()
	{
		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT rowid, fk_product, product_ref, product_label, batch, eatby, sellby, qty_book, qty_counted, qty_diff, note"
			." FROM %spharmacy_stock_count_line WHERE fk_count = %d ORDER BY product_ref, batch",
			$P,
			(int) $this->id
		);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->lines = array();
		while ($o = $this->db->fetch_object($resql)) {
			$o->rowid = (int) $o->rowid;
			$o->fk_product = (int) $o->fk_product;
			$o->qty_book = (float) $o->qty_book;
			$o->qty_counted = ($o->qty_counted === null) ? null : (float) $o->qty_counted;
			$o->qty_diff = (float) $o->qty_diff;
			$this->lines[] = $o;
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Paged sheet list.
	 *
	 * @param	array	$f		filters: wh, status, from, to, limit, offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f)
	{
		$P = $this->db->prefix();
		$where = sprintf(" WHERE c.entity = %d", (int) $this->getEntity());
		if (!empty($f['wh'])) {
			$where .= sprintf(" AND c.fk_entrepot = %d", (int) $f['wh']);
		}
		if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== -1) {
			$where .= sprintf(" AND c.status = %d", (int) $f['status']);
		}
		if (!empty($f['from'])) {
			$where .= sprintf(" AND c.date_count >= '%s'", $this->db->escape($f['from']));
		}
		if (!empty($f['to'])) {
			$where .= sprintf(" AND c.date_count <= '%s'", $this->db->escape($f['to']));
		}

		$sql = sprintf("SELECT COUNT(*) AS total FROM %spharmacy_stock_count AS c%s", $P, $where);
		$resql = $this->db->query($sql);
		$total = $resql ? (int) $this->db->fetch_object($resql)->total : 0;
		if ($resql) {
			$this->db->free($resql);
		}

		$sql = sprintf(
			"SELECT c.rowid, c.ref, c.fk_entrepot, w.lieu AS warehouse, c.date_count, c.status,"
			." c.qty_book, c.qty_counted, c.qty_diff, c.date_creation,"
			." (SELECT COUNT(*) FROM %spharmacy_stock_count_line AS l WHERE l.fk_count = c.rowid) AS nb_line,"
			." (SELECT COUNT(*) FROM %spharmacy_stock_count_line AS l WHERE l.fk_count = c.rowid AND l.qty_counted IS NULL) AS nb_todo"
			." FROM %spharmacy_stock_count AS c"
			." LEFT JOIN %sentrepot AS w ON w.rowid = c.fk_entrepot%s"
			." ORDER BY c.date_count DESC, c.rowid DESC",
			$P,
			$P,
			$P,
			$P,
			$where
		);
		$sql .= $this->db->plimit((int) $f['limit'], (int) $f['offset']);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return null;
		}
		$rows = array();
		while ($o = $this->db->fetch_object($resql)) {
			$rows[] = $o;
		}
		$this->db->free($resql);
		return array('total' => $total, 'rows' => $rows);
	}

	/* ------------------------------------------------------------------ update */

	/**
	 * Record the counted quantity of one line (0 is a valid count, null clears
	 * it back to "not counted").
	 *
	 * @param	int			$lineId	Line id
	 * @param	float|null	$qty		Counted quantity, null to clear
	 * @return	int				1 ok, -2 sheet already posted, -1 error
	 */
	public function setCounted($lineId, $qty)
	{
		if ($this->id <= 0) {
			$this->error = 'PharmacyStockCountErrNotLoaded';
			return -1;
		}
		if ($this->status !== self::STATUS_DRAFT) {
			return -2;
		}
		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT qty_book FROM %spharmacy_stock_count_line WHERE rowid = %d AND fk_count = %d",
			$P,
			(int) $lineId,
			(int) $this->id
		);
		$resql = $this->db->query($sql);
		$book = $resql ? (float) $this->db->fetch_object($resql)->qty_book : null;
		if ($resql) {
			$this->db->free($resql);
		}
		if ($book === null) {
			$this->error = 'PharmacyStockCountErrNoLine';
			return -1;
		}
		$value = ($qty === null || $qty === '') ? null : (float) $qty;
		$diff = ($value === null) ? 0.0 : ($value - $book);

		$sql = sprintf(
			"UPDATE %spharmacy_stock_count_line SET qty_counted = %s, qty_diff = %s WHERE rowid = %d",
			$P,
			($value === null ? 'NULL' : $this->num($value)),
			$this->num($diff),
			(int) $lineId
		);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->refreshTotals();
		return 1;
	}

	/**
	 * Recompute the header totals from the lines.
	 *
	 * @return	void
	 */
	public function refreshTotals()
	{
		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT COALESCE(SUM(qty_book),0) AS b,"
			." COALESCE(SUM(CASE WHEN qty_counted IS NULL THEN 0 ELSE qty_counted END),0) AS c"
			." FROM %spharmacy_stock_count_line WHERE fk_count = %d",
			$P,
			(int) $this->id
		);
		$resql = $this->db->query($sql);
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if ($resql) {
			$this->db->free($resql);
		}
		$book = $obj ? (float) $obj->b : 0.0;
		$counted = $obj ? (float) $obj->c : 0.0;
		$sql = sprintf(
			"UPDATE %spharmacy_stock_count SET qty_book = %s, qty_counted = %s, qty_diff = %s WHERE rowid = %d",
			$P,
			$this->num($book),
			$this->num($counted),
			$this->num($counted - $book),
			(int) $this->id
		);
		$this->db->query($sql);
		$this->qty_book = $book;
		$this->qty_counted = $counted;
		$this->qty_diff = $counted - $book;
	}

	/**
	 * Post the sheet: write the difference into the stock and freeze the sheet.
	 *
	 * @param	User	$user			Poster
	 * @param	bool	$forceSameUser	Allow the counter to post their own sheet
	 * @return	int					1 ok, -2 already posted, -3 lines not all counted,
	 *								-4 counter/poster must differ, -1 error
	 */
	public function post(User $user, $forceSameUser = false)
	{
		$this->error = '';
		if ($this->id <= 0) {
			$this->error = 'PharmacyStockCountErrNotLoaded';
			return -1;
		}
		if ($this->status !== self::STATUS_POSTED && $this->status !== self::STATUS_DRAFT) {
			return -2;
		}
		if ($this->status === self::STATUS_POSTED) {
			return -2;
		}
		if (!$forceSameUser && $this->fk_user_creat > 0 && (int) $user->id === $this->fk_user_creat) {
			$this->error = 'PharmacyStockCountErrSameUser';
			return -4;
		}
		foreach ($this->lines as $l) {
			if ($l->qty_counted === null) {
				$this->error = 'PharmacyStockCountErrNotAllCounted';
				return -3;
			}
		}

		$P = $this->db->prefix();
		$this->db->begin();
		try {
			foreach ($this->lines as $l) {
				$delta = (float) $l->qty_diff;
				if (abs($delta) < 0.0005) {
					continue;
				}
				$sql = sprintf("SELECT rowid FROM %sproduct_stock WHERE fk_product = %d AND fk_entrepot = %d", $P, (int) $l->fk_product, (int) $this->fk_entrepot);
				$resql = $this->db->query($sql);
				$stock = $resql ? (int) $this->db->fetch_object($resql)->rowid : 0;
				if ($resql) {
					$this->db->free($resql);
				}
				if ($stock <= 0) {
					throw new RuntimeException('stock row missing for product '.(int) $l->fk_product);
				}

				// The batch must exist for this stock row: a missing one means the
				// data was already inconsistent, refuse rather than invent a lot.
				$sql = sprintf("SELECT rowid, qty FROM %sproduct_batch WHERE fk_product_stock = %d AND batch = '%s'", $P, $stock, $this->db->escape((string) $l->batch));
				$resql = $this->db->query($sql);
				$batchRow = $resql ? $this->db->fetch_object($resql) : null;
				if ($resql) {
					$this->db->free($resql);
				}
				if (!$batchRow) {
					throw new RuntimeException('batch missing: '.$l->batch);
				}
				$newQty = (float) $batchRow->qty + $delta;
				if ($newQty < -0.0005) {
					throw new RuntimeException(
						'counted quantity below zero for batch '.$l->batch
						.' (counted '.$this->num((float) $l->qty_counted).' < book '.$this->num((float) $l->qty_book).')'
					);
				}
				$newQty = max(0, $newQty);

				$sql = sprintf("UPDATE %sproduct_batch SET qty = %s WHERE rowid = %d", $P, $this->num($newQty), (int) $batchRow->rowid);
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}
				$sql = sprintf("UPDATE %sproduct_stock SET reel = reel + %s WHERE rowid = %d", $P, $this->num($delta), $stock);
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}

				// Movement row: same shape as the core (3 = in, 2 = out).
				$sql = sprintf(
					"INSERT INTO %sstock_mouvement (datem, fk_product, batch, eatby, sellby, fk_entrepot, value, type_mouvement, fk_user_author, label)"
					." VALUES ('%s', %d, '%s', %s, %s, %d, %s, %d, %d, '%s')",
					$P,
					$this->db->idate(dol_now()),
					(int) $l->fk_product,
					$this->db->escape((string) $l->batch),
					empty($l->eatby) ? 'NULL' : "'".$this->db->escape($l->eatby)."'",
					empty($l->sellby) ? 'NULL' : "'".$this->db->escape($l->sellby)."'",
					(int) $this->fk_entrepot,
					$this->num($delta),
					($delta > 0 ? 3 : 2),
					(int) $user->id,
					$this->db->escape('Stock count '.$this->ref)
				);
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}
			}

			// Batch rule guard: batches must still add up to every stock row.
			$sql = sprintf(
				"SELECT ps.fk_product FROM %sproduct_stock AS ps"
				." LEFT JOIN %sproduct_batch AS pb ON pb.fk_product_stock = ps.rowid"
				." WHERE ps.fk_entrepot = %d"
				." GROUP BY ps.fk_product, ps.rowid, ps.reel"
				." HAVING ABS(reel - COALESCE(SUM(pb.qty),0)) > 0.0005",
				$P,
				$P,
				(int) $this->fk_entrepot
			);
			$resql = $this->db->query($sql);
			$broken = array();
			if ($resql) {
				while ($o = $this->db->fetch_object($resql)) {
					$broken[] = (int) $o->fk_product;
				}
				$this->db->free($resql);
			}
			if (!empty($broken)) {
				throw new RuntimeException('batch/stock mismatch for product(s) '.implode(',', $broken));
			}

			$sql = sprintf(
				"UPDATE %spharmacy_stock_count SET status = %d, fk_user_valid = %d WHERE rowid = %d AND status = %d",
				$P,
				self::STATUS_POSTED,
				(int) $user->id,
				(int) $this->id,
				self::STATUS_DRAFT
			);
			if (!$this->db->query($sql)) {
				throw new RuntimeException($this->db->lasterror());
			}
			$this->db->commit();
			$this->status = self::STATUS_POSTED;
			$this->fk_user_valid = (int) $user->id;
			return 1;
		} catch (Exception $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			return -1;
		}
	}

	/**
	 * Format a quantity for SQL (locale independent, no thousand separator).
	 *
	 * @param	float	$n		Number
	 * @return	string
	 */
	private function num($n)
	{
		$out = rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
		return ($out === '' || $out === '-') ? '0' : $out;
	}

	/**
	 * @return	int	Entity id
	 */
	private function getEntity()
	{
		global $conf;
		return (int) $conf->entity;
	}

	/**
	 * Roll the whole transaction stack back (Dolibarr counts references).
	 *
	 * @return	void
	 */
	private function rollbackAll()
	{
		while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
			$this->db->rollback();
		}
	}
}
