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
 * \file    htdocs/custom/pharmacy/class/pharmacyrecall.class.php
 * \ingroup pharmacy
 * \brief   Drug recall: pick a batch, get the list of patients who received it,
 *          track who has been notified.
 *
 * The patient list is derived from llx_stock_mouvement (label
 * "Dispense {ref}"), the same source the batch traceability page uses, so a
 * recall and a traceability lookup can never disagree about who got the goods.
 *
 * Creating a recall also appends a BLOCK row to llx_pharmacy_expiry_action:
 * a recalled batch must stop being dispensable immediately, and the expiry
 * board already knows how to show and reverse a sales hold. The quantity on
 * hand is NOT touched here - physically removing the goods is a disposition
 * (SCRAP) that somebody has to record on the expiry page.
 *
 * SQL uses sprintf(); the table prefix already carries its underscore, so
 * table names are written as %sproduct_batch and never %sp.%s.
 */
class PharmacyRecall
{
	/** Registered, patients not notified yet. */
	const STATUS_REGISTERED = 0;
	/** Every listed patient has been notified. */
	const STATUS_NOTIFIED = 1;
	/** Recall finished (and the stock disposed of). */
	const STATUS_CLOSED = 2;

	/** @var DoliDB */
	private $db;
	/** @var string */
	public $error = '';

