<?php

namespace App\Services\Payroll;

final class MonthlyPayrollDocument extends PayrollPdfDocument
{
    public function Header(): void
    {
        $logo = base_path('../frontend/public/logo.png');
        if (! is_file($logo)) {
            throw new \RuntimeException('Official logo asset missing.');
        }
        $this->Image($logo, 12, 7, 16, 16, 'PNG');
        $this->setXY(32, 7);
        $this->setFont('cairo', 'B', 13);
        $this->MultiCell(166, 8, $this->headerLines[0] ?? '', 0, 'R');
        $this->setFont('cairo', '', 8.5);
        foreach (array_slice($this->headerLines, 1) as $line) {
            $this->setX(32);
            $this->MultiCell(166, 5, $line, 0, 'R');
        }
    }
}
