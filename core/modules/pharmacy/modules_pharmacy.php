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
 * \file    htdocs/custom/pharmacy/core/modules/pharmacy/modules_pharmacy.php
 * \ingroup pharmacy
 * \brief   Shared base for the dispense-sheet PDF models (spec §3.6).
 *          Same discovery mechanism as modPrescription / modChinaDoc:
 *          module_parts['models'] = 1, template files under
 *          core/modules/pharmacy/doc/pdf_*.modules.php.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commondocgenerator.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * Parent class for the pharmacy dispense-sheet PDF models
 */
abstract class ModelePDFPharmacy extends CommonDocGenerator
{
	public $db;
	public $name;
	public $description;
	public $type = 'pdf';
	public $marge_gauche;
	public $marge_droite;
	public $marge_haute;
	public $marge_basse;
	public $page_largeur;
	public $page_hauteur;
	public $emetteur;

	/** @var string Chinese font validated by chinadoc / prescription */
	protected $font = 'stsongstdlight';
	/** @var float Base body font size */
	protected $fontSize = 10;
	/** @var float Measured single-line height */
	protected $lineHeight;

	/** @var object */
	protected $pdfObject;
	/** @var Translate */
	protected $pdfLangs;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $mysoc;

		$this->db = $db;
		$this->update_main_doc_field = 1;
		$this->page_largeur = 210;
		$this->page_hauteur = 297;
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = 12;
		$this->marge_droite = 12;
		$this->marge_haute = 10;
		$this->marge_basse = 10;
		$this->emetteur = $mysoc;
		if (is_object($this->emetteur) && empty($this->emetteur->country_code) && is_object($langs)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2);
		}
	}

	/**
	 * Build the dispense sheet: DOL_DATA_ROOT/pharmacy/<ref>/<ref>.pdf
	 *
	 * @param	object		$object			Dispense (with ->lines, ->patient_name, ->card_no, ->presc_ref, ...)
	 * @param	Translate	$outputlangs	Lang
	 * @param	string		$srctemplatepath	Unused
	 * @param	int			$hidedetails	Unused
	 * @param	int			$hidedesc		Unused
	 * @param	int			$hideref		Unused
	 * @return	int							1 ok, <=0 error
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $langs, $user;

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		$outputlangs->loadLangs(array('main', 'companies', 'pharmacy@pharmacy'));
		if (empty($conf->pharmacy->dir_output)) {
			$this->error = 'PHARMACY_OUTPUTDIR undefined (module not enabled?)';
			return 0;
		}

		$ref = dol_sanitizeFileName($object->ref);
		$dir = $conf->pharmacy->dir_output.'/'.$ref;
		$file = $dir.'/'.$ref.'.pdf';
		if (!is_dir($dir) && dol_mkdir($dir) < 0) {
			$this->error = $outputlangs->transnoentities('ErrorCanNotCreateDir', $dir);
			return -1;
		}

		$pdf = pdf_getInstance($this->format);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);
		$pdf->SetAutoPageBreak(false, 0);
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->setCellPaddings(0.8, 0.6, 0.8, 0.6);
		$pdf->setCellHeightRatio(1.3);
		$pdf->SetLineWidth(0.2);
		$pdf->SetFont($this->font, '', $this->fontSize);
		$this->lineHeight = $pdf->getStringHeight(100, 'X', true, false);
		$pdf->SetTitle($object->ref);
		$pdf->SetSubject($outputlangs->transnoentities('PharmacyDispenseSheet'));
		$pdf->SetCreator('Dolibarr '.DOL_VERSION);
		if (is_object($user) && method_exists($user, 'getFullName')) {
			$pdf->SetAuthor($user->getFullName($outputlangs));
		}

		$this->pdfObject = $object;
		$this->pdfLangs = $outputlangs;
		$y = $this->newPage($pdf, true);
		$y = $this->drawBody($pdf, $object, $outputlangs, $y);
		$this->drawFooter($pdf, $object, $outputlangs, $y);

		// Page numbers + watermark on every page
		$pages = $pdf->getNumPages();
		for ($p = 1; $p <= $pages; $p++) {
			$pdf->setPage($p);
			$pdf->SetFont($this->font, '', 7);
			$pdf->SetTextColor(100, 100, 100);
			$this->text($pdf, $this->page_largeur - $this->marge_droite - 30, $this->page_hauteur - $this->marge_basse - 3, 30, $outputlangs->transnoentities('PharmacyPage', $p, $pages), 'R');
			$pdf->SetTextColor(0, 0, 0);
			$this->watermark($pdf, $object, $outputlangs);
		}

		$pdf->Close();
		$pdf->Output($file, 'F');
		dolChmod($file);
		$this->result = array('fullpath' => $file);
		return 1;
	}

	// ------------------------------------------------------------ shared drawing

	/**
	 * @return	float	Usable width
	 */
	protected function contentWidth()
	{
		return $this->page_largeur - $this->marge_gauche - $this->marge_droite;
	}

	/**
	 * @return	float	Lowest Y the body may use on a page (footer reserved on the last page by drawFooter)
	 */
	protected function bodyBottom()
	{
		return $this->page_hauteur - $this->marge_basse - 8;
	}

	/**
	 * Height reserved by the footer block (signatures + notes).
	 *
	 * @return	float
	 */
	protected function footerHeight()
	{
		return 30;
	}

	/**
	 * Draw a plain text block and return its bottom Y.
	 *
	 * @param	TCPDF	$pdf	PDF
	 * @param	float	$x		X
	 * @param	float	$y		Y
	 * @param	float	$w		Width
	 * @param	string	$text	Text
	 * @param	string	$align	L/C/R
	 * @return	float
	 */
	protected function text($pdf, $x, $y, $w, $text, $align = 'L')
	{
		$h = $pdf->getStringHeight($w, $text, true);
		$pdf->MultiCell($w, $h, $text, 0, $align, false, 1, $x, $y, true);
		return $y + $h;
	}

	/**
	 * New page with the header; continuation pages get a shorter header.
	 *
	 * @param	TCPDF	$pdf	PDF
	 * @param	bool	$first	First page
	 * @return	float			Y where the body starts
	 */
	protected function newPage($pdf, $first = false)
	{
		$pdf->AddPage();
		return $this->drawHeader($pdf, $this->pdfObject, $this->pdfLangs, $first);
	}

	/**
	 * 发药单 header: institution, title, sheet number, patient, prescription
	 * ref, dispense time, dispatcher.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object	$object			Dispense
	 * @param	Translate	$outputlangs	Lang
	 * @param	bool	$first			First page (full header) or continuation
	 * @return	float					Y after the header
	 */
	protected function drawHeader($pdf, $object, $outputlangs, $first)
	{
		$x = $this->marge_gauche;
		$w = $this->contentWidth();
		$pdf->SetTextColor(0, 0, 0);

		$pdf->SetFont($this->font, '', $first ? 15 : 12);
		$y = $this->text($pdf, $x, $this->marge_haute, $w, (is_object($this->emetteur) ? $this->emetteur->name : ''), 'C');
		$pdf->SetFont($this->font, '', $first ? 14 : 12);
		$title = $outputlangs->transnoentities('PharmacyDispenseSheet');
		if (!$first) {
			$title .= ' '.$outputlangs->transnoentities('PharmacyContinued');
		}
		$y = $this->text($pdf, $x, $y + 1, $w, $title, 'C') + 1;

		$pdf->SetFont($this->font, '', $this->fontSize);
		$half = $w / 2;
		$y = $this->text($pdf, $x, $y, $half, $outputlangs->transnoentities('PharmacyRef').': '.$object->ref, 'L');
		$right = '';
		if (!empty($object->date_dispense)) {
			$right = $outputlangs->transnoentities('PharmacyDateDispense').': '.dol_print_date($object->date_dispense, 'day', false, $outputlangs, true);
			if (!empty($object->fk_user_dispense)) {
				$right .= '    '.(isset($object->dispenser_name) ? $object->dispenser_name : '').' : ';
			}
		}
		$this->text($pdf, $x + $half, $y, $half, $right, 'R');
		$y += $this->lineHeight + 1;

		$patientLine = $outputlangs->transnoentities('PharmacyPatient').': '.(isset($object->patient_name) ? $object->patient_name : '');
		if (isset($object->card_no)) {
			$patientLine .= '    '.$outputlangs->transnoentities('PatientCardNo').': '.(string) $object->card_no;
		}
		$y = $this->text($pdf, $x, $y, $w, $patientLine);
		$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('PharmacyPrescription').': '.(isset($object->presc_ref) ? $object->presc_ref : ''));

		$y += 1;
		$pdf->Line($x, $y, $x + $w, $y);
		return $y + 2;
	}

	/**
	 * 后记: dispenser / checker / receiver signature slots. Starts a new
	 * page when it does not fit.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object	$object			Dispense
	 * @param	Translate	$outputlangs	Lang
	 * @param	float	$y				Y after the body
	 * @return	void
	 */
	protected function drawFooter($pdf, $object, $outputlangs, $y)
	{
		if ($y + $this->footerHeight() > $this->bodyBottom()) {
			$y = $this->newPage($pdf, false);
		}
		$x = $this->marge_gauche;
		$w = $this->contentWidth();
		$y = max($y + 4, $this->bodyBottom() - $this->footerHeight());
		$pdf->Line($x, $y, $x + $w, $y);
		$y += 2;
		$pdf->SetFont($this->font, '', $this->fontSize);

		$slots = array('PharmacySignDispenser', 'PharmacySignChecker', 'PharmacySignReceiver');
		$sw = $w / 3;
		$dispenser = '';
		if (!empty($object->fk_user_dispense) && !empty($object->dispenser_name)) {
			$dispenser = $object->dispenser_name;
		}
		foreach ($slots as $i => $key) {
			$label = $outputlangs->transnoentities($key);
			if ($i === 0) {
				$label .= ': '.($dispenser !== '' ? $dispenser : '______');
			} else {
				$label .= ': ________';
			}
			$this->text($pdf, $x + $i * $sw, $y, $sw, $label, 'L');
		}
	}

	/**
	 * Diagonal watermark: 已退货 (red) for returned sheets.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object	$object			Dispense
	 * @param	Translate	$outputlangs	Lang
	 * @return	void
	 */
	protected function watermark($pdf, $object, $outputlangs)
	{
		if ((int) $object->status !== PHARMACY_STATUS_RETURNED) {
			return;
		}
		$text = $outputlangs->transnoentities('PharmacyStatusReturned');
		$pdf->SetTextColor(200, 0, 0);
		$pdf->StartTransform();
		$cx = $this->page_largeur / 2;
		$cy = $this->page_hauteur / 2;
		$pdf->Rotate(35, $cx, $cy);
		$pdf->setAlpha(0.2);
		$pdf->SetFont($this->font, '', 60);
		$pdf->Text($cx - 30, $cy - 10, $text);
		$pdf->setAlpha(1);
		$pdf->StopTransform();
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont($this->font, '', $this->fontSize);
	}

	/**
	 * Format a decimal without trailing zeros.
	 *
	 * @param	mixed	$n	Number
	 * @return	string
	 */
	protected function num($n)
	{
		if ($n === null || $n === '') {
			return '';
		}
		return rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
	}

	/**
	 * Body renderer implemented by each layout. Must call $this->newPage()
	 * when $y would pass $this->bodyBottom().
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			Dispense (with ->lines, ...)
	 * @param	Translate	$outputlangs	Lang
	 * @param	float		$y				Start Y
	 * @return	float						Y after the body
	 */
	abstract protected function drawBody($pdf, $object, $outputlangs, $y);
}
