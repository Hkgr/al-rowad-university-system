<?php

namespace Tests\Feature;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\RecalculatesWorkbooks;

/**
 * The exported workbook's formulas, recalculated by a real spreadsheet engine (LibreOffice), must produce the same figures as the
 * application for every calculated cell and every total. Only the recalculated values are compared — never formula text or cached results.
 */
final class OwnerPayrollExcelPrecisionTest extends OwnerPayrollTestCase
{
    use RecalculatesWorkbooks;

    private const CFG = self::API.'/payroll/config';

    /** [label, formula, type] — each becomes a custom calculated column. */
    private const CASES = [
        ['حد أدنى', '0.499999 * 1%', 'amount'],
        ['حد أدنى سالب', '-0.499999 * 1%', 'amount'],
        ['تعادل موجب', '0.5 * 1%', 'amount'],
        ['تعادل سالب', '-0.5 * 1%', 'amount'],
        ['فوق التعادل', '0.500001 * 1%', 'amount'],
        ['تحت التعادل سالب', '-0.500001 * 1%', 'amount'],
        ['ضريبة التعويض الأصلية', '4539.5 * 0.15 - 680.93', 'amount'],
        ['عدد في نسبة', '[عدد] * [نسبة]', 'amount'],
        ['عدد في نسبة (رقم)', '[عدد] * [نسبة]', 'number'],
        ['عدد في نسبة (نسبة)', '[عدد] * [نسبة]', 'percent'],
        ['ثلاثة عوامل', '[عدد] * [نسبة] * 3', 'amount'],
        ['قسمة مبلغ', '[مبلغ أ] / [مبلغ ب]', 'amount'],
        ['قسمة رقم', '[مبلغ أ] / [مبلغ ب]', 'number'],
        ['تعبير متداخل', '([مبلغ أ] + [مبلغ ب]) * [نسبة] - [مبلغ ب] / 7', 'amount'],
        ['تقريب صريح ٣', 'ROUND([عدد] * [نسبة], 3)', 'amount'],
        ['تقريب صريح ١', 'ROUND([مبلغ أ] / 3, 1) + 0.04', 'amount'],
        ['قيمة كبيرة', '[مبلغ كبير] * [نسبة]', 'amount'],
        ['فرق كبير', '[مبلغ كبير] * 0.07 - [مبلغ كبير] * 0.0699', 'amount'],
        ['شرط على ناتج', 'IF([عدد] * [نسبة] > 0.005, 1, 2)', 'number'],
        ['أكبر قيمتين', 'MAX([مبلغ أ] * 0.015, [مبلغ ب] / 3)', 'amount'],
        ['مجموع', 'SUM([عدد] * [نسبة], 0.001, [مبلغ أ] * 0.0001)', 'amount'],
        ['نسبة مئوية من مبلغ', '[مبلغ أ] * 1.5%', 'amount'],
        ['قسمة على ثمانية', '[مبلغ أ] / 8', 'amount'],
        ['نصف سنت', '[مبلغ أ] * 0.005', 'amount'],
        ['مضاعف النسبة', '[عدد] * [نسبة] * [نسبة]', 'number'],
    ];

    private const EMPLOYEES = [
        ['E1', ['عدد' => '1234.567891', 'نسبة' => '0.0725', 'مبلغ أ' => '1.00', 'مبلغ ب' => '3.00', 'مبلغ كبير' => '999999999.99']],
        ['E2', ['عدد' => '0.000001', 'نسبة' => '0.0001', 'مبلغ أ' => '0.12', 'مبلغ ب' => '0.07', 'مبلغ كبير' => '123456789.12']],
        ['E3', ['عدد' => '999999.999999', 'نسبة' => '1', 'مبلغ أ' => '-2.50', 'مبلغ ب' => '-0.30', 'مبلغ كبير' => '1.01']],
        ['E4', ['عدد' => '0.5', 'نسبة' => '0.01', 'مبلغ أ' => '1.00', 'مبلغ ب' => '8.00', 'مبلغ كبير' => '0.01']],
    ];

