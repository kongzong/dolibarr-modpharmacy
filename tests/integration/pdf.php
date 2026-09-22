<?php
/* Copyright (C) 2026 modPharmacy contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render the dispense-sheet layout (pdf_fy) with real TCPDF, isolated from
 * the database (stub object, in-memory company). Writes fixtures to
 * temp/pdf-acceptance/ and checks page counts / text presence.
 * Run: php tests/integration/pdf.php
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});
date_default_timezone_set('Asia/Shanghai');
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_DOCUMENT_ROOT') ?: dirname(__DIR__, 4));
define('DOL_URL_ROOT', '');
define('DOL_MAIN_URL_ROOT', 'http://localhost');
define('DOL_VERSION', '22.0.4');
define('DOL_DATA_ROOT', dirname(__DIR__, 2).'/temp/pdf-runtime');
define('TCPDF_PATH', DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/');
define('TCPDI_PATH', DOL_DOCUMENT_ROOT.'/includes/tcpdi/');
define('MAIN_DB_PREFIX', 'llx_');

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/conf.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';

$conf = new Conf();
$conf->entity = 1;
$conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT, 'custom' => dirname(__DIR__, 3));
$conf->file->dol_url_root = array('main' => '', 'custom' => '/custom');
$conf->global = (object) array(
	'MAIN_PDF_FORMAT' => 'A4', 'MAIN_DISABLE_TCPDI' => 1, 'TCPDF_THROW_ERRORS_INSTEAD_OF_DIE' => 1,
);
$outputDir = getenv('PHARMACY_PDF_OUTPUT') ?: dirname(__DIR__, 2).'/temp/pdf-acceptance';
$conf->pharmacy = (object) array('dir_output' => $outputDir, 'enabled' => 1);
if (dol_mkdir($outputDir) < 0) {
	throw new RuntimeException('Cannot create PDF fixture output directory');
}

// dol_include_once() in the templates resolves through $conf->file->dol_document_root; no DB needed
$langs = new Translate('', $conf);
$langs->dir[] = dirname(__DIR__, 2);
$langs->dir[] = dirname(__DIR__, 3).'/patient';
$langs->setDefaultLang('zh_CN');
$langs->load('main', 0, 0, '', 1);
$langs->load('companies', 0, 0, '', 1);
$langs->load('patient', 0, 0, '', 1);
$langs->load('pharmacy', 0, 0, '', 1);

$mysoc = (object) array('name' => '示例中医馆（测试）', 'country_code' => 'CN', 'address' => '', 'phone' => '');
$user = (object) array('id' => 1, 'login' => 'test');
$db = null;

require_once dirname(__DIR__, 2).'/lib/pharmacy.lib.php';
require_once dirname(__DIR__, 2).'/core/modules/pharmacy/doc/pdf_fy.modules.php';

/**
 * Build a stub Dispense-like object with the fields the template reads.
 *
 * @param	string	$ref		Sheet ref
 * @param	int		$status	PHARMACY_STATUS_*
 * @param	array	$lines		Dispense line stubs
 * @param	array	$extra		Overrides
 * @return	object
 */
function fixture($ref, $status, $lines, $extra = array())
{
	$o = (object) array_merge(array(
		'id' => 1, 'ref' => $ref, 'status' => $status, 'fk_patient' => 1,
		'date_dispense' => $status >= 1 ? strtotime('2026-09-22 09:30:00') : null,
		'fk_user_dispense' => 2, 'dispenser_name' => '王药师',
		'patient_name' => '李四', 'card_no' => 'HZ-202609-0002', 'presc_ref' => 'CF-20260921-001',
		'note' => '', 'lines' => $lines,
	), $extra);
	return $o;
}

/**
 * @param	string	$label		Drug label
 * @param	mixed	$qty		Quantity
 * @param	string	$unit		Unit
 * @param	int		$stock		1 = stock line with batch allocation, 0 = non-stock
 * @param	string	$batchNote	"BATCH/sellby, ..." snapshot written by confirm()
 * @return	array
 */
