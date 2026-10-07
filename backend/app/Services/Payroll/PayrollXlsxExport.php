<?php

namespace App\Services\Payroll;

use App\Services\Payroll\Formula\ExcelFormula;
use App\Services\Payroll\Formula\PayrollFormula;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Real .xlsx export of one consistent snapshot (rows + configuration + totals).
 *
 * Layout (right-to-left): title/scope rows, a labelled settings block (row 5 holds the values every formula points at), a group
 * band, the headings, one row per employee, and a totals row. Inputs are literal values (employee numbers as text, amounts as
 * numbers in Syrian pounds); calculated columns are live Excel formulas equivalent to the application formulas, so editing an
 * input or a setting recalculates the sheet. Columns an exported formula needs but that are not exported are included hidden.
 */
final class PayrollXlsxExport
{
    public const SETTINGS_LABEL_ROW = 4;

    public const SETTINGS_ROW = 5;

    public const GROUP_ROW = 6;

    public const HEADER_ROW = 7;

    public const FIRST_DATA_ROW = 8;

    public const MONEY_FORMAT = '#,##0.00" ل.س";[Red]\-#,##0.00" ل.س"';

    public const NUMBER_FORMAT = '#,##0.######;[Red]\-#,##0.######';

    public const PERCENT_FORMAT = '0.####%';

    /** Returns the path of a temporary .xlsx file. */
    public function build(array $snapshot, array $scopeLabels): string
    {
        $config = $snapshot['config'];
        $exported = PayrollColumns::exportColumns($config);
        $byKey = collect($config['columns'])->keyBy('key');
        $helpers = array_values(array_diff(PayrollColumns::closure($config, array_column(array_filter($exported, fn ($c) => ! $c['identity']), 'key')), array_column($exported, 'key')));
        $columns = $exported;
        foreach ($helpers as $key) {
            $c = $byKey[$key];
            $columns[] = ['key' => $key, 'heading' => $c['label'].' (عمود مساعد)', 'group' => $c['group'], 'group_label' => PayrollConfigService::GROUPS[$c['group']] ?? '', 'type' => $c['value_type'], 'aggregation' => 'none', 'identity' => false, 'hidden' => true];
        }
        $letters = [];
        foreach ($columns as $i => $column) {
            $letters[$column['key']] = Coordinate::stringFromColumnIndex($i + 1);
        }
        $lastLetter = Coordinate::stringFromColumnIndex(count($columns));
        $visibleLast = Coordinate::stringFromColumnIndex(count($exported));

        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Alrowad University')->setTitle(PayrollColumns::TITLE);
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('ورقة الرواتب');
        $sheet->setRightToLeft(true);

        $this->text($sheet, 'A1', PayrollColumns::TITLE);
        $this->text($sheet, 'A2', PayrollColumns::scopeLine($snapshot, $scopeLabels));
        $this->text($sheet, 'A3', 'تاريخ التوليد: '.now()->format('Y-m-d H:i').' — '.PayrollColumns::CURRENCY_NOTE.' — '.PayrollColumns::sortLine($snapshot['filters'], $config).' — '.PayrollColumns::CURRENT_SHEET_NOTE);
        foreach ([1, 2, 3] as $r) {
            $sheet->mergeCells("A{$r}:{$visibleLast}{$r}");
        }
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);

