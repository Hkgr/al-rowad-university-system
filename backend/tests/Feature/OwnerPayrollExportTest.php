<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel and PDF exports: configured columns, real formulas, Syrian-pound formats, one consistent snapshot.
 */
final class OwnerPayrollExportTest extends OwnerPayrollTestCase
{
    private const CFG = self::API.'/payroll/config';

    private function loadXlsx($response): Worksheet
    {
        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'payroll_test_').'.xlsx';
        copy($file, $copy);
        $this->assertSame('PK', substr(file_get_contents($copy), 0, 2), 'a real zip-based .xlsx');

        return IOFactory::load($copy)->getActiveSheet();
    }

    private function column(array $fields)
    {
        return $this->postJson(self::CFG.'/columns', $fields + ['config_revision' => $this->configRevision()]);
    }

    /** Column letter by heading text in the heading row. */
    private function letter(Worksheet $sheet, string $heading): string
    {
        foreach ($sheet->getRowIterator(7, 7) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if (str_starts_with((string) $cell->getValue(), $heading)) {
                    return $cell->getColumn();
                }
            }
        }
        $this->fail("heading {$heading} not found");
    }

    private function seedPayroll(): array
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body('=HYPERLINK("http://x","هيئة")');
        $a = $this->employee(['employee_number' => '00007', 'full_name' => '=1+1', 'job_title' => '+SUM(A1)', 'academic_level' => '@cmd'], $body);
        $b = $this->employee(['employee_number' => '00123', 'full_name' => 'ثاني'], $body);
        $this->values([
            $this->change($a, ['fixed_salary' => '96600', 'compensation' => '55200', 'other_deductions' => '0']),
            $this->change($b, ['other_deductions' => '5']),
        ])->assertOk();

        return [$a, $b];
    }

    public function test_excel_has_rtl_syp_formats_text_identity_real_formulas_and_matching_values(): void
    {
        $this->seedPayroll();
        $response = $this->getJson(self::API.'/payroll/export/xlsx');
        $response->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = $this->loadXlsx($response);

        $this->assertTrue($sheet->getRightToLeft());
        $this->assertStringContainsString('ل.س', $sheet->getCell('A3')->getValue());
        $this->assertStringContainsString('كل الصفوف دون مرشحات', $sheet->getCell('A2')->getValue());
        $this->assertSame('رقم الموظف', $sheet->getCell('A7')->getValue());
        $this->assertSame('الراتب', $sheet->getCell($this->letter($sheet, 'الأجر المقطوع').'6')->getValue(), 'group band above the headings');

        // Identity as literal text; user text that looks like a formula is never evaluated.
        $this->assertSame('00007', $sheet->getCell('A8')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A8')->getDataType());
        foreach (['B8' => '=1+1', 'C8' => '+SUM(A1)', 'F8' => '@cmd', 'D8' => '=HYPERLINK("http://x","هيئة")'] as $cell => $text) {
            $this->assertSame($text, $sheet->getCell($cell)->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), $cell);
        }

        // Settings block with labels; formulas point at those cells.
        $this->assertSame('نسبة التأمينات', $sheet->getCell('A4')->getValue());
        $this->assertSame(0.07, (float) $sheet->getCell('A5')->getValue());
        $this->assertSame('نسبة ضريبة الدخل', $sheet->getCell('B4')->getValue());
        $this->assertSame(0.15, (float) $sheet->getCell('B5')->getValue());
        $this->assertSame(12560.0, (float) $sheet->getCell('C5')->getValue());
        $this->assertStringContainsString('ل.س', $sheet->getStyle('C5')->getNumberFormat()->getFormatCode());

        $fixed = $this->letter($sheet, 'الأجر المقطوع');
        $insurance = $this->letter($sheet, 'التأمينات الاجتماعية');
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell("{$fixed}8")->getDataType());
        $this->assertSame(96600.0, (float) $sheet->getCell("{$fixed}8")->getValue());
        $this->assertNull($sheet->getCell("{$fixed}9")->getValue(), 'blank fixed salary stays an empty cell');
        $this->assertStringContainsString('ل.س', $sheet->getStyle("{$fixed}8")->getNumberFormat()->getFormatCode());
        $formula = (string) $sheet->getCell("{$insurance}8")->getValue();
        $this->assertStringStartsWith('=', $formula);
        $this->assertStringContainsString('$A$5', $formula);
        $this->assertStringContainsString("{$fixed}8", $formula);
        $this->assertStringContainsString('ROUND(', $formula);

        // Every calculated cell recalculates to the server's figure (blank/unavailable => empty text).
        $server = $this->getJson(self::API.'/payroll/sheet')->json('data');
        $columns = $this->getJson(self::CFG)->json('data.columns');
        foreach ($server as $i => $row) {
            foreach ($columns as $column) {
                if ($column['kind'] !== 'formula') {
                    continue;
                }
                $cell = $sheet->getCell($this->letter($sheet, $column['label']).(8 + $i));
                $expected = $row['cells'][$column['key']]['v'];
                $actual = $cell->getCalculatedValue();
                if ($expected === null) {
                    $this->assertSame('', (string) $actual, "{$column['key']} row {$i}");
                } else {
                    $this->assertEqualsWithDelta((float) $expected, (float) $actual, 0.0001, "{$column['key']} row {$i}");
                }
            }
        }
        $this->assertSame(125166.30, round((float) $sheet->getCell($this->letter($sheet, 'إجمالي الصافي المستحق').'8')->getCalculatedValue(), 2));

        // Totals row: formulas that equal the grid totals and skip unavailable cells.
        $totals = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals.columns');
        $totalRow = 10;
        $net = $this->letter($sheet, 'إجمالي الصافي المستحق');
        $this->assertStringContainsString('الإجمالي (2 موظفًا)', $sheet->getCell("A{$totalRow}")->getValue());
        $this->assertStringStartsWith('=SUMIF(', (string) $sheet->getCell("{$net}{$totalRow}")->getValue());
        $this->assertEqualsWithDelta((float) $totals['total_net_payable']['sum'], (float) $sheet->getCell("{$net}{$totalRow}")->getCalculatedValue(), 0.0001);
        $this->assertStringContainsString('تستثني المجاميع', (string) $sheet->getCell('A11')->getValue());
        $this->assertStringNotContainsString('$"', $sheet->getStyle("{$net}8")->getNumberFormat()->getFormatCode(), 'no USD format anywhere');
    }

    public function test_excel_follows_configured_export_columns_and_includes_hidden_helpers_for_formulas(): void
    {
        $this->seedPayroll();
        // Hide insurance from the export: net salary's formula still needs it, so it travels as a hidden helper.
        $this->patchJson(self::CFG.'/columns/insurance', ['visible_export' => false, 'config_revision' => $this->configRevision()])->assertOk();
        $custom = $this->column(['label' => 'بدل مخصص', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'salary', 'aggregation' => 'sum'])->assertCreated()->json('data.key');
        $this->column(['label' => 'سري', 'kind' => 'input', 'value_type' => 'text', 'visible_export' => false])->assertCreated();
        $double = $this->column(['label' => 'ضعف البدل', 'kind' => 'formula', 'value_type' => 'amount', 'group' => 'salary', 'aggregation' => 'sum', 'formula' => '[بدل مخصص] * 2 + [التأمينات الاجتماعية]'])->assertCreated()->json('data.key');
        $this->values([$this->change($this->sheetRow('00007'), [$custom => '100.25'])])->assertOk();

        $sheet = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx'));
        $headings = [];
        foreach ($sheet->getRowIterator(7, 7) as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $headings[$cell->getColumn()] = (string) $cell->getValue();
            }
        }
        $this->assertContains('بدل مخصص (ل.س)', $headings);
        $this->assertNotContains('سري', $headings);
        $helper = array_search('التأمينات الاجتماعية (عمود مساعد)', $headings, true);
        $this->assertNotFalse($helper, 'a formula dependency that is not exported is present as a helper');
        $this->assertFalse($sheet->getColumnDimension($helper)->getVisible(), 'and hidden');
        $this->assertNotContains('التأمينات الاجتماعية (ل.س)', $headings);

        $col = array_search('ضعف البدل (ل.س)', $headings, true);
        $this->assertEqualsWithDelta(100.25 * 2 + 6762, (float) $sheet->getCell("{$col}8")->getCalculatedValue(), 0.0001);
        $this->assertSame('', (string) $sheet->getCell("{$col}9")->getCalculatedValue(), 'unavailable (blank fixed salary) stays blank');
        $this->assertSame((float) $this->sheetRow('00007')['cells'][$double]['v'], (float) $sheet->getCell("{$col}8")->getCalculatedValue());
    }

    public function test_exports_use_the_current_filters_order_and_label_the_scope(): void
    {
        $this->actingAsUser(self::OWNER);
        $one = $this->body('هيئة أ');
        $two = $this->body('هيئة ب');
        foreach ([['010', 'جيم', $one, 30000], ['002', 'ألف', $two, 50000], ['003', 'باء', $two, 40000]] as [$n, $name, $body, $salary]) {
            $row = $this->employee(['employee_number' => $n, 'full_name' => $name], $body);
            $this->values([$this->change($row, ['fixed_salary' => (string) $salary])])->assertOk();
        }
        $sheet = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx?body_id='.$two['id'].'&sort=total_net_payable&direction=desc'));
        $this->assertStringContainsString('الصفوف المطابقة للمرشحات فقط (2 موظفًا)', $sheet->getCell('A2')->getValue());
        $this->assertStringContainsString('الهيئة: هيئة ب', $sheet->getCell('A2')->getValue());
        $this->assertStringContainsString('إجمالي الصافي المستحق (تنازلي)', $sheet->getCell('A3')->getValue());
        $this->assertSame(['002', '003'], [$sheet->getCell('A8')->getValue(), $sheet->getCell('A9')->getValue()]);
        $this->getJson(self::API.'/payroll/export/xlsx?sort=nope')->assertUnprocessable();

        $empty = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx?search=zzzz'));
        $this->assertSame('الإجمالي (0 موظفًا)', $empty->getCell('A8')->getValue());
    }

    public function test_pdf_is_a_landscape_a3_document_with_embedded_arabic_font_syp_figures_and_repeated_headings(): void
    {
        $this->actingAsUser(self::OWNER);
        $body = $this->body('هيئة التدريس');
        $changes = [];
        for ($i = 1; $i <= 45; $i++) {
            $row = $this->employee(['employee_number' => sprintf('%04d', $i), 'full_name' => "موظف تجريبي رقم {$i} بن عبد الله الطويل الاسم جدًا لاختبار التفاف الأسطر", 'job_title' => 'صفة وظيفية طويلة للاختبار'], $body);
            $changes[] = $this->change($row, ['fixed_salary' => (string) (96600 + $i), 'compensation' => '55200']);
        }
        $this->values($changes)->assertOk();

        $response = $this->get(self::API.'/payroll/export/pdf');
        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertMatchesRegularExpression('#/MediaBox\s*\[0(\.0+)?\s+0(\.0+)?\s+(1190|1191)\.\d+\s+(841|842)\.\d+\]#', $pdf, 'A3 landscape');
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->assertStringContainsString('Cairo', $pdf);
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
        $this->assertGreaterThan(2, $pages, 'a wide report spans several pages (column groups and rows)');

        if (trim((string) shell_exec('command -v pdftotext')) !== '') {
            $file = tempnam(sys_get_temp_dir(), 'payroll_pdf_').'.pdf';
            file_put_contents($file, $pdf);
            $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' - 2>/dev/null');
            $this->assertStringContainsString('96,601.00', $text);
            $this->assertStringNotContainsString('$', $text, 'no USD marker');
        }
    }

    public function test_pdf_only_prints_columns_marked_for_export(): void
    {
        $this->seedPayroll();
        $this->column(['label' => 'عمود مخفي', 'kind' => 'input', 'value_type' => 'amount', 'visible_export' => false])->assertCreated();
        $pdf = $this->get(self::API.'/payroll/export/pdf')->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        if (trim((string) shell_exec('command -v pdftotext')) !== '') {
            $file = tempnam(sys_get_temp_dir(), 'payroll_pdf_').'.pdf';
            file_put_contents($file, $pdf);
            $text = (string) shell_exec('pdftotext '.escapeshellarg($file).' - 2>/dev/null');
            $this->assertStringNotContainsString('مخفي', $text);
            $this->assertStringContainsString('125,166.30', $text);
        }
    }

    public function test_export_comes_from_one_snapshot_so_grid_totals_and_files_agree(): void
    {
        $this->seedPayroll();
        $grid = $this->getJson(self::API.'/payroll/sheet')->json('meta.totals.columns.total_net_payable.sum');
        $home = $this->getJson(self::API.'/home')->json('data.totals.net_payable');
        $sheet = $this->loadXlsx($this->getJson(self::API.'/payroll/export/xlsx'));
        $net = $this->letter($sheet, 'إجمالي الصافي المستحق');
        $this->assertSame($grid, $home);
        $this->assertEqualsWithDelta((float) $grid, (float) $sheet->getCell("{$net}10")->getCalculatedValue(), 0.0001);
        $this->assertSame(0, DB::table('payroll_entry_values')->whereNull('value_scaled')->whereNull('value_text')->count());
    }
}