function fyLine($label, $qty, $unit, $stock = 1, $batchNote = null)
{
	return array(
		'fk_product' => $stock ? 100 : null,
		'product_ref' => $stock ? 'P-001' : null,
		'label' => $label,
		'qty' => $qty,
		'qty_unit' => $unit,
		'is_stock' => $stock,
		'batch_note' => $batchNote,
	);
}

$cases = array();
// 1. Dispensed sheet, stock + non-stock lines, batch/expiry columns filled: single page
$lines = array(
	fyLine('阿莫西林胶囊 0.25g×24', 3, '盒', 1, 'AM20250101/2026-08-31, AM20250301/2026-09-30'),
	fyLine('感冒清热颗粒 10g×12', 2, '盒', 1, 'GR20250601/2027-05-31'),
	fyLine('自制剂（非库存）', 1, '瓶', 0),
);
$cases[] = array('name' => 'dispensed', 'object' => fixture('FY-20260922-001', PHARMACY_STATUS_DISPENSED, $lines), 'pages' => 1,
	'must' => array('发药单', 'FY-20260922-001', '李四', 'HZ-202609-0002', 'CF-20260921-001', '阿莫西林胶囊', 'AM20250101', '2026-08-31', 'GR20250601', '非库存', '王药师', '审核', '领药人', 'Rp.'),
	'mustnot' => array('已退回'));
// 2. Returned sheet: 已退货 watermark
$cases[] = array('name' => 'returned', 'object' => fixture('FY-20260922-002', PHARMACY_STATUS_RETURNED, $lines, array('return_reason' => '患者拒收')), 'pages' => 1,
	'must' => array('已退回'), 'mustnot' => array());
// 3. Empty lines: placeholder row
$cases[] = array('name' => 'empty', 'object' => fixture('FY-20260922-003', PHARMACY_STATUS_DISPENSED, array()), 'pages' => 1,
	'must' => array('发药单', 'Rp.'), 'mustnot' => array('已退回'));

$passed = 0;
$failed = 0;
$manifest = array();
foreach ($cases as $c) {
	try {
		$gen = new pdf_fy($db);
		$r = $gen->write_file($c['object'], $langs);
		if ($r <= 0) {
			throw new RuntimeException('write_file returned '.$r.': '.($gen->error ?: ''));
		}
		$file = $gen->result['fullpath'];
		if (!is_file($file) || filesize($file) < 1000) {
			throw new RuntimeException('PDF missing or too small');
		}
		$raw = file_get_contents($file);
		// TCPDF writes page objects uncompressed: count them
		$pages = preg_match_all('#/Type\s*/Page(?!s)#', $raw);
		if (isset($c['pages']) && $pages !== $c['pages']) {
			throw new RuntimeException('expected '.$c['pages'].' page(s), got '.$pages);
		}
		// Text streams are compressed: check content through a decompressed copy
		$text = '';
		if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $raw, $m)) {
			foreach ($m[1] as $s) {
				$d = @gzuncompress($s);
				if ($d !== false) {
					$text .= $d;
				}
			}
		}
		// stsongstdlight (CID, UniGB-UCS2-H): TCPDF writes the text as raw UTF-16BE bytes inside
		// (...) string operands, escaping \ ( ) as in PDF string syntax. Match the same bytes.
		$pdfBytes = function ($s) {
			$b = mb_convert_encoding($s, 'UTF-16BE', 'UTF-8');
			return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $b);
		};
		foreach ($c['must'] as $needle) {
			if (strpos($text, $pdfBytes($needle)) === false && strpos($text, $needle) === false) {
				throw new RuntimeException('missing text: '.$needle);
			}
		}
		foreach ($c['mustnot'] as $needle) {
			if (strpos($text, $pdfBytes($needle)) !== false) {
				throw new RuntimeException('unexpected text: '.$needle);
			}
		}
		$manifest[] = array('name' => $c['name'], 'file' => $file, 'pages' => $pages);
		echo 'PASS  '.$c['name'].' ('.$pages.' page(s), '.basename($file).")\n";
		$passed++;
	} catch (Throwable $e) {
		echo 'FAIL  '.$c['name'].' - '.$e->getMessage()."\n";
		$failed++;
	}
}
file_put_contents($outputDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
