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
 * \file    htdocs/custom/pharmacy/tests/unit/PharmacyOpsTest.php
 * \ingroup pharmacy
 * \brief   Stock count and recall: the invariants that must not regress.
 *
 * The behaviour of both flows is proven by scripts/probe_stock_count.php and
 * scripts/probe_recall.php (they run against the database inside a rolled-back
 * transaction). What is pinned here are the traps that broke them while being
 * written, because those are exactly the things a later edit would undo.
 */
class PharmacyOpsTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * @return	string[]
	 */
	private function newClasses()
	{
		return array(
			'count' => file_get_contents(__DIR__.'/../../class/pharmacystockcount.class.php'),
			'recall' => file_get_contents(__DIR__.'/../../class/pharmacyrecall.class.php'),
		);
	}

	/**
	 * The table prefix already ends with an underscore. Writing "%sp." or
	 * "%s." produces "llx_p.product_batch" / "llx_.product_batch", which MySQL
	 * reads as another DATABASE and answers with a misleading
	 * "SELECT command denied ... for table 'product_batch'".
	 */
	public function testTablePrefixIsNotDoubleWritten()
	{
		foreach ($this->newClasses() as $name => $src) {
			$this->assertSame(0, preg_match('/%s[._]product_/', $src), $name.': table prefix must be written as %sproduct_x');
			$this->assertSame(0, preg_match('/%s[._]pharmacy_/', $src), $name.': same for module tables');
			$this->assertSame(0, preg_match('/%s[._]stock_/', $src), $name.': same for stock tables');
		}
	}

	/**
	 * sprintf() argument order mistakes around casts are invisible in PHP but
	 * glue an integer into the next string, so the statements must all be
	 * built with sprintf and plain $P concatenation.
	 */
	public function testNoCastInsideConcatenation()
	{
		foreach ($this->newClasses() as $name => $src) {
			$this->assertSame(
				0,
				preg_match('/\"\\.= \"\\(\\((?:int|float)\\) \\\$[A-Za-z_>\\-]+[.,]/', $src),
				$name.': close the cast parenthesis before concatenating'
			);
		}
	}

	/**
	 * Posting a count must move the batch quantity AND the stock total, write
	 * a movement, and re-check the batch rule; a count that only touches one
	 * of them silently breaks the "not enough stock" guard downstream.
	 */
	public function testStockCountPostingKeepsTheBatchRule()
	{
		$src = $this->newClasses()['count'];
		$this->assertStringContainsString('UPDATE %sproduct_batch SET qty = ', $src, 'batch quantity adjusted');
		$this->assertStringContainsString('UPDATE %sproduct_stock SET reel = reel + ', $src, 'stock total adjusted');
		$this->assertStringContainsString('INSERT INTO %sstock_mouvement', $src, 'movement written');
		$this->assertStringContainsString('batch/stock mismatch for product(s)', $src, 'batch rule verified after posting');
		$this->assertStringContainsString('SELECT ps.fk_product FROM %sproduct_stock AS ps', $src, 'the guard really queries batches');
	}

	/**
	 * A half-counted sheet must never reach the stock ledger, and the counter
	 * must not be the poster (GSP).
	 */
	public function testStockCountGuards()
	{
		$src = $this->newClasses()['count'];
		$this->assertStringContainsString("'PharmacyStockCountErrNotAllCounted'", $src, 'uncounted line blocks posting');
		$this->assertStringContainsString("'PharmacyStockCountErrSameUser'", $src, 'counter cannot post their own sheet');
		$this->assertStringContainsString('$forceSameUser', $src, 'override is explicit');
		$this->assertStringContainsString('qty_counted === null', $src, 'NULL means not counted');
	}

	/**
	 * The recall patient list has to come from the stock movements, the same
	 * source as the traceability page, otherwise the two can disagree about
	 * who received the goods.
	 */
	public function testRecallPatientListComesFromMovements()
	{
		$src = $this->newClasses()['recall'];
		$this->assertStringContainsString('FROM %sstock_mouvement AS m', $src, 'movements are the source');
		$this->assertStringContainsString("m.label = CONCAT('Dispense ', d.ref)", $src, 'linked to the dispensing sheet');
		$this->assertStringContainsString('ABS(m.value) AS qty', $src, 'quantity is absolute, movements are signed');
		$this->assertStringNotContainsString("CONCAT('Return '", $src, 'a returned sheet is not a new patient to notify');
	}

	/**
	 * A recall must stop the batch being dispensed: the sales hold is written
	 * through the existing expiry-action path, and the quantity on hand is left
	 * alone (physical disposal is a separate, recorded step).
	 */
	public function testRecallBlocksBatchWithoutTouchingQuantity()
	{
		$src = $this->newClasses()['recall'];
		$this->assertStringContainsString('INSERT INTO %spharmacy_expiry_action', $src, 'hold goes through the expiry action log');
		$this->assertStringContainsString("'BLOCK'", $src, 'op = BLOCK');
		$this->assertStringContainsString("VALUES (%d, %d, '%s', %d, 'BLOCK', 0,", $src, 'hold row carries qty = 0');
		$this->assertStringNotContainsString('UPDATE %sproduct_batch', $src, 'recall must not change the quantities');
		$this->assertStringNotContainsString('UPDATE %sproduct_stock', $src, 'recall must not change the stock total');
	}

	/**
	 * The status walk registered -> notified -> closed must be driven by the
	 * notification flags, and closing early is refused.
	 */
	public function testRecallStatusWalk()
	{
		$src = $this->newClasses()['recall'];
		$this->assertStringContainsString('notified = 0', $src, 'pending patients counted');
		$this->assertStringContainsString('return -3;', $src, 'close refused while patients are pending');
		$this->assertStringContainsString('self::STATUS_NOTIFIED', $src, 'status becomes notified');
		$this->assertStringContainsString('self::STATUS_CLOSED', $src, 'status can be closed');
	}

	/**
	 * A drug return credits the money side through the existing two-step refund
	 * API as a DRAFT: the pharmacy may not decide what to refund on its own.
	 */
	public function testReturnOnlyDraftsTheRefund()
	{
		$lib = file_get_contents(__DIR__.'/../../lib/pharmacy.lib.php');
		$this->assertStringContainsString('function pharmacy_return_draft_refund(', $lib, 'helper exists');
		$this->assertStringContainsString('createRefundDraft(', $lib, 'uses the existing refund API');
		$this->assertStringNotContainsString('executeRefund(', $lib, 'never executes the refund from the pharmacy');
		$this->assertStringContainsString("isModEnabled('clinicpay')", $lib, 'clinicpay is optional at runtime');
		$this->assertStringContainsString('CLINICPAY_BILL_PAID', $lib, 'only paid bills are credited');
		$this->assertStringContainsString('findRefundDraft()', $lib, 'no duplicate draft');

		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString('pharmacy_return_draft_refund(', $card, 'the return page reports the draft');
		$this->assertStringContainsString('PharmacyReturnRefundDraft', $card, 'and links to the bill');
	}

	/**
	 * The prescription stock column must reuse the shared helper instead of
	 * querying stock itself, so the FEFO exclusions stay in one place.
	 */
	public function testPrescriptionStockUsesSharedHelper()
	{
		$lib = file_get_contents(__DIR__.'/../../lib/pharmacy.lib.php');
		$this->assertStringContainsString('function pharmacy_stock_available(', $lib, 'helper exists');
		$this->assertStringContainsString('PharmacyBatchAction::latestOpJoin', $lib, 'sales hold excluded like the dispenser does');
		$this->assertStringContainsString("ea.op IS NULL OR ea.op <> 'BLOCK'", $lib, 'blocked lots are not offered');
	}

	/**
	 * New menu entries must exist and be reachable from the Clinic groups.
	 */
	public function testNewMenusRegistered()
	{
		$desc = file_get_contents(__DIR__.'/../../core/modules/modPharmacy.class.php');
		$this->assertStringContainsString("'url' => '/pharmacy/stock_count.php'", $desc, 'stock count menu');
		$this->assertStringContainsString("'url' => '/pharmacy/recall.php'", $desc, 'recall menu');
		$this->assertStringContainsString('fk_leftmenu=clinic_pharmacy', $desc, 'both live in the pharmacy group');
		$this->assertStringContainsString("'position' => 1311", $desc, 'stock count position');
		$this->assertStringContainsString("'position' => 1320", $desc, 'recall position');
		$this->assertStringContainsString("91 => 'stock_count'", $desc, 'stock count permission registered');
	}

	/**
	 * Both features ship their SQL, and a fresh install must create the tables.
	 */
	public function testSqlFilesExist()
	{
		$this->assertFileExists(__DIR__.'/../../sql/llx_pharmacy_stock_count.sql');
		$this->assertFileExists(__DIR__.'/../../sql/llx_pharmacy_stock_count.key.sql');
		$this->assertFileExists(__DIR__.'/../../sql/llx_pharmacy_recall.sql');
		$this->assertFileExists(__DIR__.'/../../sql/llx_pharmacy_recall.key.sql');

		$count = file_get_contents(__DIR__.'/../../sql/llx_pharmacy_stock_count.sql');
		$this->assertStringContainsString('CREATE TABLE llx_pharmacy_stock_count(', $count);
		$this->assertStringContainsString('CREATE TABLE llx_pharmacy_stock_count_line(', $count);
		// qty_counted NULL = not counted yet, which is what blocks posting.
		$this->assertRegExp('/qty_counted\s+double DEFAULT NULL/', $count);

		$recall = file_get_contents(__DIR__.'/../../sql/llx_pharmacy_recall.sql');
		$this->assertStringContainsString('CREATE TABLE llx_pharmacy_recall(', $recall);
		$this->assertStringContainsString('CREATE TABLE llx_pharmacy_recall_line(', $recall);
	}
}
