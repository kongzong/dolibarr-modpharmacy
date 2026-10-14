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
 * \file    htdocs/custom/pharmacy/label.php
 * \brief   Drug-bag labels for one dispense sheet, A4 two-column print
 *          layout, auto window.print(). Usage text is composed from the
 *          prescription line (dose/route/freq/days/sig_note) via live
 *          dictionaries; TCM rows (is_stock=0) get a doses/decoction layout.
 *          Printing writes PHARMACY_PRINT audit with labels=1.
 *          Requires 'pharmacy read'.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/patient/lib/patient.lib.php');
dol_include_once('/pharmacy/lib/pharmacy.lib.php');
dol_include_once('/pharmacy/class/dispense.class.php');
dol_include_once('/prescription/class/prescriptionsheet.class.php');
dol_include_once('/prescription/lib/prescription.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "pharmacy@pharmacy"));

$id = GETPOSTINT('id');
if ($id <= 0 || !$user->hasRight('pharmacy', 'read')) {
	accessforbidden();
}

$object = new Dispense($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans("ErrorRecordNotFound"));
}
if ((int) $object->status === PHARMACY_STATUS_PENDING) {
	accessforbidden($langs->trans("PharmacyLabelNotDispensed"));
}
$object->preparePdfContextPublic();

$lines = is_array($object->lines) ? $object->lines : array();
if (empty($lines)) {
	accessforbidden($langs->trans("ErrorRecordNotFound"));
}

// ---- Prescription context: doses (TCM) + per-line usage from llx_prescription_line
$presc = new PrescriptionSheet($db);
$prescLoaded = ($presc->fetch((int) $object->fk_prescription) > 0);

$dictRoute = prescription_dict_options($db, 'c_prescription_route');
$dictFreq = prescription_dict_options($db, 'c_prescription_freq');
$dictDoseUnit = prescription_dict_options($db, 'c_prescription_dose_unit');
$dictDecoct = prescription_dict_options($db, 'c_prescription_decoct');

$prescLines = array(); // keyed by llx_prescription_line.rowid
if ($prescLoaded) {
	$prefix = $db->prefix();
	$sql = "SELECT rowid, dose, dose_unit, route_code, freq_code, days, sig_note, decoct_code";
	$sql .= " FROM ".$prefix."prescription_line WHERE fk_prescription = ".((int) $object->fk_prescription);
	$resql = $db->query($sql);
	if ($resql) {
		while ($o = $db->fetch_object($resql)) {
			$prescLines[(int) $o->rowid] = $o;
		}
		$db->free($resql);
	}
}

/**
 * Compose the human usage phrase for one dispense line.
 *
 * @param array $line Dispense line (has fk_prescription_line)
 * @return string Usage phrase, '' when nothing is known
 */
function pharmacy_label_sig(array $line)
{
	global $db, $prescLines, $dictRoute, $dictFreq, $dictDoseUnit;

	$pl = null;
	$plid = (int) ($line['fk_prescription_line'] ?? 0);
	if ($plid > 0 && isset($prescLines[$plid])) {
		$pl = $prescLines[$plid];
	}
	$parts = array();

	if ($pl && $pl->dose !== null && $pl->dose !== '') {
		$unit = isset($dictDoseUnit[(string) $pl->dose_unit]) ? $dictDoseUnit[(string) $pl->dose_unit] : (string) $pl->dose_unit;
		$parts[] = '每次 '.rtrim(rtrim((string) $pl->dose, '0'), '.').($unit !== '' ? ' '.$unit : '');
	}
	if ($pl && $pl->route_code !== null && (string) $pl->route_code !== '') {
		$code = (string) $pl->route_code;
		$parts[] = isset($dictRoute[$code]) ? $dictRoute[$code] : $code;
	}
	if ($pl && $pl->freq_code !== null && (string) $pl->freq_code !== '') {
		$code = (string) $pl->freq_code;
		$parts[] = isset($dictFreq[$code]) ? $dictFreq[$code] : $code;
	}
	if ($pl && $pl->days !== null && (int) $pl->days > 0) {
		$parts[] = '连用 '.(int) $pl->days.' 天';
	}
	$sig = $pl ? trim((string) $pl->sig_note) : '';
	if ($sig !== '') {
		$parts[] = $sig;
	}
	return implode('，', $parts);
}

/**
 * Parse batch_note ("BATCH/date, BATCH/date") into batch list + earliest expiry.
 *
 * @param string $note Raw batch_note
 * @return array{batches:string,expiry:string}
 */
function pharmacy_label_batch($note)
{
	$note = trim((string) $note);
	if ($note === '') {
		return array('batches' => '', 'expiry' => '');
	}
	$batches = array();
	$earliest = '';
	foreach (explode(',', $note) as $chunk) {
		$chunk = trim($chunk);
		if ($chunk === '') {
			continue;
		}
		$pos = strpos($chunk, '/');
		if ($pos === false) {
			$batches[] = $chunk;
			continue;
		}
		$batches[] = substr($chunk, 0, $pos);
		$d = substr($chunk, $pos + 1);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($earliest === '' || $d < $earliest)) {
			$earliest = $d;
		}
	}
	return array('batches' => implode(' / ', $batches), 'expiry' => $earliest);
}

$dispenser = (string) $object->dispenser_name;
// date_dispense arrives as an int timestamp after fetch (DoliDB datetime cast)
$dispenseTs = (int) $object->date_dispense;
$dispenseDate = $dispenseTs > 0 ? date('Y-m-d', $dispenseTs) : date('Y-m-d');