    private function addColumn(array $fields): string
    {
        return $this->postJson(self::CFG.'/columns', $fields + ['config_revision' => $this->configRevision()])->assertCreated()->json('data.key');
    }

    private function setUpSheet(): void
    {
        $this->actingAsUser(self::OWNER);
        $keys = [];
        $keys['عدد'] = $this->addColumn(['label' => 'عدد', 'kind' => 'input', 'value_type' => 'number', 'group' => 'salary']);
        $keys['نسبة'] = $this->addColumn(['label' => 'نسبة', 'kind' => 'input', 'value_type' => 'percent', 'group' => 'salary']);
        foreach (['مبلغ أ', 'مبلغ ب'] as $label) {
            $keys[$label] = $this->addColumn(['label' => $label, 'kind' => 'input', 'value_type' => 'amount', 'group' => 'salary', 'allow_negative' => true]);
        }
        $keys['مبلغ كبير'] = $this->addColumn(['label' => 'مبلغ كبير', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'salary']);
        foreach (self::CASES as [$label, $formula, $type]) {
            $this->addColumn(['label' => $label, 'kind' => 'formula', 'value_type' => $type, 'formula' => $formula, 'group' => 'net', 'aggregation' => $type === 'percent' ? 'none' : 'sum']);
        }
        foreach (self::EMPLOYEES as [$number, $values]) {
            $row = $this->employee(['employee_number' => $number]);
            $mapped = ['fixed_salary' => '1000'];
            foreach ($values as $label => $value) {
                $mapped[$keys[$label]] = $value;
            }
            $this->values([$this->change($row, $mapped)])->assertOk();
        }
    }

    /** @return array{header: list<string>, rows: list<list<string>>, total: list<string>} */
    private function recalculatedExport(): array
    {
        $response = $this->getJson(self::API.'/payroll/export/xlsx')->assertOk();
        $csv = $this->recalculate($response->baseResponse->getFile()->getPathname());
        $header = $csv[6];
        $rows = [];
        $total = [];
        foreach (array_slice($csv, 7) as $line) {
            if (str_starts_with($line[0] ?? '', 'الإجمالي')) {
                $total = $line;
                break;
            }
            $rows[] = $line;
        }

        return ['header' => $header, 'rows' => $rows, 'total' => $total];
    }

    /**
     * A double cannot hold every decimal: 72512346.69 is stored as the nearest double (…6899999976). Excel's value is therefore read at 6
     * decimals, far finer than the 2 the amounts have, so a one-cent difference can never hide.
     */
    private function same(string $expected, string $actual): bool
    {
        return $actual !== '' && BigDecimal::of($expected)->toScale(6, RoundingMode::HALF_UP)->isEqualTo(BigDecimal::of($actual)->toScale(6, RoundingMode::HALF_UP));
    }

    private function sameNumber(string $expected, string $actual, string $message): void
    {
        $this->assertTrue($this->same($expected, $actual), "{$message}: server {$expected} vs Excel {$actual}");
    }

    public function test_every_calculated_cell_and_total_of_the_exported_workbook_matches_the_application_after_recalculation(): void
    {
        if (! $this->libreOfficeAvailable()) {
            $this->markTestSkipped('LibreOffice (soffice) is required to recalculate the exported workbook.');
        }
        $this->setUpSheet();
        $server = $this->getJson(self::API.'/payroll/sheet')->assertOk();
        $config = collect($server->json('config.columns'));
        $export = $this->recalculatedExport();
        $mismatches = [];
        foreach ($config->where('kind', 'formula') as $column) {
            $index = collect($export['header'])->search(fn ($h) => str_starts_with($h, $column['label']));
            $this->assertNotFalse($index, "column {$column['label']} exported");
            foreach ($server->json('data') as $i => $row) {
                $expected = $row['cells'][$column['key']];
                $actual = $export['rows'][$i][$index] ?? '';
                if ($expected['v'] === null) {
                    $this->assertSame('', $actual, "{$column['label']} {$row['employee_number']} unavailable");

                    continue;
                }
                if (! $this->same($expected['v'], $actual)) {
                    $mismatches[] = "{$column['label']} [{$column['value_type']}] {$row['employee_number']}: app {$expected['v']} vs Excel ".($actual === '' ? '(blank)' : $actual);
                }
            }
            $totalExpected = $server->json("meta.totals.columns.{$column['key']}.sum");
            if ($totalExpected !== null) {
                try {
                    $this->sameNumber($totalExpected, $export['total'][$index], "total {$column['label']}");
                } catch (\Throwable $e) {
                    $mismatches[] = $e->getMessage();
                }
            }
        }
        $this->assertSame([], $mismatches, "Excel must equal the application:\n".implode("\n", $mismatches));
    }

