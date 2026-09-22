<?php
/* Copyright (C) 2026 modPharmacy contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Concurrent dispense-number reservation against a real MySQL/MariaDB.
 * Run: php tests/integration/numbering.php
 * Uses only randomly prefixed fixture tables (pharmacy_test_<hex>_*),
 * all dropped in finally. Credentials from PHARMACY_TEST_DB_* env vars
 * or, on the DoliWamp box, htdocs/conf/conf.php; never printed.
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}
$workerMode = isset($argv[1]) && $argv[1] === '--worker';
$prefix = $workerMode ? $argv[2] : 'pharmacy_test_'.bin2hex(random_bytes(6)).'_';
if (!preg_match('/^pharmacy_test_[a-f0-9]{12}_$/D', $prefix)) {
	die('Invalid isolated table prefix');
}
define('MAIN_DB_PREFIX', $prefix);
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_DOCUMENT_ROOT') ?: dirname(__DIR__, 4));
$testBaseDir = getenv('PHARMACY_TEST_TMPDIR') ?: dirname(__DIR__, 2).'/temp';
$testDir = $workerMode ? $argv[3] : $testBaseDir.'/pharmacy_numbering_'.bin2hex(random_bytes(6));
date_default_timezone_set('UTC');

$conf = (object) array(
	'entity' => 1,
	'db' => (object) array('character_set' => 'utf8mb4', 'dolibarr_main_db_collation' => 'utf8mb4_unicode_ci'),
);
function dol_syslog($message, $level = 0, $indent = 0) {}
function getDolGlobalString($key, $default = '') { return $default; }

require_once DOL_DOCUMENT_ROOT.'/core/db/mysqli.class.php';
require_once dirname(__DIR__, 2).'/class/pharmacynumbering.class.php';

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
		$connection = new DoliDBMysqli('mysqli', $host, $login, $password, $name, $port);
	} catch (Throwable $error) {
		throw new RuntimeException('Cannot connect to the integration test database');
	}
	if (!$connection->connected || !$connection->database_selected) {
		throw new RuntimeException('Cannot connect to the integration test database');
	}
	pxTestQuery($connection, 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
	pxTestQuery($connection, 'SET SESSION innodb_lock_wait_timeout = 10');
	return $connection;
}
function pxTestQuery($connection, $sql)
{
	$result = $connection->query($sql);
	if (!$result) {
		throw new RuntimeException('Integration fixture SQL failed: '.$connection->lasterrno());
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
function pxTestEvent($id, $event, $value = array())
{
	global $testDir;
	$file = $testDir.'/'.$id.'.'.$event;
	file_put_contents($file.'.tmp', json_encode($value));
	rename($file.'.tmp', $file);
}
function pxTestWaitFile($id, $event, $seconds = 20)
{
	global $testDir;
	$deadline = microtime(true) + $seconds;
	$file = $testDir.'/'.$id.'.'.$event;
	do {
		clearstatcache(true, $file);
		if (is_file($file)) {
			return json_decode(file_get_contents($file), true);
		}
		usleep(20000);
	} while (microtime(true) < $deadline);
	throw new RuntimeException('Timed out waiting for worker '.$id.' '.$event);
}

// ---------------------------------------------------------------- worker: mirrors Dispense::create()
if ($workerMode) {
	$id = (int) $argv[4];
	$prefixDay = $argv[5];
	$mode = $argv[6]; // normal | rollback | pause
	$db = null;
	try {
		$db = pxTestConnect();
		pxTestEvent($id, 'ready', array('connection' => (int) pxTestScalar($db, 'SELECT CONNECTION_ID()')));
		if ($mode === 'pause') {
			pxTestWaitFile($id, 'go');
		}
		$db->begin();
		$number = (new PharmacyNumbering($db))->nextReference($prefixDay);
		pxTestEvent($id, 'allocated', array('ref' => $number));
		if ($mode === 'pause') {
			pxTestWaitFile($id, 'release');
		}
		pxTestQuery($db, 'INSERT INTO '.MAIN_DB_PREFIX."pharmacy_dispense (entity, ref, fk_prescription, fk_patient, fk_warehouse, status, date_creation) VALUES (1, '".$db->escape($number)."', ".$id.", ".$id.", 1, 0, NOW())");
		if ($mode === 'rollback') {
			$db->rollback();
		} else {
			$db->commit();
		}
		pxTestEvent($id, 'done', array('ref' => $number, 'mode' => $mode, 'depth' => $db->transaction_opened));
	} catch (Throwable $error) {
		pxTestEvent($id, 'done', array('error' => $error->getMessage(), 'depth' => $db ? $db->transaction_opened : null));
	} finally {
		if ($db) {
			$db->close();
		}
	}
	exit;
}

// ---------------------------------------------------------------- driver
$workers = array();
$db = null;
$passed = 0;
$failed = 0;
$createdTables = array();

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
function startWorker($id, $day, $mode = 'normal')
{
	global $workers, $testDir;
	$command = array(PHP_BINARY, __FILE__, '--worker', MAIN_DB_PREFIX, $testDir, (string) $id, $day, $mode);
	$process = proc_open($command, array(
		0 => array('file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'),
		1 => array('file', $testDir.'/'.$id.'.stdout', 'w'),
		2 => array('file', $testDir.'/'.$id.'.stderr', 'w'),
	), $pipes, null, null, array('bypass_shell' => true, 'create_new_console' => false));
	check(is_resource($process), 'Could not start a PHP worker');
	$workers[$id] = $process;
	return pxTestWaitFile($id, 'ready');
}
function waitForLock($id, $connection)
{
	global $db, $testDir;
	$deadline = microtime(true) + 8;
	$observations = 0;
	do {
		clearstatcache();
		check(!is_file($testDir.'/'.$id.'.allocated'), 'Second save got a number before the first committed');
		check(!is_file($testDir.'/'.$id.'.done'), 'Second save failed before the expected lock wait');
		$statement = pxTestScalar($db, 'SELECT INFO FROM information_schema.PROCESSLIST WHERE ID = '.((int) $connection));
		if (is_string($statement) && strpos($statement, 'INSERT INTO '.MAIN_DB_PREFIX.'pharmacy_dispense_sequence') === 0) {
			if (++$observations >= 3) {
				return;
			}
		} else {
			$observations = 0;
		}
		usleep(50000);
	} while (microtime(true) < $deadline);
	throw new RuntimeException('Second save did not enter an InnoDB lock wait');
}

try {
	if (!is_dir($testBaseDir)) {
		check(mkdir($testBaseDir, 0700, true), 'Could not create the test temp directory');
	}
	check(mkdir($testDir, 0700), 'Could not create an isolated worker directory');
	$db = pxTestConnect();

	pxTestQuery($db, str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_pharmacy_dispense_sequence.sql')));
	$createdTables[] = MAIN_DB_PREFIX.'pharmacy_dispense_sequence';
	pxTestQuery($db, 'CREATE TABLE '.MAIN_DB_PREFIX.'pharmacy_dispense (rowid INTEGER AUTO_INCREMENT PRIMARY KEY, entity INTEGER NOT NULL DEFAULT 1, ref VARCHAR(32) NOT NULL, fk_prescription INTEGER NOT NULL, fk_patient INTEGER NOT NULL, fk_warehouse INTEGER NOT NULL, status SMALLINT NOT NULL DEFAULT 0, date_creation DATETIME, UNIQUE KEY uk_ref (ref)) ENGINE=InnoDB');
	$createdTables[] = MAIN_DB_PREFIX.'pharmacy_dispense';

	testCase('Preview outside a transaction reserves nothing', function () use ($db) {
		$n = new PharmacyNumbering($db);
		check($n->nextReference('FY-20990101-') === 'FY-20990101-001', 'First preview wrong');
		check($n->nextReference('FY-20990101-') === 'FY-20990101-001', 'Preview consumed a number');
		check((int) pxTestScalar($db, 'SELECT COUNT(*) FROM '.MAIN_DB_PREFIX."pharmacy_dispense_sequence WHERE ref_prefix = 'FY-20990101-'") === 0, 'Preview inserted a counter');
	});

	testCase('20 parallel saves of one day are unique and consecutive', function () use ($db) {
		$ids = range(1, 20);
		foreach ($ids as $id) {
			startWorker($id, 'FY-20260920-', 'pause');
		}
		foreach ($ids as $id) {
			pxTestEvent($id, 'go');
		}
		$pending = $ids;
		$deadline = microtime(true) + 60;
		while ($pending && microtime(true) < $deadline) {
			foreach ($pending as $k => $id) {
				clearstatcache();
				if (is_file($GLOBALS['testDir'].'/'.$id.'.allocated')) {
					pxTestEvent($id, 'release');
					unset($pending[$k]);
				}
			}
			usleep(20000);
		}
		check(!$pending, 'Some saves never obtained a number');
		$refs = array();
		foreach ($ids as $id) {
			$done = pxTestWaitFile($id, 'done');
			check(!isset($done['error']), 'Worker '.$id.' failed: '.(isset($done['error']) ? $done['error'] : ''));
			check($done['depth'] === 0, 'Worker '.$id.' left a transaction open');
			$refs[] = $done['ref'];
		}
		sort($refs);
		$expected = array();
		for ($i = 1; $i <= 20; $i++) {
			$expected[] = sprintf('FY-20260920-%03d', $i);
		}
		check($refs === $expected, 'Concurrent numbers are not unique and consecutive: '.implode(',', $refs));
		check((int) pxTestScalar($db, 'SELECT last_value FROM '.MAIN_DB_PREFIX."pharmacy_dispense_sequence WHERE ref_prefix = 'FY-20260920-'") === 20, 'Counter not advanced to 20');
	});

	testCase('Second save waits for the first lock and gets the next number', function () use ($db) {
		startWorker(31, 'FY-20260921-', 'pause');
		pxTestEvent(31, 'go');
		$first = pxTestWaitFile(31, 'allocated');
		$second = startWorker(32, 'FY-20260921-');
		waitForLock(32, $second['connection']);
		pxTestEvent(31, 'release');
		pxTestWaitFile(31, 'done');
		$next = pxTestWaitFile(32, 'done');
		check($first['ref'] === 'FY-20260921-001' && $next['ref'] === 'FY-20260921-002', 'Lock hand-off produced wrong numbers');
	});

	testCase('Rolled-back save frees its number; committed ones are never reused', function () use ($db) {
		startWorker(41, 'FY-20260922-', 'rollback');
		$rolled = pxTestWaitFile(41, 'done');
		check(!isset($rolled['error']) && $rolled['ref'] === 'FY-20260922-001', 'Rollback worker failed');
		startWorker(42, 'FY-20260922-');
		$next = pxTestWaitFile(42, 'done');
		check($next['ref'] === 'FY-20260922-001', 'Number of a rolled-back save was not reused');
		pxTestQuery($db, 'DELETE FROM '.MAIN_DB_PREFIX."pharmacy_dispense WHERE ref = 'FY-20260922-001'");
		startWorker(43, 'FY-20260922-');
		$after = pxTestWaitFile(43, 'done');
		check($after['ref'] === 'FY-20260922-002', 'Deleted committed number was reused');
	});

	testCase('Missing sequence table aborts the transaction instead of returning a number', function () use ($db) {
		pxTestQuery($db, 'DROP TABLE '.MAIN_DB_PREFIX.'pharmacy_dispense_sequence');
		$db->begin();
		$thrown = false;
		try {
			(new PharmacyNumbering($db))->nextReference('FY-20260923-');
		} catch (RuntimeException $error) {
			$thrown = true;
		}
		check($thrown && $db->transaction_opened === 0, 'Missing table did not abort the whole transaction');
		pxTestQuery($db, str_replace('llx_', MAIN_DB_PREFIX, file_get_contents(dirname(__DIR__, 2).'/sql/llx_pharmacy_dispense_sequence.sql')));
	});

	testCase('Every committed dispense has a valid unique number', function () use ($db) {
		check((int) pxTestScalar($db, 'SELECT COUNT(*) FROM '.MAIN_DB_PREFIX."pharmacy_dispense WHERE ref NOT REGEXP '^FY-[0-9]{8}-[0-9]{3,}$'") === 0, 'Invalid number committed');
		check((int) pxTestScalar($db, 'SELECT COUNT(*) - COUNT(DISTINCT ref) FROM '.MAIN_DB_PREFIX.'pharmacy_dispense') === 0, 'Duplicate numbers committed');
	});
} catch (Throwable $error) {
	$failed++;
	echo 'FAIL  '.$error->getMessage()."\n";
	foreach (glob($testDir.'/*.stderr') ?: array() as $log) {
		$content = trim(file_get_contents($log));
		if ($content !== '') {
			echo basename($log).': '.$content."\n";
		}
	}
} finally {
	foreach ($workers as $process) {
		$status = proc_get_status($process);
		if ($status['running']) {
			proc_terminate($process);
		}
		proc_close($process);
	}
	if ($db) {
		while ($db->transaction_opened > 0) {
			$db->rollback();
		}
		foreach (array_reverse($createdTables) as $table) {
			pxTestQuery($db, 'DROP TABLE IF EXISTS '.$table);
		}
		$db->close();
	}
	if (is_dir($testDir) && realpath(dirname($testDir)) === realpath($testBaseDir)
		&& preg_match('/^pharmacy_numbering_[a-f0-9]{12}$/D', basename($testDir))) {
		foreach (glob($testDir.'/*') ?: array() as $file) {
			if (is_file($file)) {
				unlink($file);
			}
		}
		rmdir($testDir);
	}
}
echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
