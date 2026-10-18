<?php

namespace Tests\Feature;

use App\Services\Payroll\Formula\FormulaScope;
use App\Services\Payroll\Formula\PayrollFormula;
use App\Services\Payroll\PayrollCalculator;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Shared formula vectors. The same file drives the PHP engine here and the browser engine in
 * frontend/tests/ownerPayrollFormula.test.mjs (which evaluates the AST stored in the fixture), so the two implementations
 * cannot drift. Regenerate with `UPDATE_PAYROLL_VECTORS=1 php artisan test --filter=FormulaVectors` after an intentional change.
 */
final class OwnerPayrollFormulaVectorsTest extends TestCase
{
    private const FILE = __DIR__.'/../Fixtures/payroll_formula_vectors.json';

    private const COLUMNS = [
        ['key' => 'a', 'label' => 'أ', 'value_type' => 'amount', 'blank_as_zero' => false],
        ['key' => 'b', 'label' => 'ب', 'value_type' => 'amount', 'blank_as_zero' => false],
        ['key' => 'z', 'label' => 'اختياري', 'value_type' => 'amount', 'blank_as_zero' => true],
        ['key' => 'n', 'label' => 'عدد', 'value_type' => 'number', 'blank_as_zero' => true],
        ['key' => 't', 'label' => 'نص', 'value_type' => 'text', 'blank_as_zero' => true],
    ];

    private const SETTINGS = [
        ['key' => 'rate', 'label' => 'النسبة', 'value_type' => 'percent', 'value' => '0.07'],
        ['key' => 'floor', 'label' => 'الحد', 'value_type' => 'amount', 'value' => '12560'],
    ];

    /** [name, formula, output type, inputs, expected state, expected value] */
    private const CASES = [
        ['precedence', '[أ] + [ب] * 2', 'amount', ['a' => '10', 'b' => '5'], 'ok', '20.00'],
        ['parentheses', '([أ] + [ب]) * 2', 'amount', ['a' => '10', 'b' => '5'], 'ok', '30.00'],
        ['left associative subtraction', '[أ] - [ب] - 1', 'amount', ['a' => '10', 'b' => '5'], 'ok', '4.00'],
        ['unary minus', '-[أ] + 3', 'amount', ['a' => '10', 'b' => '0'], 'ok', '-7.00'],
        ['percent literal', '[أ] * 15%', 'amount', ['a' => '200', 'b' => '0'], 'ok', '30.00'],
        ['setting reference', '[أ] * [النسبة]', 'amount', ['a' => '96600', 'b' => '0'], 'ok', '6762.00'],
        ['setting amount', '[أ] - [الحد]', 'amount', ['a' => '10000', 'b' => '0'], 'ok', '-2560.00'],
        ['half up positive', '[أ] * [النسبة]', 'amount', ['a' => '12345.67', 'b' => '0'], 'ok', '864.20'],
        ['half up at exactly .005', '[أ] / 200', 'amount', ['a' => '1', 'b' => '0'], 'ok', '0.01'],
        ['half away from zero negative', '[أ] / 200', 'amount', ['a' => '-1', 'b' => '0'], 'ok', '-0.01'],
        ['division keeps precision', '[أ] / 3 * 3', 'amount', ['a' => '100', 'b' => '0'], 'ok', '100.00'],
        ['division by zero', '[أ] / [ب]', 'amount', ['a' => '5', 'b' => '0'], 'error', null],
        ['division by blank optional zero', '[أ] / [اختياري]', 'amount', ['a' => '5'], 'error', null],
        ['guarded division', 'IF([ب] = 0, 0, [أ] / [ب])', 'amount', ['a' => '5', 'b' => '0'], 'ok', '0.00'],
        ['guarded division other branch', 'IF([ب] = 0, 0, [أ] / [ب])', 'amount', ['a' => '5', 'b' => '2'], 'ok', '2.50'],
        ['comparison gt', 'IF([أ] > 50000, 1, 2)', 'number', ['a' => '60000', 'b' => '0'], 'ok', '1'],
        ['comparison gte', 'IF([أ] >= 50000, 1, 2)', 'number', ['a' => '50000', 'b' => '0'], 'ok', '1'],
        ['comparison neq', 'IF([أ] <> [ب], 1, 2)', 'number', ['a' => '1', 'b' => '1'], 'ok', '2'],
        ['text comparison', 'IF([نص] = "ممتاز", 100, 0)', 'amount', ['a' => '1', 'b' => '1', 't' => 'ممتاز'], 'ok', '100.00'],
        ['blank text equals empty', 'IF([نص] = "", 1, 2)', 'number', ['a' => '1', 'b' => '1'], 'ok', '1'],
        ['max min', 'MAX([أ], [ب]) - MIN([أ], [ب])', 'amount', ['a' => '3', 'b' => '10'], 'ok', '7.00'],
        ['sum many', 'SUM([أ], [ب], [اختياري], 1.5)', 'amount', ['a' => '3', 'b' => '10'], 'ok', '14.50'],
        ['round zero digits', 'ROUND([أ] / 3, 0)', 'amount', ['a' => '100', 'b' => '0'], 'ok', '33.00'],
        ['round half up', 'ROUND([أ], 0)', 'amount', ['a' => '2.5', 'b' => '0'], 'ok', '3.00'],
        ['round negative half away', 'ROUND([أ], 0)', 'amount', ['a' => '-2.5', 'b' => '0'], 'ok', '-3.00'],
        ['number output six decimals', '[أ] / 7', 'number', ['a' => '1', 'b' => '0'], 'ok', '0.142857'],
        ['percent output', '[أ] / [ب]', 'percent', ['a' => '1', 'b' => '3'], 'ok', '0.333333'],
        ['optional blank counts as zero', '[أ] + [اختياري]', 'amount', ['a' => '5'], 'ok', '5.00'],
        ['required blank is missing', '[أ] + [ب]', 'amount', ['a' => '5'], 'missing', null],
        ['missing is static: dead branch still counts', 'IF([أ] > 0, 1, [ب])', 'amount', ['a' => '5'], 'missing', null],
        ['missing beats nothing: all blank', '[أ] * [ب]', 'amount', [], 'missing', null],
        ['no floor on negative', '[أ] - [ب]', 'amount', ['a' => '1', 'b' => '5'], 'ok', '-4.00'],
        ['large value', '[أ] * 1000', 'amount', ['a' => '999999999.99', 'b' => '0'], 'ok', '999999999990.00'],
        ['overflow is an error', '[أ] * 10000000', 'amount', ['a' => '999999999.99', 'b' => '0'], 'error', null],
        ['arabic digits in formula', '[أ] * ٢ + ١٫٥', 'amount', ['a' => '10', 'b' => '0'], 'ok', '21.50'],
        ['semicolon argument separators', 'MAX([أ]؛ [ب]) + SUM(1; 2)', 'amount', ['a' => '3', 'b' => '10'], 'ok', '13.00'],
        ['nested functions', 'ROUND(MAX([أ] * [النسبة], 100), 1)', 'amount', ['a' => '96600', 'b' => '0'], 'ok', '6762.00'],
        ['comparison result used in arithmetic via IF', '[أ] + IF([أ] > [ب], [ب], 0)', 'amount', ['a' => '9', 'b' => '4'], 'ok', '13.00'],
    ];

