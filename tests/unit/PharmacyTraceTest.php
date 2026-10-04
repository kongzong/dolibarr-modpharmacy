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
 * \file    htdocs/custom/pharmacy/tests/unit/PharmacyTraceTest.php
 * \ingroup pharmacy
 * \brief   Batch traceability: label/batch parsing plus the contracts that
 *          depend on them.
 *
 * The parsers are pure functions precisely so they can be tested without a
 * database: the traceability page, the demo backfill script and any future
 * audit tool must all split llx_stock_mouvement labels and dispense batch notes
 * the same way.
 */

require_once dirname(dirname(__DIR__)).'/lib/pharmacy.lib.php';

class PharmacyTraceTest extends \PHPUnit\Framework\TestCase
{
	/**
	 * batch_note is written by Dispense::confirm() as "BATCH/date" chunks
	 * joined with ", ". Both the batch and the date must survive parsing.
	 */
	public function testParseBatchNoteSingleBatch()
	{
		$this->assertSame(
			array('YP-A0006-B20271003'),
			pharmacy_parse_batch_note('YP-A0006-B20271003/2027-10-03')
		);
	}

	public function testParseBatchNoteMultipleBatches()
	{
		$this->assertSame(
			array('B-1', 'B-2', 'B-3'),
			pharmacy_parse_batch_note('B-1/2027-10-03, B-2/2028-01-01, B-3')
		);
	}

	/**
	 * A batch without a sell-by date is stored without the "/date" part, and
	 * empty or whitespace input must not produce a phantom batch.
	 */
	public function testParseBatchNoteEdgeCases()
	{
		$this->assertSame(array('B-NODATE'), pharmacy_parse_batch_note('B-NODATE'));
		$this->assertSame(array(), pharmacy_parse_batch_note(''));
		$this->assertSame(array(), pharmacy_parse_batch_note('   '));
		$this->assertSame(array('B-1'), pharmacy_parse_batch_note('B-1, , '));
	}

	/**
	 * The label is the only link from a stock movement to its sheet, so both
	 * prefixes written by the core (Dispense / Return) must resolve, and
	 * anything else must not.
	 */
	public function testMovementSheetRef()
	{
		$this->assertSame('FY-20260926-002', pharmacy_movement_sheet_ref('Dispense FY-20260926-002'));
		$this->assertSame('FY-20260916-001', pharmacy_movement_sheet_ref('Return FY-20260916-001'));
		$this->assertSame('', pharmacy_movement_sheet_ref('Supplier receipt'));
		$this->assertSame('', pharmacy_movement_sheet_ref(''));
		// A label that merely contains the word is not ours.
		$this->assertSame('', pharmacy_movement_sheet_ref('Cancel Dispense FY-1'));
	}

	/**
	 * Direction drives the reconciliation sign: a return has to be added back,
	 * otherwise returning goods looks like a loss.
	 */
	public function testMovementDirection()
	{
		$this->assertSame(1, pharmacy_movement_direction('Dispense FY-1'));
		$this->assertSame(-1, pharmacy_movement_direction('Return FY-1'));
		$this->assertSame(0, pharmacy_movement_direction('Stock adjustment'));
		$this->assertSame(0, pharmacy_movement_direction(''));
	}

	/**
	 * The page must read the outbound side from the stock movements (real batch
	 * column, returns included) and must not fall back to the free-text note.
	 */
	public function testTracePageUsesStockMovements()
	{
		$page = file_get_contents(__DIR__.'/../../trace_batch.php');
		$this->assertStringContainsString('stock_mouvement AS m', $page, 'outbound side reads stock movements');
		$this->assertStringContainsString("m.label = CONCAT('Dispense ', d.ref)", $page, 'movements link to their sheet');
		$this->assertStringContainsString("m.label = CONCAT('Return ', d.ref)", $page, 'returns link as well');
		$this->assertStringContainsString('pharmacy_movement_direction', $page, 'direction comes from the shared helper');
		$this->assertStringContainsString(
			'$expected = $qtyIn - $qtyOut + $qtyBack - $qtyScrap;',
			$page,
			'returns are added back in the reconciliation'
		);
		$this->assertStringNotContainsString('pharmacy_parse_batch_note(', $page, 'page no longer parses the free-text note');
	}

	/**
	 * The demo backfill must be idempotent and must never touch stock: the seed
	 * already moved product_stock.reel and the batch rows, so deducting again
	 * would double-count.
	 */
	public function testDemoBackfillIsIdempotentAndStockSafe()
	{
		$script = file_get_contents(__DIR__.'/../../../scripts/ensure_demo_trace_chain.php');
		$this->assertStringContainsString("WHERE label = '", $script, 'idempotency guard on the movement label');
		$this->assertStringContainsString('AND fk_product = ', $script, 'idempotency guard per product');
		$this->assertStringContainsString('$type = $isReturn ? 3 : 2;', $script, 'core type codes: 2 out, 3 in');
		$this->assertStringContainsString('$value = $isReturn ? $take : -$take;', $script, 'outbound negative, return positive');
		$this->assertStringNotContainsString('product_stock SET', $script, 'never writes product_stock');
		$this->assertStringNotContainsString('product_batch SET', $script, 'never writes product_batch');
		$this->assertStringNotContainsString('SET status =', $script, 'never flips sheet or prescription status');
	}

	/**
	 * Demo batch numbering must be anchored to a fixed base day, otherwise a
	 * rebuild on another date invents new batch codes and the purchase receipt
	 * stops matching the stock batches.
	 */
	public function testDemoBatchNumberingIsAnchored()
	{
		$lib = file_get_contents(__DIR__.'/../../../scripts/demo_batch_lib.php');
		$this->assertStringContainsString("define('DEMO_BATCH_BASE_DATE'", $lib, 'fixed base day');
		$this->assertRegExp("/return \\\$ref\\.'-B'\\.date\\('Ymd'/", $lib, 'shared code shape {ref}-B{YYYYMMDD}');

		$purchase = file_get_contents(__DIR__.'/../../../scripts/ensure_demo_purchase.php');
		$this->assertStringContainsString('demo_batch_code(', $purchase, 'purchase uses the shared code');
		$this->assertStringNotContainsString("strtotime('+2 year')", $purchase, 'no clock-relative expiry');

		$batches = file_get_contents(__DIR__.'/../../../scripts/ensure_product_batches.php');
		$this->assertStringContainsString('demo_batch_eatby(', $batches, 'stock batches use the shared expiry');
		$this->assertStringNotContainsString("strtotime('+'.(12 * \$made).' months')", $batches, 'no clock-relative expiry');
	}
}
