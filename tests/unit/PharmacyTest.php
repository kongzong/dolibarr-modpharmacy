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
 * \file    htdocs/custom/pharmacy/tests/unit/PharmacyTest.php
 * \ingroup pharmacy
 * \brief   modPharmacy structural tests, phase 1 (no DB needed).
 */

use PHPUnit\Framework\TestCase;

/**
 * Class PharmacyTest
 */
class PharmacyTest extends TestCase
{
	/**
	 * Descriptor: ID 501630, depends on patient + prescription + core stock
	 * modules, models scalar 1, prescriptioncard hook context, one-level
	 * permissions with dispense/return split, cron off by default.
	 */
	public function testDescriptor()
	{
		$content = file_get_contents(__DIR__.'/../../core/modules/modPharmacy.class.php');
		$this->assertStringContainsString('$this->numero = 501630;', $content);
		$this->assertStringContainsString("rights_class = 'pharmacy'", $content);
		$this->assertStringContainsString("depends = array('modPatient', 'modPrescription', 'modProduct', 'modStock', 'modProductBatch')", $content);
		$this->assertStringContainsString("'models' => 1", $content, 'scalar 1: array form breaks dol_buildpath (chinadoc probe)');
		$this->assertStringContainsString("'hooks' => array('prescriptioncard')", $content);
		foreach (array("'read'", "'write'", "'dispense'", "'return'", "'admin'") as $perm) {
			$this->assertStringContainsString("=> ".$perm, $content, 'one-level permission '.$perm);
		}
		$this->assertStringContainsString("'fk_menu' => 'fk_mainmenu=clinic'", $content);
		$this->assertStringNotContainsString("'type' => 'top'", $content);
		// Red line §5.7: expiry push off by default
		$this->assertStringContainsString("'PHARMACY_EXPIRY_NOTIFY', 'chaine', '0'", $content);
		// Cron registered but disabled by default
		$this->assertStringContainsString("'status' => 0,", $content, 'cron job created disabled');
		$this->assertStringContainsString("PharmacyExpiryAlert", $content);
		$this->assertStringNotContainsString('DROP TABLE', $content);
		$this->assertStringContainsString('_load_tables(\'/pharmacy/sql/\')', $content);
	}

	/**
	 * SQL: dispense + line tables with the spec columns, sequence table,
	 * unique ref index, file names accepted by _load_tables.
	 */
	public function testSqlSchema()
	{
		$sqlDir = __DIR__.'/../../sql/';
		$d = file_get_contents($sqlDir.'llx_pharmacy_dispense.sql');
		foreach (array('ref', 'fk_prescription', 'fk_patient', 'fk_warehouse', 'status', 'date_dispense', 'fk_user_dispense',
			'return_reason', 'note', 'model_pdf', 'entity') as $col) {
			$this->assertStringContainsString($col, $d, 'column '.$col);
		}
		$this->assertStringNotContainsString('id_number', $d);
		$this->assertStringContainsString('uk_pharmacy_dispense_ref', file_get_contents($sqlDir.'llx_pharmacy_dispense.key.sql'));

		$l = file_get_contents($sqlDir.'llx_pharmacy_dispense_line.sql');
		foreach (array('fk_dispense', 'fk_prescription_line', 'fk_product', 'product_ref', 'label', 'qty', 'qty_unit', 'is_stock', 'batch_note') as $col) {
			$this->assertStringContainsString($col, $l, 'line column '.$col);
		}

		$seq = file_get_contents($sqlDir.'llx_pharmacy_dispense_sequence.sql');
		$this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $seq);
		$this->assertStringContainsString('ENGINE=innodb', $seq);

		foreach (glob($sqlDir.'*.sql') as $file) {
			$base = basename($file);
			$this->assertTrue(strpos($base, 'llx_') === 0 || strpos($base, 'data') === 0, $base.' would be ignored by _load_tables');
		}
	}

	/**
	 * Dispense class: fetch/search shape, no DELETE anywhere in the module
	 * (red line §5.4), audit actions PHARMACY_*.
	 */
	public function testDispenseClassAndPages()
	{
		$cls = file_get_contents(__DIR__.'/../../class/dispense.class.php');
		$this->assertStringContainsString('class Dispense', $cls);
		$this->assertStringContainsString('function fetch(', $cls);
		$this->assertStringContainsString('function search(', $cls);
		$this->assertStringNotContainsString('DELETE FROM', $cls, 'dispense sheets are never deleted');

		$alert = file_get_contents(__DIR__.'/../../class/pharmacyexpiryalert.class.php');
		$this->assertStringContainsString('function collect(', $alert);
		$this->assertStringContainsString('PHARMACY_EXPIRY_NOTIFY', $alert);
		$this->assertStringContainsString("!== '1'", $alert, 'push only when explicitly enabled');

		$card = file_get_contents(__DIR__.'/../../card.php');
		$this->assertStringContainsString("hasRight('pharmacy', 'read')", $card);
		$this->assertStringContainsString("'PHARMACY_READ'", $card, 'audit on open');
		$this->assertStringContainsString('patient_summary_banner(', $card, 'navigation convention');

		$list = file_get_contents(__DIR__.'/../../list.php');
		$this->assertTrue(strpos($list, '<form method="GET"') < strpos($list, 'print_barre_liste('), 'form before print_barre_liste');
		$this->assertStringContainsString("(int) GETPOST('page', 'int')", $list);

		$expiry = file_get_contents(__DIR__.'/../../expiry.php');
		$this->assertStringContainsString("hasRight('pharmacy', 'read')", $expiry);

		$lib = file_get_contents(__DIR__.'/../../lib/pharmacy.lib.php');
		foreach (array('PHARMACY_STATUS_PENDING', 'PHARMACY_STATUS_DISPENSED', 'PHARMACY_STATUS_RETURNED') as $c) {
			$this->assertStringContainsString("define('".$c."'", $lib);
		}
	}

	/**
	 * Lang files: zh_CN and en_US cover the same keys, in the same order.
	 */
	public function testLangFilesInSync()
	{
		$zh = file(__DIR__.'/../../langs/zh_CN/pharmacy.lang');
		$en = file(__DIR__.'/../../langs/en_US/pharmacy.lang');
		$keys = function ($lines) {
			$out = array();
			foreach ($lines as $line) {
				if (preg_match('/^([A-Za-z0-9_]+)=/', $line, $m)) {
					$out[] = $m[1];
				}
			}
			return $out;
		};
		$this->assertSame($keys($zh), $keys($en), 'zh_CN and en_US keys must match 1:1');
	}
}