    private function evaluate(string $formula, string $type, array $inputs): array
    {
        $entries = array_map(fn ($s) => ['key' => $s['key'], 'label' => $s['label'], 'type' => 'number', 'source' => 'setting'], self::SETTINGS);
        foreach (self::COLUMNS as $c) {
            $entries[] = ['key' => $c['key'], 'label' => $c['label'], 'type' => $c['value_type'] === 'text' ? 'text' : 'number', 'source' => 'column'];
        }
        $entries[] = ['key' => 'out', 'label' => 'ناتج', 'type' => 'number', 'source' => 'column'];
        $ast = PayrollFormula::parse($formula, new FormulaScope($entries));
        $columns = array_map(fn ($c) => $c + ['label' => $c['label'], 'kind' => 'input', 'formula' => null, 'warn_negative' => false], self::COLUMNS);
        $columns[] = ['key' => 'out', 'label' => 'ناتج', 'kind' => 'formula', 'value_type' => $type, 'formula' => PayrollFormula::canonical($ast), 'blank_as_zero' => false, 'warn_negative' => false];
        $stored = array_map(fn ($v) => $v === null ? null : (is_numeric($v) ? BigDecimal::of($v) : $v), $inputs);
        $cell = (new PayrollCalculator($columns, self::SETTINGS))->evaluateRow($stored)['out'];

        return ['ast' => $ast, 'state' => $cell['st'] ?? 'ok', 'value' => $cell['v']];
    }

    public function test_vectors_match_the_engine_and_the_committed_fixture(): void
    {
        $fixture = ['columns' => self::COLUMNS, 'settings' => self::SETTINGS, 'cases' => []];
        foreach (self::CASES as [$name, $formula, $type, $inputs, $state, $value]) {
            $result = $this->evaluate($formula, $type, $inputs);
            $this->assertSame($state, $result['state'], $name);
            $this->assertSame($value, $result['value'], $name);
            $fixture['cases'][] = ['name' => $name, 'formula' => $formula, 'value_type' => $type, 'inputs' => (object) $inputs, 'ast' => $result['ast'], 'state' => $state, 'value' => $value];
        }
        $json = json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        if (getenv('UPDATE_PAYROLL_VECTORS') === '1') {
            file_put_contents(self::FILE, $json);
        }
        $this->assertSame(file_get_contents(self::FILE), $json, 'fixture out of date: run with UPDATE_PAYROLL_VECTORS=1');
    }
}
