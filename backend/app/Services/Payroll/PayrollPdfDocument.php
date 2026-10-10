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
        if ($this->logoPath !== null && is_file($this->logoPath)) {
            $this->Image($this->logoPath, $this->getPageWidth() - 28, 7, 16, 16, 'PNG');
        }
        $this->setFont('cairo', 'B', 15);
        $this->setXY($this->logoPath === null ? 12 : 32, 7);
        $this->Cell(0, 8, $this->headerLines[0] ?? '', 0, 1, 'R');
        $this->setFont('cairo', '', 9.5);
        foreach (array_slice($this->headerLines, 1) as $line) {
            $this->setX(12);
            $this->Cell(0, 5.5, $line, 0, 1, 'R');
        }
    }

    public function Footer(): void
    {
        $this->setY(-14);
        $this->setFont('cairo', '', 9);
        $this->Cell(0, 6, 'صفحة '.$this->getAliasNumPage().' من '.$this->getAliasNbPages().($this->bandLabel !== '' ? ' — '.$this->bandLabel : ''), 0, 0, 'C');
    }
}