	/** @var int */
	public $id = 0;
	/** @var string */
	public $ref = '';
	/** @var int */
	public $fk_product = 0;
	/** @var string */
	public $product_ref = '';
	/** @var string */
	public $product_label = '';
	/** @var string */
	public $batch = '';
	/** @var int */
	public $fk_entrepot = 0;
	/** @var int */
	public $level = 1;
	/** @var string */
	public $reason = '';
	/** @var int */
	public $status = self::STATUS_REGISTERED;
	/** @var int */
	public $fk_user_creat = 0;
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
	 * Register a recall for one batch and build the patient list.
	 *
	 * @param	User		$user			Author
	 * @param	int			$fkProduct		Product id
	 * @param	string		$batch			Batch number
	 * @param	int			$level			Recall level 1/2/3
	 * @param	string		$reason			Reason text
	 * @param	int			$warehouseId	0 = every warehouse
	 * @return	int							>0 recall id, <=0 error
	 */
	public function createFromBatch(User $user, $fkProduct, $batch, $level, $reason, $warehouseId = 0)
	{
		$this->error = '';
		$fkProduct = (int) $fkProduct;
		$batch = trim((string) $batch);
		if ($fkProduct <= 0 || $batch === '') {
			$this->error = 'PharmacyRecallErrBatch';
			return -1;
		}
		$level = min(3, max(1, (int) $level));
		$P = $this->db->prefix();

		// The batch must exist, otherwise there is nothing to recall.
		$sql = sprintf(
			"SELECT p.ref AS product_ref, p.label AS product_label, ps.fk_entrepot"
			." FROM %sproduct_batch AS pb"
			." INNER JOIN %sproduct_stock AS ps ON ps.rowid = pb.fk_product_stock"
			." LEFT JOIN %sproduct AS p ON p.rowid = ps.fk_product"
			." WHERE ps.fk_product = %d AND pb.batch = '%s'",
			$P,
			$P,
			$P,
			$fkProduct,
			$this->db->escape($batch)
		);
		$resql = $this->db->query($sql);
		$prod = $resql ? $this->db->fetch_object($resql) : null;
		if ($resql) {
			$this->db->free($resql);
		}
		if (!$prod) {
			$this->error = 'PharmacyRecallErrBatch';
			return -2;
		}

		// Who received this batch: every "Dispense {ref}" movement row.
		$sql = sprintf(
			"SELECT d.rowid AS fk_dispense, d.ref AS dispense_ref, d.date_dispense, d.fk_patient"
			.", pp.card_no, s.nom AS patient_name, ABS(m.value) AS qty"
			." FROM %sstock_mouvement AS m"
			." INNER JOIN %spharmacy_dispense AS d ON m.label = CONCAT('Dispense ', d.ref)"
			." LEFT JOIN %spatient_profile AS pp ON pp.rowid = d.fk_patient"
			." LEFT JOIN %ssociete AS s ON s.rowid = pp.fk_soc"
			." WHERE m.fk_product = %d AND m.batch = '%s'",
			$P,
			$P,
			$P,
			$P,
			$fkProduct,
			$this->db->escape($batch)
		);
		$resql = $this->db->query($sql);
		$dispensed = array();
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$dispensed[(int) $o->fk_dispense] = $o;
			}
			$this->db->free($resql);
		}

		$this->db->begin();
		try {
			$ref = $this->nextRef();
			$sql = sprintf(
				"INSERT INTO %spharmacy_recall (entity, ref, fk_product, product_ref, product_label, batch, fk_entrepot, level, reason, status, date_creation, fk_user_creat)"
				." VALUES (%d, '%s', %d, '%s', '%s', '%s', %d, %d, '%s', %d, '%s', %d)",
				$P,
				(int) $this->getEntity(),
				$this->db->escape($ref),
				$fkProduct,
				$this->db->escape((string) $prod->product_ref),
				$this->db->escape((string) $prod->product_label),
				$this->db->escape($batch),
				(int) $warehouseId,
				$level,
				$this->db->escape($reason),
				self::STATUS_REGISTERED,
				$this->db->idate(dol_now()),
				(int) $user->id
			);
			if (!$this->db->query($sql)) {
				throw new RuntimeException($this->db->lasterror());
			}
			$recallId = (int) $this->db->last_insert_id($P.'pharmacy_recall');

			foreach ($dispensed as $d) {
				$sql = sprintf(
					"INSERT INTO %spharmacy_recall_line (fk_recall, fk_dispense, dispense_ref, date_dispense, fk_patient, card_no, patient_name, qty)"
					." VALUES (%d, %d, '%s', %s, %d, '%s', '%s', %s)",
					$P,
					$recallId,
					(int) $d->fk_dispense,
					$this->db->escape((string) $d->dispense_ref),
					empty($d->date_dispense) ? 'NULL' : "'".$this->db->escape($d->date_dispense)."'",
					(int) $d->fk_patient,
					$this->db->escape((string) $d->card_no),
					$this->db->escape((string) $d->patient_name),
					$this->num((float) $d->qty)
				);
				if (!$this->db->query($sql)) {
					throw new RuntimeException($this->db->lasterror());
				}
			}

			// Sales hold: a recalled batch must not be dispensed any more.
			$sql = sprintf(
				"INSERT INTO %spharmacy_expiry_action (entity, fk_product, batch, fk_entrepot, op, qty, note, fk_user_creat, date_creation)"
				." VALUES (%d, %d, '%s', %d, 'BLOCK', 0, '%s', %d, '%s')",
				$P,
				(int) $this->getEntity(),
				$fkProduct,
				$this->db->escape($batch),
				(int) $warehouseId,
				$this->db->escape(dol_substr('Recall '.$ref.': '.$reason, 0, 250)),
				(int) $user->id,
				$this->db->idate(dol_now())
			);
			if (!$this->db->query($sql)) {
				throw new RuntimeException($this->db->lasterror());
			}

			$this->db->commit();
			$this->id = $recallId;
			$this->ref = $ref;
			$this->fk_product = $fkProduct;
			$this->product_ref = (string) $prod->product_ref;
			$this->product_label = (string) $prod->product_label;
			$this->batch = $batch;
			$this->fk_entrepot = (int) $warehouseId;
			$this->level = $level;
			$this->reason = $reason;
			$this->status = self::STATUS_REGISTERED;
			$this->fk_user_creat = (int) $user->id;
			return $recallId;
		} catch (Exception $e) {
			$this->rollbackAll();
			$this->error = $e->getMessage();
			return -1;
		}
	}

	/**
	 * @return	string	Next recall reference, RC-{Ymd}-{NNN}
	 */
	private function nextRef()
	{
		$P = $this->db->prefix();
		$day = date('Ymd');
		for ($try = 0; $try < 20; $try++) {
			$sql = sprintf("SELECT COUNT(*) AS n FROM %spharmacy_recall WHERE ref LIKE 'RC-%s-%%'", $P, $day);
			$resql = $this->db->query($sql);
			$n = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
			if ($resql) {
				$this->db->free($resql);
			}
			$ref = 'RC-'.$day.'-'.sprintf('%03d', $n + 1 + $try);
			$sql = sprintf("SELECT COUNT(*) AS n FROM %spharmacy_recall WHERE ref = '%s'", $P, $this->db->escape($ref));
			$resql = $this->db->query($sql);
			$exists = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
			if ($resql) {
				$this->db->free($resql);
			}
			if ($exists === 0) {
				return $ref;
			}
		}
		return 'RC-'.$day.'-'.dol_print_date(dol_now(), 'dayhour');
	}

	/* -------------------------------------------------------------------- read */

	/**
	 * @param	int	$id		Recall id
	 * @return	int				1 ok, 0 not found, -1 error
	 */
	public function fetch($id)
	{
		$this->lines = array();
		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT rowid, ref, fk_product, product_ref, product_label, batch, fk_entrepot, level, reason, status, fk_user_creat, date_creation"
			." FROM %spharmacy_recall WHERE rowid = %d",
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
		$this->fk_product = (int) $obj->fk_product;
		$this->product_ref = (string) $obj->product_ref;
		$this->product_label = (string) $obj->product_label;
		$this->batch = (string) $obj->batch;
		$this->fk_entrepot = (int) $obj->fk_entrepot;
		$this->level = (int) $obj->level;
		$this->reason = (string) $obj->reason;
		$this->status = (int) $obj->status;
		$this->fk_user_creat = (int) $obj->fk_user_creat;
		return $this->fetchLines();
	}

	/**
	 * Reload the patient list. Public because the page refreshes it in place
	 * after a notification flag is toggled.
	 *
	 * @return	int	1 ok, -1 error
	 */
	public function fetchLines()
	{
		$P = $this->db->prefix();
		$sql = sprintf(
			"SELECT rowid, fk_dispense, dispense_ref, date_dispense, fk_patient, card_no, patient_name, qty, notified, date_notified, note"
			." FROM %spharmacy_recall_line WHERE fk_recall = %d ORDER BY date_dispense DESC, rowid DESC",
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
			$o->fk_dispense = (int) $o->fk_dispense;
			$o->fk_patient = (int) $o->fk_patient;
			$o->qty = (float) $o->qty;
			$o->notified = (int) $o->notified;
			$this->lines[] = $o;
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Paged recall list.
	 *
	 * @param	array	$f		filters: status, level, limit, offset
	 * @return	array{total:int,rows:array<int,object>}|null
	 */
	public function search(array $f)
	{
		$P = $this->db->prefix();
		$where = sprintf(" WHERE r.entity = %d", (int) $this->getEntity());
		if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== -1) {
			$where .= sprintf(" AND r.status = %d", (int) $f['status']);
		}
		if (!empty($f['level'])) {
			$where .= sprintf(" AND r.level = %d", (int) $f['level']);
		}

		$sql = sprintf("SELECT COUNT(*) AS total FROM %spharmacy_recall AS r%s", $P, $where);
		$resql = $this->db->query($sql);
		$total = $resql ? (int) $this->db->fetch_object($resql)->total : 0;
		if ($resql) {
			$this->db->free($resql);
		}

		$sql = sprintf(
			"SELECT r.rowid, r.ref, r.product_ref, r.product_label, r.batch, r.level, r.status, r.date_creation, r.reason"
			.", (SELECT COUNT(*) FROM %spharmacy_recall_line AS l WHERE l.fk_recall = r.rowid) AS nb_patient"
			.", (SELECT COUNT(*) FROM %spharmacy_recall_line AS l WHERE l.fk_recall = r.rowid AND l.notified = 0) AS nb_todo"
			." FROM %spharmacy_recall AS r%s"
			." ORDER BY r.date_creation DESC, r.rowid DESC",
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
	 * Flag one patient line as notified (or clear the flag).
	 *
	 * @param	int	$lineId	Line id
	 * @param	bool	$flag		true = notified
	 * @return	int				1 ok, -2 recall closed, -1 error
	 */
	public function markNotified($lineId, $flag)
	{
		if ($this->status === self::STATUS_CLOSED) {
			return -2;
		}
		$P = $this->db->prefix();
		$sql = sprintf(
			"UPDATE %spharmacy_recall_line SET notified = %d, date_notified = %s WHERE rowid = %d AND fk_recall = %d",
			$P,
			($flag ? 1 : 0),
			($flag ? "'".$this->db->idate(dol_now())."'" : 'NULL'),
			(int) $lineId,
			(int) $this->id
		);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->refreshStatus();
		return 1;
	}

	/**
	 * Move the recall to "notified" once every patient is marked, or back to
	 * "registered" when a flag is cleared.
	 *
	 * @return	void
	 */
	public function refreshStatus()
	{
		$P = $this->db->prefix();
		$sql = sprintf("SELECT COUNT(*) AS n FROM %spharmacy_recall_line WHERE fk_recall = %d AND notified = 0", $P, (int) $this->id);
		$resql = $this->db->query($sql);
		$todo = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		$status = ($todo > 0) ? self::STATUS_REGISTERED : self::STATUS_NOTIFIED;
		$sql = sprintf("UPDATE %spharmacy_recall SET status = %d WHERE rowid = %d AND status <> %d", $P, $status, (int) $this->id, self::STATUS_CLOSED);
		$this->db->query($sql);
		$this->status = $status;
	}

	/**
	 * Close the recall. The batch stays blocked: the goods still have to be
	 * disposed of on the expiry page, and closing the recall must not make a
	 * recalled batch dispensable again.
	 *
	 * @param	User	$user		Closer
	 * @return	int					1 ok, -2 already closed, -3 patients not notified, -1 error
	 */
	public function close(User $user)
	{
		if ($this->status === self::STATUS_CLOSED) {
			return -2;
		}
		$P = $this->db->prefix();
		$sql = sprintf("SELECT COUNT(*) AS n FROM %spharmacy_recall_line WHERE fk_recall = %d AND notified = 0", $P, (int) $this->id);
		$resql = $this->db->query($sql);
		$todo = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		if ($todo > 0) {
			return -3;
		}
		$sql = sprintf(
			"UPDATE %spharmacy_recall SET status = %d, fk_user_close = %d, date_close = '%s' WHERE rowid = %d AND status <> %d",
			$P,
			self::STATUS_CLOSED,
			(int) $user->id,
			$this->db->idate(dol_now()),
			(int) $this->id,
			self::STATUS_CLOSED
		);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->status = self::STATUS_CLOSED;
		return 1;
	}

	/**
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
