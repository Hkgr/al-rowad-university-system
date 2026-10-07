<?php

namespace App\Services\Payroll;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

/**
 * Real .xlsx export of one persisted snapshot: Arabic headings, right-to-left sheet, employee numbers as text,
 * money as numeric USD cells, live formulas for payable and totals. User text is always written as literal
 * strings (never parsed as a formula).
 */
final class PayrollXlsxExport
{
    public const FIRST_DATA_ROW = 6;

    public const MONEY_FORMAT = '"$"#,##0.00;[Red]\-"$"#,##0.00';

    /** Returns the path of a temporary .xlsx file. */
    public function build(array $snapshot, array $scopeLabels): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Alrowad University')->setTitle(PayrollColumns::TITLE);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ورقة الرواتب');
        $sheet->setRightToLeft(true);

        $this->text($sheet, 'A1', PayrollColumns::TITLE);
        $this->text($sheet, 'A2', PayrollColumns::scopeLine($snapshot, $scopeLabels));
        $this->text($sheet, 'A3', 'تاريخ التوليد: '.now()->format('Y-m-d H:i').' — '.PayrollColumns::CURRENT_SHEET_NOTE);
        $this->text($sheet, 'A4', PayrollColumns::sortLine($snapshot['filters']));
        foreach (['A1:J1', 'A2:J2', 'A3:J3', 'A4:J4'] as $range) {
            $sheet->mergeCells($range);
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
        $sheet->getStyle('A2:A4')->getFont()->setSize(11);

        // Headings (row 5).
        $header = self::FIRST_DATA_ROW - 1;
        foreach (array_values(PayrollColumns::HEADINGS) as $i => $title) {
            $this->text($sheet, chr(65 + $i).$header, $title);
        }
        $headStyle = $sheet->getStyle("A{$header}:J{$header}");
        $headStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('243D16');
        $headStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension($header)->setRowHeight(28);

        $row = self::FIRST_DATA_ROW;
        foreach ($snapshot['rows'] as $r) {
            $this->text($sheet, "A{$row}", $r['employee_number']);   // text: keeps leading zeros and codes
            $this->text($sheet, "B{$row}", $r['full_name']);
            $this->text($sheet, "C{$row}", $r['job_title']);
            $this->text($sheet, "D{$row}", $r['body_name']);
            $this->text($sheet, "E{$row}", $r['workplace_label']);
            $this->text($sheet, "F{$row}", $r['academic_level'] ?? '');
            foreach (['G' => 'fixed_salary', 'H' => 'deduction', 'I' => 'compensation'] as $col => $key) {
                if ($r[$key] !== null) {                              // blank stays an empty cell; an entered zero is 0
                    $sheet->setCellValueExplicit("{$col}{$row}", (float) $r[$key], DataType::TYPE_NUMERIC);
                }
            }
            $sheet->setCellValue("J{$row}", "=IF(G{$row}=\"\",\"\",G{$row}-H{$row}+I{$row})");
            $row++;
        }
        $last = $row - 1;
        $totalRow = $row;

        $this->text($sheet, "A{$totalRow}", 'الإجمالي ('.$snapshot['totals']['employees'].' موظفًا)');
        $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
        foreach (['G', 'H', 'I', 'J'] as $col) {
            $sheet->setCellValue("{$col}{$totalRow}", $last >= self::FIRST_DATA_ROW ? "=SUM({$col}".self::FIRST_DATA_ROW.":{$col}{$last})" : 0);
        }
        $sheet->getStyle("A{$totalRow}:J{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$totalRow}:J{$totalRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F1DF');
        $sheet->getStyle('G'.self::FIRST_DATA_ROW.":J{$totalRow}")->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
        $sheet->getStyle("A{$header}:J{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9CBA8');
        $sheet->getStyle('A'.self::FIRST_DATA_ROW.":F{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('A'.self::FIRST_DATA_ROW.":A{$last}")->getNumberFormat()->setFormatCode('@');

        foreach (['A' => 16, 'B' => 30, 'C' => 28, 'D' => 22, 'E' => 18, 'F' => 22, 'G' => 18, 'H' => 16, 'I' => 16, 'J' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->freezePane('C'.self::FIRST_DATA_ROW);

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header, $header);
        $sheet->getHeaderFooter()->setOddFooter('&C&"Arial"&P / &N');

        $path = tempnam(sys_get_temp_dir(), 'payroll_xlsx_');
        IOFactory::createWriter($book, 'Xlsx')->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** Literal text cell; a leading = + - @ (or control char) additionally gets the quote prefix. */
    private function text($sheet, string $coordinate, string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r\n") !== false) {
            $sheet->getStyle($coordinate)->setQuotePrefix(true);
        }
    }
}
