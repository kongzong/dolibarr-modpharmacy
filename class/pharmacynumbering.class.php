<?php
/* Copyright (C) 2026 modPharmacy contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Daily dispense numbers (FY-YYYYMMDD-NNN), reserved inside the caller's
 * database transaction. Same InnoDB row-lock pattern as modPatient,
 * modMedRecord and modPrescription. A call outside a transaction is a
 * read-only preview.
 */
class PharmacyNumbering
{
	/** Prefix shape: FY-YYYYMMDD- */
	const PREFIX_PATTERN = '/^FY-[0-9]{8}-$/D';

	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database connection of the dispense transaction */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	int|null	$timestamp	Unix timestamp (default now)
	 * @return	string					FY-YYYYMMDD-
	 */
	public static function prefixFor($timestamp = null)
	{
		return 'FY-'.date('Ymd', $timestamp === null ? time() : (int) $timestamp).'-';
	}

	/**
	 * @param string $prefix Daily prefix, for example FY-20260922-
	 * @return string Reference (a preview unless a caller transaction is open)
	 * @throws RuntimeException On any failure; never returns an invalid reference.
	 */
	public function nextReference($prefix)
	{
		$step = 'checking the numbering context';
		try {
			if (!is_object($this->db) || $this->db->type !== 'mysqli' || !preg_match(self::PREFIX_PATTERN, $prefix)) {
				throw new RuntimeException('MySQL/MariaDB and a daily dispense prefix are required');
			}

			$reserve = $this->db->transaction_opened > 0;
			$table = $this->db->prefix().'pharmacy_dispense_sequence';
			$escapedPrefix = $this->db->escape($prefix);
			$lock = $reserve ? ' FOR UPDATE' : '';

			if ($reserve) {
				$step = 'locking the daily sequence';
				$this->query("INSERT INTO ".$table." (ref_prefix, last_value) VALUES ('".$escapedPrefix."', 0)"
					." ON DUPLICATE KEY UPDATE last_value = last_value");
			}

			$step = 'reading the daily sequence';
			$result = $this->query("SELECT last_value FROM ".$table." WHERE ref_prefix = '".$escapedPrefix."'".$lock);
			$counter = $this->db->fetch_object($result);
			if (!$counter && ($reserve || $this->db->num_rows($result) !== 0)) {
				throw new RuntimeException('Cannot read the daily sequence row');
			}
			$lastValue = $this->sequenceValue($counter ? $counter->last_value : null);
			$this->db->free($result);

			$step = 'reading existing dispense references';
			$result = $this->query("SELECT MAX(CAST(SUBSTRING(ref, ".(strlen($prefix) + 1).") AS UNSIGNED)) AS maxseq"
				." FROM ".$this->db->prefix()."pharmacy_dispense WHERE ref LIKE '".$escapedPrefix."%'".$lock);
			$history = $this->db->fetch_object($result);
			if (!$history || !property_exists($history, 'maxseq')) {
				throw new RuntimeException('Cannot read existing dispense references');
			}
			$lastValue = max($lastValue, $this->sequenceValue($history->maxseq));
			$this->db->free($result);
			if ($lastValue >= PHP_INT_MAX) {
				throw new RuntimeException('Dispense sequence exhausted');
			}
			$nextValue = $lastValue + 1;

			if ($reserve) {
				$step = 'reserving the dispense reference';
				$this->query("UPDATE ".$table." SET last_value = ".$nextValue." WHERE ref_prefix = '".$escapedPrefix."'");
			}

			return $prefix.sprintf('%03d', $nextValue);
		} catch (Throwable $error) {
			$this->rollback();
			$message = 'modPharmacy could not generate a dispense number while '.$step.'. '
				.'Retry the save; after upgrading, re-enable the module to install its sequence table.';
			dol_syslog($message, LOG_ERR);
			throw new RuntimeException($message, 0, $error);
		}
	}

	/** @param string $sql SQL statement
	 *  @return mixed Successful result
	 */
	private function query($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Dispense numbering query failed');
		}
		return $result;
	}

	/** @param mixed $value Database integer or NULL for an empty day
	 *  @return int Valid nonnegative sequence
	 */
	private function sequenceValue($value)
	{
		if ($value === null) {
			return 0;
		}
		$text = (string) $value;
		$maximum = (string) PHP_INT_MAX;
		if (!preg_match('/^[0-9]+$/D', $text) || strlen($text) > strlen($maximum)
			|| (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
			throw new RuntimeException('Invalid or exhausted dispense sequence');
		}
		return (int) $text;
	}

	/** Fully unwind DoliDB's transaction depth; an inner rollback alone is a no-op. */
	private function rollback()
	{
		if (!is_object($this->db)) {
			return;
		}
		while (property_exists($this->db, 'transaction_opened') && $this->db->transaction_opened > 0) {
			$this->db->rollback();
		}
	}
}
