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
 * \file    htdocs/custom/pharmacy/core/modules/pharmacy/doc/pdf_fy.modules.php
 * \ingroup pharmacy
 * \brief   Dispense sheet layout (发药单, spec §3.6): institution /
 *          patient / prescription header, drug table with batch and
 *          expiry snapshot, signature slots. Returned sheets carry a
 *          已退货 watermark (no separate layout, spec §3.6).
 */

require_once DOL_DOCUMENT_ROOT.'/custom/pharmacy/core/modules/pharmacy/modules_pharmacy.php';

/**
 * Class pdf_fy
 */
class pdf_fy extends ModelePDFPharmacy
{
	/** @var array<string,float> Body column widths in mm (A4 content ≈ 186 mm) */
	protected $columns = array(
		'no'     => 8,
		'drug'   => 48,
		'qty'    => 14,
		'unit'   => 14,
		'batch'  => 46,
		'expiry' => 30,
		'note'   => 26,
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name = 'fy';
		$this->description = 'Dispense sheet (发药单)';
	}

	/**
	 * Column widths as fractions of the content width.
	 *
	 * @return	array<string,float>
	 */
	protected function colWidths()
	{
		$total = 0;
		foreach ($this->columns as $w) {
			$total += $w;
		}
		$scale = $this->contentWidth() / $total;
		$cols = array();
		foreach ($this->columns as $key => $w) {
			$cols[$key] = $w * $scale;
		}
		return $cols;
	}

	/**
	 * @param	TCPDF	$pdf	PDF
	 * @param	float	$y		Y
	 * @param	array	$texts	Cell texts keyed by column
	 * @param	bool	$header	Header row
	 * @return	float			Row height
	 */
	protected function drawRow($pdf, $y, array $texts, $header = false)
	{
		$cols = $this->colWidths();
		$h = 0;
		foreach ($cols as $key => $cw) {
			$h = max($h, $pdf->getStringHeight($cw, isset($texts[$key]) ? $texts[$key] : '', true));
		}
		$x = $this->marge_gauche;
		foreach ($cols as $key => $cw) {
			$align = in_array($key, array('no', 'qty', 'unit', 'expiry'), true) || $header ? 'C' : 'L';
			$pdf->MultiCell($cw, $h, isset($texts[$key]) ? $texts[$key] : '', 1, $align, false, 1, $x, $y, true, 0, false, true, $h, 'M');
			$x += $cw;
		}
		return $h;
	}

	/**
	 * 正文: drug / quantity / unit / batch / expiry / note table. The
	 * batch_note snapshot written at confirm time is "BATCH/sellby, ..." —
	 * the sellby part is moved into the expiry column; a non-stock line is
	 * marked 非库存 in the note column (spec §3.6).
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			Dispense (with ->lines, ...)
	 * @param	Translate	$outputlangs	Lang
	 * @param	float		$y				Start Y
	 * @return	float
	 */
	protected function drawBody($pdf, $object, $outputlangs, $y)
	{
		$x = $this->marge_gauche;
		$w = $this->contentWidth();

		$pdf->SetFont($this->font, '', $this->fontSize + 2);
		$y = $this->text($pdf, $x, $y, $w, 'Rp.') + 1;
		$pdf->SetFont($this->font, '', $this->fontSize);

		$header = array(
			'no'     => $outputlangs->transnoentities('PharmacyColNo'),
			'drug'   => $outputlangs->transnoentities('PharmacyColDrug'),
			'qty'    => $outputlangs->transnoentities('PharmacyColQty'),
			'unit'   => $outputlangs->transnoentities('PharmacyColUnit'),
			'batch'  => $outputlangs->transnoentities('PharmacyColBatch'),
			'expiry' => $outputlangs->transnoentities('PharmacyColExpiry'),
			'note'   => $outputlangs->transnoentities('PharmacyColNote'),
		);
		$y += $this->drawRow($pdf, $y, $header, true);

		foreach ((array) $object->lines as $i => $l) {
			$qty = $l['qty'] !== null ? $this->num($l['qty']) : '';
			$isStock = !empty($l['is_stock']);
			$batch = '';
			$expiry = '';
			$note = $isStock ? '' : $outputlangs->transnoentities('PharmacyLineNonStock');
			if ($isStock && !empty($l['batch_note'])) {
				$batches = array();
				$expiries = array();
				foreach (explode(', ', (string) $l['batch_note']) as $pair) {
					$pair = trim($pair);
					if ($pair === '') {
						continue;
					}
					if (strpos($pair, '/') !== false) {
						list($b, $d) = explode('/', $pair, 2);
						$batches[] = $b;
						$expiries[] = trim($d);
					} else {
						$batches[] = $pair;
					}
				}
				$batch = implode(', ', $batches);
				$expiry = implode(', ', $expiries);
			}
			$texts = array(
				'no'     => (string) ($i + 1),
				'drug'   => (string) $l['label'],
				'qty'    => $qty,
				'unit'   => (string) $l['qty_unit'],
				'batch'  => $batch,
				'expiry' => $expiry,
				'note'   => $note,
			);
			$cols = $this->colWidths();
			$h = 0;
			foreach ($cols as $key => $cw) {
				$h = max($h, $pdf->getStringHeight($cw, $texts[$key], true));
			}
			if ($y + $h > $this->bodyBottom()) {
				$y = $this->newPage($pdf, false);
				$pdf->SetFont($this->font, '', $this->fontSize);
				$y += $this->drawRow($pdf, $y, $header, true);
			}
			$y += $this->drawRow($pdf, $y, $texts);
		}
		if (empty($object->lines)) {
			$y += $this->drawRow($pdf, $y, array('no' => '', 'drug' => '—', 'qty' => '', 'unit' => '', 'batch' => '', 'expiry' => '', 'note' => ''));
		}

		$y += 3;
		if (!empty($object->note) && $y + 2 * $this->lineHeight <= $this->bodyBottom()) {
			$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PharmacyNote').': '.$object->note);
		}
		return $y;
	}
}
