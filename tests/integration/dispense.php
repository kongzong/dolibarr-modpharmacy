<?php
/* Copyright (C) 2026 modPharmacy contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Real-MariaDB dispense flow test: seed a product with three batches
 * (different sell-by dates) and a signed prescription, then run
 * createFromPrescription -> confirm -> returnSheet and verify FEFO
 * allocation, idempotency, fail-closed shortage and reverse booking.
 * Run: php tests/integration/dispense.php
 * Uses only randomly prefixed fixture tables (pharmacy_test_<hex>_*),
 * all dropped in finally. Credentials from PHARMACY_TEST_DB_* env vars
 * or, on the DoliWamp box, htdocs/conf/conf.php; never printed.
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}
$prefix = 'pharmacy_test_'.bin2hex(random_bytes(6)).'_';
if (!preg_match('/^pharmacy_test_[a-f0-9]{12}_$/D', $prefix)) {
	die('Invalid isolated table prefix');
}
define('MAIN_DB_PREFIX', $prefix);
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_DOCUMENT_ROOT') ?: dirname(__DIR__, 4));
$testBaseDir = getenv('PHARMACY_TEST_TMPDIR') ?: dirname(__DIR__, 2).'/temp';
$testDir = $testBaseDir.'/pharmacy_dispense_'.bin2hex(random_bytes(6));
date_default_timezone_set('UTC');

$conf = (object) array(
	'entity' => 1,
	'db' => (object) array('character_set' => 'utf8mb4', 'dolibarr_main_db_collation' => 'utf8mb4_unicode_ci'),
);
function dol_syslog($message, $level = 0, $indent = 0) {}
function getDolGlobalString($key, $default = '') { return $default; }
function getDolGlobalInt($key, $default = 0) { return $default; }

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/db/mysqli.class.php';
if (!function_exists('dol_include_once')) {
	function dol_include_once($relpath) {
		$tries = array(DOL_DOCUMENT_ROOT.$relpath, DOL_DOCUMENT_ROOT.'/custom'.$relpath);
		foreach ($tries as $path) {
			if (file_exists($path)) {
				require_once $path;
				return true;
			}
		}
		fwrite(STDERR, 'dol_include_once: file not found '.$relpath."\n");
		exit(254);
	}
}
require_once dirname(__DIR__, 2).'/class/dispense.class.php';
require_once dirname(__DIR__, 2).'/class/pharmacynumbering.class.php';
dol_include_once('/prescription/class/prescriptionsheet.class.php');
dol_include_once('/patient/lib/patient.lib.php');

// DoliDBMysqli::query() ignores the resultset argument and reports affected
// rows from the connection; the fixture tables are private, so keep the
// core count intact by wrapping the mysqli connection.
class PharmacyTestDb extends DoliDBMysqli
{
	/** @var int */
	private $fixtureRows = 0;

	public function affected_rows($resultset = null)
	{
		return $this->fixtureRows;
	}
	public function query($query, $usesavepoint = 0, $type = 'auto', $result_mode = 0)
	{
		$result = parent::query($query, $usesavepoint, $type, $result_mode);
		if ($result && is_object($result) && !empty($result->insert_id)) {
			$this->fixtureRows = 1;
		} else {
			$this->fixtureRows = $this->db->affected_rows;
		}
		return $result;
	}
}

