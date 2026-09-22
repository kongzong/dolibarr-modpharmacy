<?php
/* Copyright (C) 2026  modPharmacy contributors
 *
 * Minimal test runner: executes the module's PHPUnit-style tests without
 * requiring PHPUnit (DoliWamp has none). It provides a small TestCase shim
 * and calls every public test* method of each tests/unit class.
 *
 * Usage: php tests/run_all.php
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}

// ---------------------------------------------------------------- TestCase shim
if (!class_exists('PHPUnit\Framework\TestCase')) {
	eval('
	namespace PHPUnit\Framework;
	class TestCase {
		public static function assertTrue($cond, $msg = "") { if (!$cond) { throw new \Exception("assertTrue failed: ".$msg); } return true; }
		public static function assertFalse($cond, $msg = "") { if ($cond) { throw new \Exception("assertFalse failed: ".$msg); } return true; }
		public static function assertSame($exp, $act, $msg = "") { if ($exp !== $act) { throw new \Exception("assertSame failed: ".var_export($exp, true)." !== ".var_export($act, true)." ".$msg); } return true; }
		public static function assertEquals($exp, $act, $msg = "") { if ($exp != $act) { throw new \Exception("assertEquals failed: ".$msg); } return true; }
		public static function assertNotEquals($exp, $act, $msg = "") { if ($exp == $act) { throw new \Exception("assertNotEquals failed"); } return true; }
		public static function assertRegExp($re, $s, $msg = "") { if (!preg_match($re, $s)) { throw new \Exception("assertRegExp failed: ".$msg); } return true; }
		public static function assertFileExists($f, $msg = "") { if (!file_exists($f)) { throw new \Exception("assertFileExists failed: ".$f." ".$msg); } return true; }
		public static function assertStringContainsString($needle, $hay, $msg = "") { if (strpos($hay, $needle) === false) { throw new \Exception("assertStringContainsString failed: ".$needle." ".$msg); } return true; }
		public static function assertStringNotContainsString($needle, $hay, $msg = "") { if (strpos($hay, $needle) !== false) { throw new \Exception("assertStringNotContainsString failed: ".$needle." ".$msg); } return true; }
		public static function assertCount($n, $a, $msg = "") { if (count($a) != $n) { throw new \Exception("assertCount failed"); } return true; }
		public static function assertGreaterThan($exp, $act, $msg = "") { if (!($act > $exp)) { throw new \Exception("assertGreaterThan failed: ".var_export($act, true)." <= ".var_export($exp, true)." ".$msg); } return true; }
		public static function assertInstanceOf($c, $o, $msg = "") { if (!($o instanceof $c)) { throw new \Exception("assertInstanceOf failed: ".$c); } return true; }
		public function expectException($c) { $this->_expectedException = $c; }
		public function expectExceptionMessage($m) { $this->_expectedMessage = $m; }
		public function __call($name, $args) { throw new \Exception("Unknown assertion ".$name); }
		public $_expectedException = null;
	}
	');
}

// ---------------------------------------------------------------- stub Dolibarr helpers used by tested code
if (!defined('DOL_DOCUMENT_ROOT')) {
	define('DOL_DOCUMENT_ROOT', dirname(dirname(__DIR__)).'/..'); // htdocs/
}
if (!function_exists('getDolGlobalString')) {
	function getDolGlobalString($k, $d = '') { return $d; }
}
if (!function_exists('getDolGlobalInt')) {
	function getDolGlobalInt($k, $d = 0) { return $d; }
}
if (!function_exists('dol_syslog')) {
	function dol_syslog($m, $l = 0) {}
}
if (!function_exists('dol_now')) {
	function dol_now($mode = 'auto') { return time(); }
}
if (!function_exists('dol_time_plus_duree')) {
	function dol_time_plus_duree($time, $duration, $type) { return $time + 86400 * (int) $duration; }
}
if (!function_exists('dol_include_once')) {
	function dol_include_once($relpath) {
		$tries = array(DOL_DOCUMENT_ROOT.$relpath, DOL_DOCUMENT_ROOT.'/custom'.$relpath);
		foreach ($tries as $path) {
			if (file_exists($path)) {
				require_once $path;
				return true;
			}
		}
		die('dol_include_once: file not found '.$relpath."\n");
	}
}
$conf = new stdClass();
$conf->file = new stdClass();
$conf->file->instance_unique_id = 'test-instance-key';
$conf->entity = 1;

$testFiles = array('PharmacyTest.php', 'PharmacyNumberingTest.php');

$pass = 0; $fail = 0;
foreach ($testFiles as $file) {
	require_once __DIR__.'/unit/'.$file;
	$class = basename($file, '.php');
	$ref = new ReflectionClass($class);
	foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
		if (strncmp($method->name, 'test', 4) !== 0) {
			continue;
		}
		$instance = $ref->newInstance();
		try {
			$method->invoke($instance);
			echo "PASS  ".$class."::".$method->name."\n";
			$pass++;
		} catch (Throwable $e) {
			$short = get_class($e);
			$expected = !empty($instance->_expectedException);
			if ($expected && $e instanceof $instance->_expectedException) {
				echo "PASS  ".$class."::".$method->name." (expected exception)\n";
				$pass++;
			} else {
				echo "FAIL  ".$class."::".$method->name." - ".$short.": ".$e->getMessage()."\n";
				$fail++;
			}
		}
	}
}
echo "\nResult: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
