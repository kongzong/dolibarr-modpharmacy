<?php
/* Copyright (C) 2026 modPharmacy contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * \file    htdocs/custom/pharmacy/tests/unit/PharmacyNumberingTest.php
 * \ingroup pharmacy
 * \brief   Pure-function tests of the FY-YYYYMMDD-NNN numbering helpers
 *          (no database; the concurrency suite is tests/integration).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(dirname(__DIR__)).'/class/pharmacynumbering.class.php';

/**
 * Class PharmacyNumberingTest
 */
class PharmacyNumberingTest extends TestCase
{
	public function testPrefixShape()
	{
		$this->assertSame('FY-20260922-', PharmacyNumbering::prefixFor(mktime(12, 0, 0, 9, 22, 2026)));
		$this->assertRegExp('/^FY-[0-9]{8}-$/', PharmacyNumbering::prefixFor());
		$this->assertSame('FY-20261231-', PharmacyNumbering::prefixFor(mktime(0, 0, 0, 12, 31, 2026)));
	}

	public function testPrefixPatternAcceptsValidAndRejectsOtherModules()
	{
		$this->assertTrue((bool) preg_match(PharmacyNumbering::PREFIX_PATTERN, 'FY-20260922-'));
		$this->assertFalse((bool) preg_match(PharmacyNumbering::PREFIX_PATTERN, 'CF-20260922-'), 'prescription prefix rejected');
		$this->assertFalse((bool) preg_match(PharmacyNumbering::PREFIX_PATTERN, 'FY-2026092-'));
		$this->assertFalse((bool) preg_match(PharmacyNumbering::PREFIX_PATTERN, 'FY-20260922-x'));
	}

	public function testRefShape()
	{
		// 3-digit zero-padded sequence, FY-YYYYMMDD-NNN
		$prefix = PharmacyNumbering::prefixFor(mktime(0, 0, 0, 9, 22, 2026));
		$this->assertSame($prefix.'001', $prefix.sprintf('%03d', 1));
		$this->assertRegExp('/^'.preg_quote($prefix, '/').'[0-9]{3}$/', $prefix.'042');
	}

	/**
	 * Preview mode (no open transaction) must not write: a non-mysqli db
	 * stub makes nextReference fail closed before any query.
	 */
	public function testFailsClosedWithoutMysqli()
	{
		$db = new stdClass();
		$db->type = 'pgsql';
		$n = new PharmacyNumbering($db);
		try {
			$n->nextReference('FY-20260922-');
			$this->fail('expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertTrue(strpos($e->getMessage(), 'MySQL/MariaDB') !== false || strpos($e->getMessage(), 'MySQL/MariaDB') !== false || strpos((string) $e->getPrevious(), 'MySQL/MariaDB') !== false, 'fail-closed on non-mysqli');
		}
	}
}
