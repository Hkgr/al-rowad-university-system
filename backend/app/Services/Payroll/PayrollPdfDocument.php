<?php

namespace App\Services\Payroll;

use TCPDF;

/** TCPDF page with the running header (title, scope) and "page X of Y" footer. */
class PayrollPdfDocument extends TCPDF
{
    /** @var list<string> */
    public array $headerLines = [];

    public function Header(): void
    {
        $this->setFont('cairo', 'B', 15);
        $this->setXY(12, 7);
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
        $this->Cell(0, 6, 'صفحة '.$this->getAliasNumPage().' من '.$this->getAliasNbPages(), 0, 0, 'C');
    }
}