    public function test_the_reference_case_and_the_documented_tie_cases_in_the_exported_workbook(): void
    {
        if (! $this->libreOfficeAvailable()) {
            $this->markTestSkipped('LibreOffice (soffice) is required to recalculate the exported workbook.');
        }
        $this->actingAsUser(self::OWNER);
        $row = $this->employee(['employee_number' => '0001']);
        $this->values([$this->change($row, ['fixed_salary' => '96600', 'compensation' => '55200', 'other_deductions' => '0', 'salary_adjustment' => '0', 'compensation_adjustment' => '0'])])->assertOk();
        $tie = $this->addColumn(['label' => 'تعادل التعويض', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '4539.5 * 0.15 - 680.93', 'group' => 'net']);
        $below = $this->addColumn(['label' => 'تحت نصف سنت', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '0.499999 * 1%', 'group' => 'net']);
        $export = $this->recalculatedExport();
        $cell = fn (string $label) => $export['rows'][0][collect($export['header'])->search(fn ($h) => str_starts_with($h, $label))];
        $this->sameNumber('125166.30', $cell('إجمالي الصافي المستحق'), 'reference net payable');
        $this->sameNumber('-0.01', $cell('تعادل التعويض'), 'compensation-tax tie case');
        $this->sameNumber('0.00', $cell('تحت نصف سنت'), '0.00499999');
        $this->assertSame('-0.01', $this->sheetRow('0001')['cells'][$tie]['v']);
        $this->assertSame('0.00', $this->sheetRow('0001')['cells'][$below]['v']);
        $this->assertSame(1, DB::table('payroll_employees')->count());
    }