function pxTestConnect()
{
	if (getenv('PHARMACY_TEST_DB_NAME') !== false) {
		$host = getenv('PHARMACY_TEST_DB_HOST') ?: '127.0.0.1';
		$name = getenv('PHARMACY_TEST_DB_NAME');
		$login = getenv('PHARMACY_TEST_DB_USER');
		$password = getenv('PHARMACY_TEST_DB_PASSWORD');
		$port = (int) (getenv('PHARMACY_TEST_DB_PORT') ?: 3306);
	} else {
		require DOL_DOCUMENT_ROOT.'/conf/conf.php';
		$host = $dolibarr_main_db_host;
		$name = $dolibarr_main_db_name;
		$login = $dolibarr_main_db_user;
		$password = $dolibarr_main_db_pass;
		$port = (int) ($dolibarr_main_db_port ?: 3306);
	}
	try {
		$connection = new PharmacyTestDb('mysqli', $host, $login, $password, $name, $port);
	} catch (Throwable $error) {
		throw new RuntimeException('Cannot connect to the integration test database');
	}
	if (!$connection->connected || !$connection->database_selected) {
		throw new RuntimeException('Cannot connect to the integration test database');
	}
	pxTestQuery($connection, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	return $connection;
}
function pxTestQuery($connection, $sql)
{
	$result = $connection->query($sql);
	if (!$result) {
		throw new RuntimeException('Integration fixture SQL failed: '.$connection->lasterrno().' '.$connection->lasterror());
	}
	return $result;
}
function pxTestScalar($connection, $sql)
{
	$result = pxTestQuery($connection, $sql);
	$row = $connection->fetch_row($result);
	$connection->free($result);
	return $row ? $row[0] : null;
}

$passed = 0;
$failed = 0;
$createdTables = array();
$db = null;

function check($condition, $message)
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
function testCase($name, $callback)
{
	global $passed;
	$callback();
	$passed++;
	echo 'PASS  '.$name."\n";
}

// Minimal stubs for the code paths Dispense touches (audit + print helpers)
if (!function_exists('patient_audit')) {
	function patient_audit($db, $fkPatient, $action, $user, $details) { return true; }
}
// Core DB layer calls the static helper as if it were global (historical quirk
// of Dolibarr); a no-op stub keeps fixture queries from aborting.
if (!function_exists('getCallerInfoString')) {
	function getCallerInfoString() { return ''; }
}
if (!function_exists('price2num')) {
	function price2num($value, $alt = '') { return (float) $value; }
}
if (!function_exists('dol_substr')) {
	function dol_substr($s, $len) { return mb_substr((string) $s, 0, (int) $len); }
}
if (!function_exists('dol_now')) {
	function dol_now($mode = 'auto') { return time(); }
}
if (!function_exists('dol_mktime')) {
	function dol_mktime($hour = -1, $min = -1, $sec = -1, $month = -1, $day = -1, $year = -1, $date = '', $tz = 'UTC') { return time(); }
}
if (!function_exists('currentTheme')) {
	function currentTheme() { return 'elthandy'; }
}
if (!function_exists('dol_print_date')) {
	function dol_print_date($time, $format) { return date('Y-m-d', (int) $time); }
}
if (!function_exists('dol_time_plus_duree')) {
	function dol_time_plus_duree($time, $d, $t) { return $time + 86400 * (int) $d; }
}
if (!function_exists('dol_print_error')) {
	function dol_print_error($db = null, $error = '', $errors = null) { return ''; }
}
if (!function_exists('getEntity')) {
	function getEntity($e) { return 1; }
}
if (!function_exists('isModEnabled')) {
	function isModEnabled($module) { return false; }
}
// The real User class (user.class.php) for the type-hinted DAO methods.
dol_include_once('/user/class/user.class.php');
dol_include_once('/core/class/hookmanager.class.php');

try {
	check(is_dir($testBaseDir) || mkdir($testBaseDir, 0700, true), 'Cannot create the test temp directory');
	$db = pxTestConnect();

	// ---- Fixture schema (isolated prefixed copies of the real tables) ----
	$fixtures = array(
		// sequence + dispense + lines from module sql
		'sequence' => str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_pharmacy_dispense_sequence.sql')),
	);
	foreach (array('pharmacy_dispense', 'pharmacy_dispense_line') as $t) {
		$createdTables[] = MAIN_DB_PREFIX.$t;
		pxTestQuery($db, str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_'.$t.'.sql')));
		$keys = str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_'.$t.'.key.sql'));
		foreach (array_filter(array_map('trim', explode("\n", $keys))) as $line) {
			if (strpos($line, 'ALTER TABLE') === 0) {
				try {
					pxTestQuery($db, $line);
				} catch (Throwable $e) { /* duplicate index fine */ }
			}
		}
	}
	pxTestQuery($db, str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_pharmacy_dispense_sequence.sql')));
	$createdTables[] = MAIN_DB_PREFIX.'pharmacy_dispense_sequence';

	// Minimal prescription + patient + product/stock tables mirroring core shapes
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'prescription (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, ref VARCHAR(32) NOT NULL, presc_type VARCHAR(3), fk_patient INTEGER NOT NULL, fk_medrecord INTEGER DEFAULT NULL, fk_doctor INTEGER NOT NULL, fk_department INTEGER DEFAULT NULL, date_presc DATETIME, diagnosis_text TEXT, doses INTEGER, decoct_mode VARCHAR(32), usage_note TEXT, note TEXT, allergy_override_reason TEXT, status SMALLINT NOT NULL DEFAULT 0, date_issued DATETIME, fk_user_issue INTEGER, date_dispensed DATETIME, fk_user_dispensed INTEGER, void_reason TEXT, date_void DATETIME, fk_user_void INTEGER, model_pdf VARCHAR(32), last_main_doc VARCHAR(255), fk_user_creat INTEGER, fk_user_modif INTEGER, date_creation DATETIME, tms DATETIME, UNIQUE KEY uk_ref (ref)) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'prescription';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'prescription_line (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, fk_prescription INTEGER NOT NULL, position SMALLINT DEFAULT 0, fk_product INTEGER DEFAULT NULL, product_ref VARCHAR(128), label VARCHAR(255), qty DECIMAL(10,3), qty_unit VARCHAR(16), decoct_code VARCHAR(16), dose DECIMAL(10,3), dose_unit VARCHAR(32), route_code VARCHAR(16), freq_code VARCHAR(16), days INTEGER, sig_note VARCHAR(255), allergy_hit SMALLINT DEFAULT 0) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'prescription_line';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'patient_profile (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, fk_soc INTEGER NOT NULL, card_no VARCHAR(32)) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'patient_profile';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'societe (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, nom VARCHAR(128)) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'societe';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'product (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, ref VARCHAR(64), ref_ext VARCHAR(128) DEFAULT NULL, label VARCHAR(255), description TEXT, url VARCHAR(255), note_public TEXT, note TEXT, customcode VARCHAR(64), fk_country INTEGER DEFAULT NULL, fk_state INTEGER DEFAULT NULL, lifetime DOUBLE DEFAULT 0, qc_frequency DOUBLE DEFAULT 0, price DOUBLE DEFAULT 0, price_ttc DOUBLE DEFAULT 0, price_min DOUBLE DEFAULT 0, price_min_ttc DOUBLE DEFAULT 0, price_base_type INTEGER DEFAULT 0, cost_price DOUBLE DEFAULT 0, default_vat_code VARCHAR(16), tva_tx DOUBLE DEFAULT 0, recuperableonly INTEGER DEFAULT 0, localtax1_tx DOUBLE DEFAULT 0, localtax2_tx DOUBLE DEFAULT 0, localtax1_type INTEGER DEFAULT 0, localtax2_type INTEGER DEFAULT 0, tosell VARCHAR(32) DEFAULT NULL, tobuy VARCHAR(32) DEFAULT NULL, fk_product_type INTEGER DEFAULT 0, duration INTEGER DEFAULT 0, fk_default_warehouse INTEGER DEFAULT NULL, fk_default_workstation INTEGER DEFAULT NULL, seuil_stock_alerte INTEGER DEFAULT 0, canvas TEXT, net_measure DOUBLE DEFAULT 0, net_measure_units VARCHAR(16), weight DOUBLE DEFAULT 0, weight_units VARCHAR(16), length DOUBLE DEFAULT 0, length_units VARCHAR(16), width DOUBLE DEFAULT 0, width_units VARCHAR(16), height DOUBLE DEFAULT 0, height_units VARCHAR(16), last_main_doc VARCHAR(255), surface DOUBLE DEFAULT 0, surface_units VARCHAR(16), volume DOUBLE DEFAULT 0, volume_units VARCHAR(16), barcode VARCHAR(255), fk_barcode_type INTEGER DEFAULT NULL, finished SMALLINT DEFAULT 0, fk_default_bom INTEGER DEFAULT NULL, mandatory_period INTEGER DEFAULT 0, packaging TEXT, accountancy_code_buy VARCHAR(32), accountancy_code_buy_intra VARCHAR(32), accountancy_code_buy_export VARCHAR(32), accountancy_code_sell VARCHAR(32), accountancy_code_sell_intra VARCHAR(32), accountancy_code_sell_export VARCHAR(32), pmp DOUBLE DEFAULT 0, datec DATETIME, tms DATETIME, import_key VARCHAR(128), desiredstock DOUBLE DEFAULT 0, tobatch SMALLINT DEFAULT 0, sell_or_eat_by_mandatory SMALLINT DEFAULT 0, batch_mask VARCHAR(64), fk_unit INTEGER DEFAULT NULL, fk_price_expression INTEGER DEFAULT NULL, price_autogen INTEGER DEFAULT 0, stockable_product SMALLINT DEFAULT 2, model_pdf VARCHAR(64), price_label VARCHAR(32), stock INTEGER DEFAULT 0, type SMALLINT DEFAULT 0, status_batch SMALLINT DEFAULT 0, fk_brand INTEGER DEFAULT NULL, fk_fournisseur_last INTEGER DEFAULT NULL) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'product';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'entrepot (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, lieu VARCHAR(64), ref VARCHAR(255) NOT NULL, statut SMALLINT DEFAULT 1) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'entrepot';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'product_stock (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, fk_product INTEGER NOT NULL, fk_entrepot INTEGER NOT NULL, reel DOUBLE DEFAULT 0) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'product_stock';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'product_lot (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, fk_product INTEGER NOT NULL, batch VARCHAR(128) NOT NULL, eatby DATETIME DEFAULT NULL, sellby DATETIME DEFAULT NULL) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'product_lot';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'product_batch (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, fk_product_stock INTEGER NOT NULL, batch VARCHAR(128) NOT NULL, qty DOUBLE DEFAULT 0) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'product_batch';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'stock_mouvement (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, fk_product INTEGER NOT NULL, fk_entrepot INTEGER NOT NULL, value DOUBLE NOT NULL, type_mouvement SMALLINT NOT NULL, fk_user_author INTEGER DEFAULT NULL, label VARCHAR(255), batch VARCHAR(128) DEFAULT NULL, inventorycode VARCHAR(128) DEFAULT NULL, price DOUBLE DEFAULT 0, eatby DATETIME DEFAULT NULL, sellby DATETIME DEFAULT NULL, fk_origin INTEGER DEFAULT NULL, origintype VARCHAR(32) DEFAULT NULL, fk_projet INTEGER DEFAULT NULL, datem DATETIME) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'stock_mouvement';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'user (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, login VARCHAR(64)) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'user';
	// MouvementStock::_create writes through its own SQL; verify it works
	// against these shapes by inserting a fake user and a warehouse.

	// Real User object (empty constructor: no DB access, $id set below)
	$user = new User($db);
	$user->id = 1;
	$user->login = 'test';
	// HookManager instances created by CommonObject need a real $db;
	// initHooks() on an empty $conf->modules_parts is a cheap no-op.
	$conf->modules_parts = array('hooks' => array());
	// Core hooks run on getNomUrl() (via MouvementStock label building) and on
	// any CommonObject method; give them a live manager with a no-op context.
	$GLOBALS['hookmanager'] = new HookManager($db);
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'user (rowid, login) VALUES (1, "test")');
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'entrepot (rowid, entity, lieu, ref, statut) VALUES (1, 1, "药房", "main", 1)');
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'patient_profile (rowid, entity, fk_soc, card_no) VALUES (1, 1, 1, "HZ-T-0001")');
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'societe (rowid, entity, nom) VALUES (1, 1, "患者一")');

	// Product 1 with three batches: B-old sells first (FEFO), B-mid, B-new
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'product (rowid, entity, ref, label, stockable_product, type) VALUES (1, 1, "MED-001", "阿莫西林胶囊", 1, 0)');
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'product_stock (rowid, fk_product, fk_entrepot, reel) VALUES (1, 1, 1, 100)');
	foreach (array(
		array('B-OLD', '2026-10-01', 30),
		array('B-MID', '2027-01-15', 40),
		array('B-NEW', '2027-06-01', 30),
	) as $b) {
		pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'product_lot (entity, fk_product, batch, eatby, sellby) VALUES (1, 1, "'.$b[0].'", "'.$b[1].'", "'.$b[1].'")');
		pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'product_batch (fk_product_stock, batch, qty) VALUES (1, "'.$b[0].'", '.$b[2].')');
	}

	// Signed prescription with two stock lines + one free-text line
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'prescription (entity, ref, presc_type, fk_patient, fk_doctor, date_presc, status, date_creation, tms) VALUES (1, "CF-T-001", "WM", 1, 1, NOW(), 1, NOW(), NOW())');
	pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'prescription_line (fk_prescription, position, fk_product, product_ref, label, qty, qty_unit) VALUES (1, 0, 1, "MED-001", "阿莫西林胶囊", 50, "粒"), (1, 1, 1, "MED-001", "阿莫西林胶囊(第二行)", 20, "粒")');
	$presc = new PrescriptionSheet($db);
	$presc->fetch(1);
	$dao = new Dispense($db);

	// Fake MouvementStock: core class writes llx_stock_mouvement through
	// DoliDB; it also maintains product_stock.reel. We let it run but the
	// stock update SQL uses product_stock.reel which our fixture keeps.
	// (MouvementStock writes stock_mouvement + product_stock + product_batch.)

	testCase('createFromPrescription refuses a draft and a second live sheet', function () use ($db, $dao, $presc, $user) {
		$presc->status = 0;
		check($dao->createFromPrescription($user, $presc, 1) === -2, 'draft accepted');
		$presc->status = 1;
		check($dao->createFromPrescription($user, $presc, 1) === 1, 'first create failed: '.$dao->error);
		check($dao->ref === 'FY-20000101-001' || preg_match('/^FY-[0-9]{8}-001$/', $dao->ref), 'unexpected ref '.$dao->ref);
		$second = new Dispense($db);
		check($second->createFromPrescription($user, $presc, 1) === -2, 'second live sheet accepted');
		check($second->error === 'PharmacyErrAlreadyPending', 'wrong gate error: '.$second->error);
		// lines snapshot: 2 lines, stock flags correct
		check(count($dao->lines) === 2, 'line count');
		check($dao->lines[0]['is_stock'] === 1 && $dao->lines[1]['is_stock'] === 1, 'stock flags');
		$dao->fetch($dao->id);
		check(count($dao->lines) === 2, 'lines lost after refetch');
	});

	testCase('confirm: FEFO allocation across batches, idempotent on repeat', function () use ($db, $dao, $user) {
		$result = $dao->confirm($user);
		check($result === 1, 'confirm failed: '.$dao->error);
		check((int) $dao->status === PHARMACY_STATUS_DISPENSED, 'status not dispensed');
		// B-OLD exhausted (30), 20 more from B-MID on line 1; line 2 takes 20 from B-MID
		check(strpos($dao->lines[0]['batch_note'], 'B-OLD') === 0, 'FEFO did not start at the oldest batch: '.$dao->lines[0]['batch_note']);
		check(strpos($dao->lines[0]['batch_note'], 'B-MID') !== false, 'second batch not used on line 1');
		check(strpos($dao->lines[1]['batch_note'], 'B-MID') === 0, 'line 2 should come fully from B-MID: '.$dao->lines[1]['batch_note']);
		// stock table updated by core MouvementStock: 100 - 50 - 20 = 30
		$reelVal = (float) pxTestScalar($db, 'SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid = 1');
		check($reelVal === 30.0, 'stock not deducted to 30 (got '.$reelVal.')');
		// idempotent repeat
		$again = new Dispense($db);
		$again->fetch($dao->id);
		check($again->confirm($user) === 1, 'idempotent repeat not 1');
		check((float) pxTestScalar($db, 'SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid = 1') === 30.0, 'idempotent repeat deducted stock again');
		// prescription pushed to dispensed
		check((int) pxTestScalar($db, 'SELECT status FROM '.MAIN_DB_PREFIX.'prescription WHERE rowid = 1') === 2, 'prescription not dispensed');
	});

	testCase('shortage fails closed: fresh sheet on same product but insufficient stock', function () use ($db, $user) {
		// Prescription 2 wants 100 but only 30 left -> create would be gated
		// because prescription 1 has a live sheet; use prescription 2 instead.
		pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'prescription (entity, ref, presc_type, fk_patient, fk_doctor, date_presc, status, date_creation, tms) VALUES (1, "CF-T-002", "WM", 1, 1, NOW(), 1, NOW(), NOW())');
		pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX.'prescription_line (fk_prescription, position, fk_product, product_ref, label, qty, qty_unit) VALUES (2, 0, 1, "MED-001", "阿莫西林胶囊", 100, "粒")');
		$p2 = new PrescriptionSheet($db);
		$p2->fetch(2);
		$d2 = new Dispense($db);
		check($d2->createFromPrescription($user, $p2, 1) === 1, 'create 2 failed: '.$d2->error);
		check($d2->confirm($user) === -1, 'shortage confirm did not fail');
		check($d2->error === 'PharmacyErrStockShort', 'wrong shortage error: '.$d2->error);
		// fail-closed: no stock moved, sheet still pending
		check((float) pxTestScalar($db, 'SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid = 1') === 30.0, 'shortage moved stock');
		$d2->fetch($d2->id);
		check((int) $d2->status === PHARMACY_STATUS_PENDING, 'shortage sheet not pending');
		check((int) pxTestScalar($db, 'SELECT status FROM '.MAIN_DB_PREFIX.'prescription WHERE rowid = 2') === 1, 'shortage moved the prescription');
	});

	testCase('return: reverse booking per batch, prescription back to issued', function () use ($db, $dao, $user) {
		check($dao->returnSheet($user, '开错药') === 1, 'return failed: '.$dao->error);
		check((int) $dao->status === PHARMACY_STATUS_RETURNED, 'sheet not returned');
		check((float) pxTestScalar($db, 'SELECT reel FROM '.MAIN_DB_PREFIX.'product_stock WHERE rowid = 1') === 100.0, 'stock not restored to 100');
		check((int) pxTestScalar($db, 'SELECT status FROM '.MAIN_DB_PREFIX.'prescription WHERE rowid = 1') === 1, 'prescription not back to issued');
		// after return a new sheet can be created
		$p1 = new PrescriptionSheet($db);
		$p1->fetch(1);
		$d3 = new Dispense($db);
		check($d3->createFromPrescription($user, $p1, 1) === 1, 're-create after return failed: '.$d3->error);
	});
} catch (Throwable $error) {
	$failed++;
	echo 'FAIL  '.$error->getMessage()."\n";
} finally {
	if ($db) {
		while ($db->transaction_opened > 0) {
			$db->rollback();
		}
		foreach (array_reverse($createdTables) as $table) {
			try {
				pxTestQuery($db, 'DROP TABLE IF EXISTS '.$table);
			} catch (Throwable $e) { /* keep dropping */ }
		}
		$db->close();
	}
}
echo "\nResult: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