// Build label rows before any output (also decides TCM layout)
$labels = array();
foreach ($lines as $line) {
	$isTcm = empty($line['is_stock']);
	$batch = pharmacy_label_batch($line['batch_note'] ?? '');
	$qty = (float) ($line['qty'] ?? 0);
	$unit = trim((string) ($line['qty_unit'] ?? ''));
	$decoct = '';
	if ($isTcm && isset($prescLines[(int) ($line['fk_prescription_line'] ?? 0)])) {
		$pl = $prescLines[(int) ($line['fk_prescription_line'] ?? 0)];
		$code = trim((string) $pl->decoct_code);
		if ($code !== '') {
			$decoct = isset($dictDecoct[$code]) ? $dictDecoct[$code] : $code;
		}
	}
	$labels[] = array(
		'name' => (string) ($line['label'] ?? ''),
		'qty' => rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.'),
		'unit' => $unit,
		'tcm' => $isTcm,
		'sig' => pharmacy_label_sig($line),
		'batches' => $batch['batches'],
		'expiry' => $batch['expiry'],
		'decoct' => $decoct,
		'doses' => $isTcm && $prescLoaded ? (int) $presc->doses : 0,
	);
}

patient_audit($db, $object->fk_patient, 'PHARMACY_PRINT', $user, array('ref' => $object->ref, 'dispense' => $object->id, 'labels' => 1));
$db->close();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh_CN">
<head>
<meta charset="utf-8">
<title><?php echo dol_escape_htmltag($object->ref.' - '.$langs->trans("PharmacyLabelPrint")); ?></title>
<style>
	@page { size: A4 portrait; margin: 8mm; }
	* { box-sizing: border-box; margin: 0; padding: 0; }
	body { font-family: "Microsoft YaHei", SimHei, sans-serif; color: #000; }
	.sheet { display: grid; grid-template-columns: repeat(2, 1fr); gap: 4mm; }
	.label {
		border: 1px dashed #555; border-radius: 2mm; padding: 3mm 4mm;
		page-break-inside: avoid; height: 46mm; overflow: hidden;
		display: flex; flex-direction: column; justify-content: space-between;
	}
	.head { display: flex; justify-content: space-between; font-size: 9pt; color: #333; }
	.head b { color: #000; }
	.name { font-size: 14pt; font-weight: bold; line-height: 1.25; }
	.qty { font-size: 12pt; font-weight: bold; margin-top: 1mm; }
	.sig { font-size: 10pt; line-height: 1.35; }
	.foot { display: flex; justify-content: space-between; font-size: 8pt; color: #444; }
	.tcm-flag { display: inline-block; font-size: 9pt; border: 1px solid #000; border-radius: 2mm; padding: 0 2mm; margin-left: 2mm; vertical-align: middle; }
	.noprint { background: #f6f6f6; border-bottom: 1px solid #ddd; padding: 6px 12px; margin: -8mm -8mm 6mm; }
	.noprint button { padding: 4px 18px; }
	@media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint">
	<button onclick="window.print()"><?php echo dol_escape_htmltag($langs->trans("PharmacyLabelPrintNow")); ?></button>
	<span style="margin-left:12px;font-size:12px;color:#666;"><?php echo dol_escape_htmltag($langs->trans("PharmacyLabelHint")); ?></span>
</div>
<div class="sheet">
<?php
$n = 0;
foreach ($labels as $lb) {
	$n++;
	print '<div class="label">';
	print '<div class="head"><span>'.dol_escape_htmltag($object->patient_name).' <b>'.dol_escape_htmltag($object->card_no).'</b></span><span>'.dol_escape_htmltag($dispenseDate).' '.dol_escape_htmltag($object->ref).'</span></div>';
	$nameHtml = dol_escape_htmltag($lb['name']).($lb['tcm'] ? '<span class="tcm-flag">'.dol_escape_htmltag($langs->trans("PharmacyLabelTcm")).'</span>' : '');
	print '<div><div class="name">'.$nameHtml.'</div>';
	if ($lb['tcm']) {
		$tcmQty = $lb['doses'] > 0
			? '共 '.$lb['doses'].' 剂 · 每剂 '.$lb['qty'].' g'
			: $lb['qty'].' g';
		$extra = ($lb['decoct'] !== '' ? ' · '.dol_escape_htmltag($lb['decoct']) : '');
		print '<div class="qty">'.dol_escape_htmltag($tcmQty).$extra.'</div>';
	} else {
		print '<div class="qty">'.dol_escape_htmltag($lb['qty']).($lb['unit'] !== '' ? ' '.dol_escape_htmltag($lb['unit']) : '').'</div>';
	}
	print '<div class="sig">'.dol_escape_htmltag($lb['sig']).'</div></div>';
	$batchHtml = '';
	if ($lb['batches'] !== '') {
		$batchHtml = dol_escape_htmltag($lb['batches']);
		if ($lb['expiry'] !== '') {
			$batchHtml .= ' · '.dol_escape_htmltag($langs->trans("PharmacyLabelExpiry")).' '.dol_escape_htmltag($lb['expiry']);
		}
	}
	print '<div class="foot"><span>'.$batchHtml.'</span><span>'.dol_escape_htmltag($langs->trans("PharmacyLabelDispenser")).' '.dol_escape_htmltag($dispenser).'</span></div>';
	print '</div>';
}
if ($n === 0) {
	print '<p>'.dol_escape_htmltag($langs->trans("ErrorRecordNotFound")).'</p>';
}
?>
</div>
<script>window.print();</script>
</body>
</html>
