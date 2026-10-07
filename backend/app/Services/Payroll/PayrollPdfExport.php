<?php

namespace App\Services\Payroll;

use RuntimeException;
use TCPDF_FONTS;

/**
 * PDF export of one persisted snapshot. TCPDF (LGPL) performs Arabic shaping and bidi; the SIL-OFL Cairo font
 * (the UI font) is embedded as a subset. A3 landscape keeps all ten columns readable at 9 pt; the column
 * headings repeat on every page, every page has "page X of Y", and the totals close the table.
 */
final class PayrollPdfExport
{
    private const PAGE_FORMAT = 'A3';

    private const MARGIN = 12;

    private const FONT_SIZE = 9.5;

    /** mm, in display order (right to left). Sum = 396 = A3 landscape width minus margins. */
    private const WIDTHS = [
        'employee_number' => 28, 'full_name' => 62, 'job_title' => 55, 'body' => 44, 'workplace' => 36,
        'academic_level' => 41, 'fixed_salary' => 32, 'deduction' => 30, 'compensation' => 30, 'payable' => 38,
    ];

    /** Returns the PDF bytes. */
    public function build(array $snapshot, array $scopeLabels): string
    {
        $fontDir = $this->ensureFonts();
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontDir.'/');
        }

        $pdf = new PayrollPdfDocument('L', 'mm', self::PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->setCreator('Alrowad University');
        $pdf->setTitle(PayrollColumns::TITLE);
        $pdf->setRTL(true);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->setMargins(self::MARGIN, 34, self::MARGIN);
        $pdf->setHeaderMargin(8);
        $pdf->setFooterMargin(10);
        $pdf->setAutoPageBreak(false);
        $pdf->setCellPaddings(1.5, 1, 1.5, 1);
        $pdf->AddFont('cairo', '', $fontDir.'/cairo.php');
        $pdf->AddFont('cairo', 'B', $fontDir.'/cairob.php');
        $pdf->headerLines = [
            PayrollColumns::TITLE,
            PayrollColumns::scopeLine($snapshot, $scopeLabels),
            PayrollColumns::sortLine($snapshot['filters']).' — تاريخ التوليد: '.now()->format('Y-m-d H:i').' — '.PayrollColumns::CURRENT_SHEET_NOTE,
        ];

        $pdf->AddPage();
        $this->tableHeader($pdf);
        $limit = $pdf->getPageHeight() - 22;

        foreach ($snapshot['rows'] as $r) {
            $cells = [
                $r['employee_number'], $r['full_name'], $r['job_title'], $r['body_name'], $r['workplace_label'], $r['academic_level'] ?? '',
                $this->money($r['fixed_salary']), $this->money($r['deduction']), $this->money($r['compensation']), $this->money($r['payable']),
            ];
            $height = $this->rowHeight($pdf, $cells);
            if ($pdf->GetY() + $height > $limit) {
                $pdf->AddPage();
                $this->tableHeader($pdf);
            }
            $this->row($pdf, $cells, $height, false, $r['payable'] !== null && str_starts_with($r['payable'], '-'));
        }

        $totals = $snapshot['totals'];
        if ($pdf->GetY() + 9 > $limit) {
            $pdf->AddPage();
            $this->tableHeader($pdf);
        }
        $pdf->setFont('cairo', 'B', self::FONT_SIZE);
        $pdf->setFillColor(232, 241, 223);
        $labelWidth = array_sum(array_slice(self::WIDTHS, 0, 6));
        $pdf->MultiCell($labelWidth, 9, 'الإجمالي ('.$totals['employees'].' موظفًا)', 1, 'R', true, 0, '', '', true, 0, false, true, 9, 'M');
        foreach (['fixed_salary', 'deduction', 'compensation', 'payable'] as $key) {
            $pdf->MultiCell(self::WIDTHS[$key], 9, $this->money($totals[$key]), 1, 'C', true, 0, '', '', true, 0, false, true, 9, 'M');
        }
        $pdf->Ln(9);
        $pdf->setFont('cairo', '', 8.5);
        $pdf->MultiCell(0, 6, 'إجمالي المستحق يجمع الصفوف التي لها راتب مقطوع فقط (المستحق الفارغ لا يدخل في المجموع).', 0, 'R', false, 1);

        return $pdf->Output('payroll.pdf', 'S');
    }

    private function money(?string $value): string
    {
        if ($value === null) {
            return '';
        }
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = explode('.', ltrim($value, '-'));

        return ($negative ? '-' : '').'$'.number_format((int) $whole).'.'.$fraction;
    }

    private function tableHeader(PayrollPdfDocument $pdf): void
    {
        $pdf->setFont('cairo', 'B', self::FONT_SIZE);
        $pdf->setFillColor(36, 61, 22);
        $pdf->setTextColor(255, 255, 255);
        foreach (PayrollColumns::HEADINGS as $key => $title) {
            $pdf->MultiCell(self::WIDTHS[$key], 10, $title, 1, 'C', true, 0, '', '', true, 0, false, true, 10, 'M');
        }
        $pdf->Ln(10);
        $pdf->setTextColor(0, 0, 0);
    }

    private function rowHeight(PayrollPdfDocument $pdf, array $cells): float
    {
        $pdf->setFont('cairo', '', self::FONT_SIZE);
        $lines = 1;
        foreach (array_values(self::WIDTHS) as $i => $width) {
            $lines = max($lines, $pdf->getNumLines((string) $cells[$i], $width));
        }

        return max(8.0, $lines * 4.6 + 2.4);
    }

    private function row(PayrollPdfDocument $pdf, array $cells, float $height, bool $fill, bool $negativePayable): void
    {
        $pdf->setFont('cairo', '', self::FONT_SIZE);
        $pdf->setFillColor(246, 249, 243);
        foreach (array_keys(self::WIDTHS) as $i => $key) {
            $numeric = $i >= 6;
            if ($key === 'payable' && $negativePayable) {
                $pdf->setTextColor(185, 28, 28);
            }
            $pdf->MultiCell(self::WIDTHS[$key], $height, (string) $cells[$i], 1, $numeric ? 'C' : 'R', $fill, 0, '', '', true, 0, false, true, $height, 'M');
            $pdf->setTextColor(0, 0, 0);
        }
        $pdf->Ln($height);
    }

    /** Convert the shipped OFL TTFs into TCPDF font definitions once (cached in storage). */
    private function ensureFonts(): string
    {
        $dir = storage_path('app/pdf-fonts');
        if (is_file($dir.'/cairo.php') && is_file($dir.'/cairob.php') && is_file($dir.'/helvetica.php')) {
            return $dir;
        }
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('PDF font cache directory is not writable.');
        }
        $lock = fopen($dir.'/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            // TCPDF's constructor selects the core "helvetica" definition from the same font directory.
            $core = base_path('vendor/tecnickcom/tcpdf/fonts/helvetica.php');
            if (! is_file($dir.'/helvetica.php') && ! copy($core, $dir.'/helvetica.php')) {
                throw new RuntimeException('Could not prepare the PDF core font definition.');
            }
            foreach (['Cairo-Regular' => 'cairo', 'Cairo-Bold' => 'cairob'] as $file => $name) {
                if (is_file($dir.'/'.$name.'.php')) {
                    continue;
                }
                $tmp = $dir.'/tmp-'.getmypid().'-'.$name;
                @mkdir($tmp, 0775, true);
                copy(resource_path("pdf-fonts/{$file}.ttf"), "{$tmp}/{$name}.ttf");
                if (! TCPDF_FONTS::addTTFfont("{$tmp}/{$name}.ttf", 'TrueTypeUnicode', '', 32, $tmp.'/')) {
                    throw new RuntimeException('Could not convert the PDF font.');
                }
                foreach (glob("{$tmp}/{$name}.*") as $generated) {
                    if (! str_ends_with($generated, '.ttf')) {
                        rename($generated, $dir.'/'.basename($generated));
                    }
                }
                array_map('unlink', glob("{$tmp}/*"));
                @rmdir($tmp);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $dir;
    }
}
