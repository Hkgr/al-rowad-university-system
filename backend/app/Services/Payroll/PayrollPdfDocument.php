<?php

namespace App\Services\Payroll;

use TCPDF;

/** TCPDF page with the running header (title, scope) and "page X of Y" footer. */
class PayrollPdfDocument extends TCPDF
{
    /** @var list<string> */
    public array $headerLines = [];

    /** "Part 2 of 3" when a wide report is split into column groups. */
    public string $bandLabel = '';

    public ?string $logoPath = null;

    public function Header(): void
    {
        $margins = $this->getMargins();
        $hasLogo = $this->logoPath !== null && is_file($this->logoPath);
        $leadingMargin = $this->rtl ? $margins['right'] : $margins['left'];
        $trailingMargin = $this->rtl ? $margins['left'] : $margins['right'];
        $textOffset = $leadingMargin + ($hasLogo ? 21 : 0); // 16mm logo + 5mm clear gap.
        $textWidth = $this->getPageWidth() - $textOffset - $trailingMargin;
        if ($hasLogo) {
            // Image uses an absolute right edge in RTL; setXY uses distance from the right edge.
            $this->Image($this->logoPath, $this->rtl ? $this->getPageWidth() - $leadingMargin : $leadingMargin, 7, 16, 16, 'PNG');
        }
        $this->setFont('cairo', 'B', 15);
        $this->setXY($textOffset, 7);
        $this->Cell($textWidth, 8, $this->headerLines[0] ?? '', 0, 1, 'R', false, '', 1);
        $this->setFont('cairo', '', 9.5);
        foreach (array_slice($this->headerLines, 1) as $line) {
            $this->setX($textOffset);
            $this->Cell($textWidth, 5.5, $line, 0, 1, 'R', false, '', 1);
        }
    }

    public function Footer(): void
    {
        $this->setY(-14);
        $this->setFont('cairo', '', 9);
        $this->Cell(0, 6, 'صفحة '.$this->getAliasNumPage().' من '.$this->getAliasNbPages().($this->bandLabel !== '' ? ' — '.$this->bandLabel : ''), 0, 0, 'C');
    }
}