    public function test_exact_ties_and_near_ties_across_many_values_match_after_recalculation(): void
    {
        if (! $this->libreOfficeAvailable()) {
            $this->markTestSkipped('LibreOffice (soffice) is required to recalculate the exported workbook.');
        }
        $this->actingAsUser(self::OWNER);
        $a = $this->addColumn(['label' => 'مبلغ أ', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'salary', 'allow_negative' => true]);
        $this->addColumn(['label' => 'نسبة', 'kind' => 'input', 'value_type' => 'percent', 'group' => 'salary']);
        $formulas = ['[مبلغ أ] * 0.005', '[مبلغ أ] / 8', '[مبلغ أ] * [نسبة]', '[مبلغ أ] * 1.5%', '[مبلغ أ] + 0.004999', '[مبلغ أ] + 0.005001', '[مبلغ أ] - 0.005', '[مبلغ أ] * 0.015 - [مبلغ أ] * 0.01', '([مبلغ أ] + 0.001) / 2'];
        foreach ($formulas as $i => $formula) {
            $this->addColumn(['label' => "اختبار {$i}", 'kind' => 'formula', 'value_type' => 'amount', 'formula' => $formula, 'group' => 'net', 'aggregation' => 'sum']);
        }
        $amounts = ['1', '3', '5', '7', '0.5', '0.01', '0.03', '-1', '-3', '-0.01', '0.3', '2.5', '99999.99', '123456.78', '-654321.09', '12.34', '0.07', '4539.50', '680.93', '1000000.01', '33.33', '66.67', '0.11', '0.99'];
        foreach ($amounts as $i => $amount) {
            $row = $this->employee(['employee_number' => 'T'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
            $this->values([$this->change($row, ['fixed_salary' => '1', $a => $amount, collect(DB::table('payroll_columns')->where('label', 'نسبة')->pluck('key'))->first() => '0.005'])])->assertOk();
        }
        $server = $this->getJson(self::API.'/payroll/sheet')->assertOk();
        $export = $this->recalculatedExport();
        $mismatches = [];
        foreach (collect($server->json('config.columns'))->filter(fn ($c) => str_starts_with($c['label'], 'اختبار')) as $column) {
            $index = collect($export['header'])->search(fn ($h) => str_starts_with($h, $column['label']));
            foreach ($server->json('data') as $i => $row) {
                $expected = $row['cells'][$column['key']]['v'];
                if (! $this->same($expected, $export['rows'][$i][$index] ?? '')) {
                    $mismatches[] = "{$column['label']} a={$row['cells'][$a]['v']}: app {$expected} vs Excel ".($export['rows'][$i][$index] ?? '');
                }
            }
            $this->sameNumber($server->json("meta.totals.columns.{$column['key']}.sum"), $export['total'][$index], "total {$column['label']}");
        }
        $this->assertSame([], $mismatches, implode("\n", $mismatches));
    }

    public function test_cells_beyond_excels_fifteen_digit_precision_are_flagged_and_everything_else_is_not(): void
    {
        if (! $this->libreOfficeAvailable()) {
            $this->markTestSkipped('LibreOffice (soffice) is required to recalculate the exported workbook.');
        }
        $this->actingAsUser(self::OWNER);
        $g = $this->addColumn(['label' => 'مبلغ كبير', 'kind' => 'input', 'value_type' => 'amount', 'group' => 'salary']);
        $n = $this->addColumn(['label' => 'عدد', 'kind' => 'input', 'value_type' => 'number', 'group' => 'salary']);
        $this->addColumn(['label' => 'ضرب هائل', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[مبلغ كبير] * [عدد]', 'group' => 'net']);
        $this->addColumn(['label' => 'ضرب عادي', 'kind' => 'formula', 'value_type' => 'amount', 'formula' => '[مبلغ كبير] * 0.07', 'group' => 'net']);
        $row = $this->employee(['employee_number' => 'P1']);
        // 999,999,999.99 x 999,999.999999 ≈ 10^15: the rounded amount has 17 significant digits — beyond a double.
        $this->values([$this->change($row, ['fixed_salary' => '1', $g => '999999999.99', $n => '999999.999999'])])->assertOk();
        $normal = $this->employee(['employee_number' => 'P2']);
        $this->values([$this->change($normal, ['fixed_salary' => '1', $g => '12345.67', $n => '2'])])->assertOk();
        $file = $this->getJson(self::API.'/payroll/export/xlsx')->assertOk()->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($file)->getActiveSheet();
        $cell = function (string $label, int $row) use ($sheet) {
            foreach ($sheet->getRowIterator(7, 7) as $head) {
                foreach ($head->getCellIterator() as $c) {
                    if (str_starts_with((string) $c->getValue(), $label)) {
                        return $sheet->getCell($c->getColumn().$row);
                    }
                }
            }
            $this->fail($label);
        };
        $flagged = fn ($c) => $c->getStyle()->getFill()->getStartColor()->getRGB() === 'FFE0B2';
        $this->assertTrue($flagged($cell('ضرب هائل', 8)), 'a result beyond 15 significant digits is flagged');
        $this->assertFalse($flagged($cell('ضرب هائل', 9)), 'an ordinary row of the same column is not');
        $this->assertFalse($flagged($cell('ضرب عادي', 8)), 'a large but representable result is not flagged');
        $this->assertTrue($flagged($cell('ضرب هائل', 10)), 'the total of a column with a flagged cell is flagged');
        $this->assertStringContainsString('تنبيه دقة Excel', (string) $sheet->getCell('A11')->getValue().$sheet->getCell('A12')->getValue());
    }
}