        // Settings block: label above value; the formulas below reference these absolute cells.
        $settingCells = [];
        foreach ($config['settings'] as $i => $setting) {
            $letter = Coordinate::stringFromColumnIndex($i + 1);
            $this->text($sheet, $letter.self::SETTINGS_LABEL_ROW, $setting['label']);
            $sheet->setCellValueExplicit($letter.self::SETTINGS_ROW, (float) $setting['value'], DataType::TYPE_NUMERIC);
            $sheet->getStyle($letter.self::SETTINGS_ROW)->getNumberFormat()->setFormatCode($setting['value_type'] === 'percent' ? self::PERCENT_FORMAT : ($setting['value_type'] === 'amount' ? self::MONEY_FORMAT : self::NUMBER_FORMAT));
            $settingCells[$setting['key']] = '$'.$letter.'$'.self::SETTINGS_ROW;
        }
        $settingsRange = 'A'.self::SETTINGS_LABEL_ROW.':'.Coordinate::stringFromColumnIndex(max(1, count($config['settings']))).self::SETTINGS_ROW;
        $sheet->getStyle($settingsRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8E1');
        $sheet->getStyle($settingsRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E0C97F');
        $sheet->getStyle('A'.self::SETTINGS_LABEL_ROW.':'.Coordinate::stringFromColumnIndex(max(1, count($config['settings']))).self::SETTINGS_LABEL_ROW)->getFont()->setBold(true);
        $sheet->getStyle($settingsRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);

        // Group band + headings.
        $groupStart = 0;
        foreach ($columns as $i => $column) {
            $next = $columns[$i + 1] ?? null;
            if ($next === null || $next['group'] !== $column['group'] || ($next['hidden'] ?? false) !== ($column['hidden'] ?? false)) {
                $from = Coordinate::stringFromColumnIndex($groupStart + 1);
                $to = Coordinate::stringFromColumnIndex($i + 1);
                $this->text($sheet, $from.self::GROUP_ROW, $column['group_label']);
                if ($from !== $to) {
                    $sheet->mergeCells($from.self::GROUP_ROW.':'.$to.self::GROUP_ROW);
                }
                $groupStart = $i + 1;
            }
            $this->text($sheet, $letters[$column['key']].self::HEADER_ROW, $column['heading']);
        }
        $band = $sheet->getStyle('A'.self::GROUP_ROW.":{$lastLetter}".self::GROUP_ROW);
        $band->getFont()->setBold(true)->getColor()->setRGB('243D16');
        $band->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE8CF');
        $band->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $head = $sheet->getStyle('A'.self::HEADER_ROW.":{$lastLetter}".self::HEADER_ROW);
        $head->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $head->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('243D16');
        $head->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(32);

        // Formula metadata per column.
        $calculator = new PayrollCalculator($config['columns'], $config['settings']);
        $keyIsStrict = function (string $key) use ($byKey): bool {
            $c = $byKey->get($key);

            return $c !== null && $c['value_type'] !== 'text' && ($c['kind'] === 'formula' || ! $c['blank_as_zero']);
        };

        $row = self::FIRST_DATA_ROW;
        foreach ($snapshot['rows'] as $r) {
            foreach ($columns as $column) {
                $coordinate = $letters[$column['key']].$row;
                if ($column['identity']) {
                    $this->text($sheet, $coordinate, PayrollColumns::identityValue($r, $column['key']));

                    continue;
                }
                $def = $byKey[$column['key']];
                $cell = $r['cells'][$column['key']];
                if ($def['kind'] === 'formula') {
                    $ast = $calculator->ast($def['key']);
                    $refs = PayrollFormula::references($ast);
                    $formula = ExcelFormula::build(
                        $ast, $def['value_type'],
                        fn (string $k) => $settingCells[$k] ?? $letters[$k].$row,
                        array_values(array_filter($refs, fn ($k) => ! isset($settingCells[$k]) && $keyIsStrict($k))),
                    );
                    $sheet->setCellValue($coordinate, $formula);

                    continue;
                }
                if ($cell['v'] === null) {
                    continue; // blank stays an empty cell; an entered zero is a real 0
                }
                if ($def['value_type'] === 'text') {
                    $this->text($sheet, $coordinate, $cell['v']);
                } else {
                    $sheet->setCellValueExplicit($coordinate, (float) $cell['v'], DataType::TYPE_NUMERIC);
                }
            }
            $row++;
        }
        $last = $row - 1;
        $totalRow = $row;

        $this->text($sheet, "A{$totalRow}", 'الإجمالي ('.$snapshot['totals']['employees'].' موظفًا)');
        $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
        foreach ($exported as $column) {
            if ($column['aggregation'] !== 'sum') {
                continue;
            }
            $letter = $letters[$column['key']];
            // SUMIF over "any number" skips blank, "" and error cells, as the application's totals leave out unavailable cells
            // (results are bounded below 1e15 by the formula engine, so the criterion matches every real number).
            $sheet->setCellValue("{$letter}{$totalRow}", $last >= self::FIRST_DATA_ROW ? "=SUMIF({$letter}".self::FIRST_DATA_ROW.":{$letter}{$last},\">-1E+16\")" : 0);
        }
        $footer = $sheet->getStyle("A{$totalRow}:{$visibleLast}{$totalRow}");
        $footer->getFont()->setBold(true);
        $footer->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F1DF');
        $note = PayrollColumns::exclusionNote($exported, $snapshot);
        if ($note !== null) {
            $this->text($sheet, 'A'.($totalRow + 1), $note);
            $sheet->mergeCells('A'.($totalRow + 1).":{$visibleLast}".($totalRow + 1));
        }

        // Number formats, alignment, widths, hidden helpers.
        foreach ($columns as $column) {
            $letter = $letters[$column['key']];
            $range = "{$letter}".self::FIRST_DATA_ROW.":{$letter}{$totalRow}";
            if ($column['identity'] || $column['type'] === 'text') {
                $sheet->getStyle("{$letter}".self::FIRST_DATA_ROW.":{$letter}{$last}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("{$letter}".self::FIRST_DATA_ROW.":{$letter}{$last}")->getNumberFormat()->setFormatCode('@');
            } else {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode(match ($column['type']) {
                    'amount' => self::MONEY_FORMAT, 'percent' => self::PERCENT_FORMAT, default => self::NUMBER_FORMAT,
                });
            }
            $sheet->getColumnDimension($letter)->setWidth($column['identity'] ? match ($column['key']) {
                'employee_number' => 16, 'full_name' => 30, 'job_title' => 28, 'body' => 22, 'workplace' => 18, default => 22,
            } : ($column['type'] === 'text' ? 26 : 20));
            if ($column['hidden'] ?? false) {
                $sheet->getColumnDimension($letter)->setVisible(false);
            }
        }
        $sheet->getStyle('A'.self::HEADER_ROW.":{$visibleLast}{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9CBA8');
        $sheet->freezePane('C'.self::FIRST_DATA_ROW);

        $setup = $sheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(self::GROUP_ROW, self::HEADER_ROW);
        $sheet->getHeaderFooter()->setOddFooter('&C&"Arial"&P / &N');

        $path = tempnam(sys_get_temp_dir(), 'payroll_xlsx_');
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas(true); // cached results travel with the file; Excel still recalculates on open
        $writer->setForceFullCalc(true);
        $writer->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /** Literal text cell; a leading = + - @ (or control char) additionally gets the quote prefix. */
    private function text(Worksheet $sheet, string $coordinate, string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r\n") !== false) {
            $sheet->getStyle($coordinate)->setQuotePrefix(true);
        }
    }
}
